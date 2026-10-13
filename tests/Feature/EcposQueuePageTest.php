<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class EcposQueuePageTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private ?array $items = null;

    private int $n = 0;

    private function sendingOn(): void
    {
        config(['ecpos.api_key' => 'test-key', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders', 'ecpos.send_orders' => true]);
    }

    /** a posted order for a store linked to ECPOS, in the given send state */
    private function order(?string $state, bool $uncertain = false, ?\DateTimeInterface $since = null): Order
    {
        $n = ++$this->n;
        $store = $this->makeStore('SF'.(10 + $n));
        $store->forceFill(['ecpos_store_id' => 'BW'.(1000 + $n)])->save();
        $items = $this->items ??= $this->makeItems();
        $items['bw']->forceFill(['synced_at' => now()])->save();
        $order = $this->seedOrder($store, $this->makeStoreUser($store, "u{$n}@t.test"), 'posted', now()->subDays(random_int(1, 300))->toDateString(), [[$items['bw'], 2]]);
        $order->forceFill(['ecpos_state' => $state, 'ecpos_uncertain' => $uncertain, 'ecpos_state_at' => $since ?? now()])->save();

        return $order;
    }

    public function test_the_page_lists_what_has_not_reached_ecpos_by_kind(): void
    {
        $this->sendingOn();
        $this->order('failed');
        $this->order('queued', since: now()->subMinutes(30));
        $this->order('sending');
        $this->order(null);
        $this->order('sent')->forceFill(['ecpos_sent_at' => now()])->save();

        $this->actingAs($this->makeAdmin())->get('/admin/ecpos-queue')->assertOk()->assertInertia(fn ($page) => $page
            ->component('admin/ecpos-queue')
            ->has('orders', 4)
            ->where('counts', ['failed' => 1, 'stuck' => 1, 'sending' => 1, 'not_sent' => 1])
            ->where('safe_to_retry', 2));
    }

    public function test_a_store_account_cannot_open_the_page(): void
    {
        $order = $this->order('failed');
        $this->actingAs($order->user)->get('/admin/ecpos-queue')->assertForbidden();
        $this->actingAs($order->user)->post('/admin/ecpos-queue/retry')->assertForbidden();
    }

    public function test_retry_all_requeues_only_the_safe_ones(): void
    {
        $this->sendingOn();
        Queue::fake();
        $failed = $this->order('failed');
        $never = $this->order(null);
        $uncertain = $this->order('failed', uncertain: true);
        $stuck = $this->order('queued', since: now()->subMinutes(30));

        $this->actingAs($this->makeAdmin())->post('/admin/ecpos-queue/retry')->assertSessionHas('success');

        $this->assertSame('queued', $failed->fresh()->ecpos_state);
        $this->assertSame('queued', $never->fresh()->ecpos_state);
        $this->assertSame('failed', $uncertain->fresh()->ecpos_state, 'it may already be in ECPOS, so it is never sent in bulk');
        $this->assertSame('queued', $stuck->fresh()->ecpos_state);
        Queue::assertPushed(\App\Jobs\SendOrderToEcpos::class, 2);
    }

    public function test_an_area_manager_only_sees_and_retries_their_own_stores(): void
    {
        $this->sendingOn();
        Queue::fake();
        $mine = $this->order('failed');
        $theirs = $this->order('failed');
        $manager = \App\Models\User::factory()->create(['role' => \App\Models\User::AREA_MANAGER, 'email' => 'am@test.test']);
        $manager->managedStores()->attach($mine->store_id);

        $this->actingAs($manager)->get('/admin/ecpos-queue')->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.id', $mine->id));
        $this->actingAs($manager)->post('/admin/ecpos-queue/retry');

        $this->assertSame('queued', $mine->fresh()->ecpos_state);
        $this->assertSame('failed', $theirs->fresh()->ecpos_state);
    }

    public function test_the_scheduler_heartbeat_goes_stale_only_while_sending_is_on(): void
    {
        config(['ecpos.send_orders' => false]);
        $this->assertFalse(SchedulerHeartbeat::isStale());

        $this->sendingOn();
        $this->assertTrue(SchedulerHeartbeat::isStale(), 'never seen');

        SchedulerHeartbeat::beat();
        $this->assertFalse(SchedulerHeartbeat::isStale());

        $this->travel(6)->minutes();
        $this->assertTrue(SchedulerHeartbeat::isStale());
    }

    public function test_the_stale_scheduler_is_shared_with_every_page(): void
    {
        $this->sendingOn();
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get('/admin/today')->assertInertia(fn ($page) => $page->where('ordering_today.scheduler_stale', true));
        SchedulerHeartbeat::beat();
        $this->actingAs($admin)->get('/admin/today')->assertInertia(fn ($page) => $page->where('ordering_today.scheduler_stale', false));
    }
}
