<?php

namespace Tests\Feature;

use App\Jobs\SendOrderToEcpos;
use App\Mail\EcposSendFailed;
use App\Mail\OrderPosted;
use App\Models\Order;
use App\Services\EcposOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.admin_emails' => ['boss@bw.test', 'ops@bw.test'], 'notifications.order_posted' => true, 'notifications.ecpos_failed' => true]);
        Mail::fake();
    }

    private function pending(): array
    {
        $store = $this->makeStore('AA01', true, 'BW Test Store');
        $store->forceFill(['ecpos_store_id' => 'BW0018'])->save();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $items['bw']->forceFill(['synced_at' => now()])->save();

        return [$user, $this->seedOrder($store, $user, 'pending', now()->toDateString(), [[$items['bw'], 3], [$items['wh'], 2]])];
    }

    public function test_admins_are_emailed_when_a_store_posts(): void
    {
        [$user, $order] = $this->pending();

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();

        Mail::assertQueued(OrderPosted::class, function (OrderPosted $m) use ($order) {
            return $m->orderId === $order->id && $m->hasTo('boss@bw.test') && $m->hasTo('ops@bw.test');
        });
        Mail::assertQueuedCount(1);
    }

    public function test_the_email_shows_the_store_the_order_and_its_size(): void
    {
        [$user, $order] = $this->pending();
        $mail = new OrderPosted($order->id);

        $this->assertStringContainsString('BW Test Store posted an order', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('BW Test Store', $html);
        $this->assertStringContainsString('5 units in 2 items', $html);
        $this->assertStringContainsString('<img src="data:image/png', $html); // the logo travels inside the email (a data URI in previews, an inline attachment when sent)
        $this->assertStringContainsString("/orders/{$order->id}", $html);
    }

    public function test_nothing_is_sent_when_switched_off_or_nobody_is_listed(): void
    {
        [$user, $order] = $this->pending();
        config(['notifications.order_posted' => false]);
        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        Mail::assertNothingQueued();

        [$user2, $order2] = $this->pendingFor('BB01');
        config(['notifications.order_posted' => true, 'notifications.admin_emails' => []]);
        $this->actingAs($user2)->post("/orders/{$order2->id}/post")->assertSessionHasNoErrors();
        Mail::assertNothingQueued();
    }

    private function pendingFor(string $code): array
    {
        $store = $this->makeStore($code, true, 'Store '.$code);
        $store->forceFill(['ecpos_store_id' => 'BW1'.$code])->save();
        $user = $this->makeStoreUser($store, strtolower($code).'@test.test');
        $item = \App\Models\Item::where('product_code', 'BW001')->first();

        return [$user, $this->seedOrder($store, $user, 'pending', now()->toDateString(), [[$item, 1]])];
    }

    public function test_a_mail_problem_never_stops_an_order_from_posting(): void
    {
        [$user, $order] = $this->pending();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->actingAs($user)->post("/orders/{$order->id}/post")->assertSessionHasNoErrors();
        $this->assertSame('posted', $order->fresh()->status);
    }

    public function test_admins_are_emailed_when_ecpos_finally_refuses_an_order_but_not_for_a_retry(): void
    {
        config(['ecpos.api_key' => 'k', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders', 'ecpos.send_orders' => true, 'queue.default' => 'database']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        [$user, $order] = $this->pending();
        $order->forceFill(['status' => 'posted', 'ecpos_state' => 'queued'])->save();

        // ECPOS is busy: it will be tried again, so no email yet
        Http::fake(['ecpos.test/*' => Http::response([], 503)]);
        (new SendOrderToEcpos($order->id))->handle(app(EcposOrders::class));
        $this->assertSame('queued', $order->fresh()->ecpos_state);
        Mail::assertNothingQueued();

        // ECPOS refuses it: final, so the admins are told
        $order->forceFill(['ecpos_state' => 'queued'])->save();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => Http::response(['message' => 'Item not allowed'], 422)]);
        (new SendOrderToEcpos($order->id))->handle(app(EcposOrders::class));

        $this->assertSame('failed', $order->fresh()->ecpos_state);
        Mail::assertQueued(EcposSendFailed::class, fn ($m) => $m->orderId === $order->id && $m->hasTo('boss@bw.test'));
        $this->assertStringContainsString('Item not allowed', (new EcposSendFailed($order->id))->render());
    }
}
