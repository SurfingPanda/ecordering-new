<?php

namespace Tests\Feature;

use App\Jobs\SendOrderToEcpos;
use App\Models\Item;
use App\Models\Order;
use App\Services\EcposOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class EcposQueueTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function ecpos($response): void
    {
        config(['ecpos.api_key' => 'test-key', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders', 'ecpos.send_orders' => true, 'queue.default' => 'database']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => $response]);
    }

    private function linkedStoreWithItems(): array
    {
        $store = $this->makeStore();
        $store->forceFill(['ecpos_store_id' => 'BW0018'])->save();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $items['bw']->forceFill(['synced_at' => now()])->save(); // BW001 comes from ECPOS

        return [$store, $user, $items];
    }

    /** a posted order for the store, in the given send state */
    private function postedOrder(string $state = 'queued', ?array $setup = null): array
    {
        [$store, $user, $items] = $setup ?? $this->linkedStoreWithItems();
        $order = $this->seedOrder($store, $user, 'posted', now()->subDays(random_int(1, 300))->toDateString(), [[$items['bw'], 3]]);
        $order->forceFill(['ecpos_state' => $state])->save();

        return [$user, $order, [$store, $user, $items]];
    }

    private function runJob(Order $order): void
    {
        (new SendOrderToEcpos($order->id))->handle(app(EcposOrders::class));
    }

    private function postsToEcpos(): int
    {
        return Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST' && str_contains($p[0]->url(), 'ecpos.test'))->count();
    }

    public function test_posting_is_instant_and_only_queues_the_send(): void
    {
        $this->ecpos(Http::response(['journalid' => 1]));
        Queue::fake();
        [$store, $user, $items] = $this->linkedStoreWithItems();
        $order = $this->seedOrder($store, $user, 'pending', now()->toDateString(), [[$items['bw'], 2]]);

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['posted', 'queued'], [$order->status, $order->ecpos_state]);
        Queue::assertPushed(SendOrderToEcpos::class, fn ($j) => $j->orderId === $order->id);
        Http::assertNothingSent(); // ECPOS isn't touched while the store is waiting
    }

    public function test_the_job_sends_the_order_and_records_the_journal(): void
    {
        $this->ecpos(Http::response(['journalid' => 47000000367]));
        [, $order] = $this->postedOrder();

        $this->runJob($order);

        $order->refresh();
        $this->assertSame(['sent', '47000000367', 1, null], [$order->ecpos_state, $order->ecpos_journal_id, $order->ecpos_attempts, $order->ecpos_error]);
        $this->assertSame('TR47000000367', $order->number());
        $this->assertSame(1, $order->events()->where('event', 'sent')->count());
    }

    public function test_it_never_sends_the_same_order_twice(): void
    {
        $this->ecpos(Http::response(['journalid' => 5]));
        [, $order] = $this->postedOrder();

        $this->runJob($order);
        $this->runJob($order); // a duplicate job, or a restarted worker
        $order->forceFill(['ecpos_state' => 'sending'])->save();
        $this->runJob($order); // another worker already has it

        $this->assertSame(1, $this->postsToEcpos());
    }

    public function test_when_ecpos_cannot_be_reached_the_send_is_retried_later_and_finally_gives_up(): void
    {
        $this->ecpos(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ecpos.test port 443'));
        Queue::fake();
        [, $order] = $this->postedOrder();

        $this->runJob($order);

        $order->refresh();
        $this->assertSame(['queued', 1], [$order->ecpos_state, $order->ecpos_attempts]); // not failed yet: it will try again
        $this->assertStringContainsString('Could not reach ECPOS', $order->ecpos_error);
        $this->assertNotNull($order->ecpos_next_at);
        Queue::assertPushed(SendOrderToEcpos::class, fn ($j) => $j->orderId === $order->id && $j->delay === 30);

        // later attempts wait longer each time; the 5th failure is final
        $order->forceFill(['ecpos_state' => 'queued', 'ecpos_attempts' => 3])->save();
        $this->runJob($order);
        Queue::assertPushed(SendOrderToEcpos::class, fn ($j) => $j->delay === 900);

        $order->refresh()->forceFill(['ecpos_state' => 'queued', 'ecpos_attempts' => 4])->save();
        $this->runJob($order);
        $order->refresh();
        $this->assertSame(['failed', 5, false], [$order->ecpos_state, $order->ecpos_attempts, $order->ecpos_uncertain]);
        $this->assertSame(1, $order->events()->where('event', 'send_failed')->count());
    }

    public function test_a_busy_ecpos_is_retried_but_a_timeout_or_server_error_is_not(): void
    {
        Queue::fake();
        $setup = $this->linkedStoreWithItems();

        $this->ecpos(Http::response('busy', 503));
        [, $busy] = $this->postedOrder('queued', $setup);
        $this->runJob($busy);
        $this->assertSame('queued', $busy->fresh()->ecpos_state);
        Queue::assertPushed(SendOrderToEcpos::class, 1);

        // a timeout may have created the order, so sending again could duplicate it: stop and let a person check
        $this->ecpos(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'));
        [, $timeout] = $this->postedOrder('queued', $setup);
        $this->runJob($timeout);
        $timeout->refresh();
        $this->assertSame(['failed', true], [$timeout->ecpos_state, $timeout->ecpos_uncertain]);
        $this->assertStringContainsString('may or may not have reached', $timeout->ecpos_error);

        $this->ecpos(Http::response('oops', 500));
        [, $server] = $this->postedOrder('queued', $setup);
        $this->runJob($server);
        $this->assertSame(['failed', true], [$server->fresh()->ecpos_state, $server->fresh()->ecpos_uncertain]);

        Queue::assertPushed(SendOrderToEcpos::class, 1); // still only the one retry, from the 503
    }

    public function test_a_queue_that_runs_immediately_does_not_retry_by_itself(): void
    {
        $this->ecpos(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        config(['queue.default' => 'sync']);
        Queue::fake();
        [, $order] = $this->postedOrder();

        $this->runJob($order);

        $this->assertSame('failed', $order->fresh()->ecpos_state);
        Queue::assertNotPushed(SendOrderToEcpos::class); // (the alert email to admins is a separate queued job)
    }

    public function test_a_failed_send_can_be_requeued_by_hand_but_not_while_it_is_in_progress(): void
    {
        $this->ecpos(Http::response(['journalid' => 9]));
        Queue::fake();
        [$user, $order] = $this->postedOrder('failed');
        $order->forceFill(['ecpos_error' => 'Could not reach ECPOS', 'ecpos_attempts' => 5])->save();

        $this->actingAs($admin ??= $this->makeAdmin())->post("/orders/{$order->id}/ecpos-send")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(['queued', 0, null], [$order->ecpos_state, $order->ecpos_attempts, $order->ecpos_error]);
        Queue::assertPushed(SendOrderToEcpos::class, 1);

        // already queued -> nothing more to do
        $this->actingAs($admin ??= $this->makeAdmin())->post("/orders/{$order->id}/ecpos-send")->assertSessionHasErrors('ecpos');
        Queue::assertPushed(SendOrderToEcpos::class, 1);
    }

    public function test_admins_are_told_about_orders_that_did_not_reach_ecpos(): void
    {
        $admin = $this->makeAdmin();
        $this->ecpos(Http::response([]));
        [, $failed, $setup] = $this->postedOrder('failed');
        [, $stuck] = $this->postedOrder('queued', $setup);
        $stuck->forceFill(['ecpos_state_at' => now()->subMinutes(15)])->save(); // waiting far too long: is the worker running?
        [, $fresh] = $this->postedOrder('queued', $setup);
        $fresh->forceFill(['ecpos_state_at' => now()])->save();                  // just queued: fine

        $this->assertSame(2, $this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['ordering_today']['ecpos_problems']);
    }
}
