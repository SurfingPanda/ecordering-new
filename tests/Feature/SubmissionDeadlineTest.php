<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\DeadlineChange;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreDeadline;
use App\Services\SubmissionDeadline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class SubmissionDeadlineTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): SubmissionDeadline
    {
        return app(SubmissionDeadline::class);
    }

    /* ---- the rules ---- */

    public function test_the_default_deadline_is_4pm_and_lives_in_the_database(): void
    {
        $this->at('2026-10-09 09:00');
        $store = $this->makeStore();

        $d = $this->service()->for($store);

        $this->assertSame('16:00', $d['time']);
        $this->assertSame('default', $d['source']);
        $this->assertSame('2026-10-09 16:00', $d['at']->format('Y-m-d H:i'));
        $this->assertSame('16:00', AppSetting::read('default_deadline'));
    }

    public function test_the_deadline_uses_the_configured_business_timezone(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-10-09 15:30', 'Asia/Manila'));
        $store = $this->makeStore();

        $this->assertTrue($this->service()->isOpen($store));
        $this->assertSame('Asia/Manila', $this->service()->for($store)['at']->timezoneName);

        Carbon::setTestNow(Carbon::parse('2026-10-09 16:01', 'Asia/Manila'));
        $this->assertFalse($this->service()->isOpen($store));
    }

    public function test_priority_is_date_specific_then_store_recurring_then_default(): void
    {
        $this->at('2026-10-09 09:00');
        $store = $this->makeStore();
        $other = $this->makeStore('AN01');

        AppSetting::write('default_deadline', '15:00');
        $this->assertSame(['15:00', 'default'], [$this->service()->for($store)['time'], $this->service()->for($store)['source']]);

        StoreDeadline::create(['store_id' => $store->id, 'date' => null, 'time' => '18:00']);
        $this->assertSame(['18:00', 'store'], [$this->service()->for($store)['time'], $this->service()->for($store)['source']]);
        $this->assertSame('15:00', $this->service()->for($other)['time'], 'another store is unaffected');

        StoreDeadline::create(['store_id' => $store->id, 'date' => '2026-10-09', 'time' => '12:00']);
        $this->assertSame(['12:00', 'date'], [$this->service()->for($store)['time'], $this->service()->for($store)['source']]);

        // the date-specific one only applies on its own day
        $tomorrow = Carbon::parse('2026-10-10');
        $this->assertSame(['18:00', 'store'], [$this->service()->for($store, $tomorrow)['time'], $this->service()->for($store, $tomorrow)['source']]);
    }

    /* ---- enforcement on the backend ---- */

    public function test_orders_are_accepted_before_the_deadline_and_rejected_after(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $this->at('2026-10-09 15:59');
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Order::where('status', 'pending')->count());

        // exactly at the cutoff is still allowed; one minute later is not
        $this->at('2026-10-10 16:00');
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-10'))->assertSessionHasNoErrors();
        $this->at('2026-10-11 16:01');
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-11'))->assertSessionHasErrors('deadline');

        $this->assertSame(2, Order::where('status', '!=', 'draft')->count());
    }

    public function test_the_deadline_blocks_placing_posting_and_opening_a_new_order_but_not_admins(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $admin = $this->makeAdmin();
        $this->at('2026-10-09 10:00');
        $pending = $this->seedOrder($store, $user, 'pending', '2026-10-09', [[$items['bw'], 2]]);
        $posted = $this->seedOrder($store, $user, 'posted', '2026-10-08', [[$items['bw'], 2]]);

        $this->at('2026-10-09 17:00'); // after the cutoff

        $this->actingAs($user)->postJson('/orders/draft')->assertStatus(422)->assertJsonValidationErrors('deadline');
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-12'))->assertSessionHasErrors('deadline');
        $this->actingAs($user)->post("/orders/{$pending->id}/post")->assertSessionHasErrors('deadline');
        $this->assertSame('pending', $pending->fresh()->status);

        // an already-posted order is untouched, and an admin can still cancel it
        $this->assertSame('posted', $posted->fresh()->status);
        $this->actingAs($admin)->post("/orders/{$posted->id}/cancel", ['reason' => 'after hours fix'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $posted->fresh()->status);
    }

    public function test_the_frontend_cannot_bypass_the_deadline(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $this->at('2026-10-09 17:30');

        // extra "deadline"/"override" fields, a future order date or a spoofed header change nothing
        $this->actingAs($user)->withHeaders(['X-Deadline-Override' => '23:59'])
            ->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-10', ['deadline' => '23:59', 'override' => true, 'status' => 'posted']))
            ->assertSessionHasErrors('deadline');

        $this->assertSame(0, Order::where('status', '!=', 'draft')->count());
    }

    public function test_a_failed_deadline_check_leaves_no_partial_order(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();
        $this->at('2026-10-09 18:00');

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1], [$items['wh'], 1]]))->assertSessionHasErrors('deadline');

        $this->assertSame(0, \App\Models\OrderItem::count());
        $this->assertSame(0, Order::where('status', '!=', 'draft')->count());
    }

    public function test_a_store_with_an_extended_deadline_can_still_order_after_4pm(): void
    {
        $admin = $this->makeAdmin();
        $late = $this->makeStore('LATE');
        $normal = $this->makeStore('NORM');
        $lateUser = $this->makeStoreUser($late);
        $normalUser = $this->makeStoreUser($normal);
        $items = $this->makeItems();

        $this->at('2026-10-09 10:00');
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $late->id, 'time' => '18:30'])->assertSessionHasNoErrors();

        $this->at('2026-10-09 17:45');
        $this->actingAs($lateUser)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasNoErrors();
        $this->actingAs($normalUser)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasErrors('deadline');

        $this->at('2026-10-09 18:31');
        $order = Order::where('store_id', $late->id)->where('status', 'pending')->firstOrFail();
        $this->actingAs($lateUser)->post("/orders/{$order->id}/post")->assertSessionHasErrors('deadline');
    }

    public function test_a_date_specific_override_beats_the_recurring_deadline(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $this->at('2026-10-09 09:00');
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'time' => '20:00']);                        // recurring
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'date' => '2026-10-09', 'time' => '11:00']); // today only

        $this->at('2026-10-09 12:00'); // past today's 11:00 override, well before the recurring 20:00
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]]))->assertSessionHasErrors('deadline');

        $this->at('2026-10-10 12:00'); // next day: recurring 20:00 applies again
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]], '2026-10-10'))->assertSessionHasNoErrors();
    }

    public function test_changing_the_deadline_later_does_not_invalidate_a_posted_order(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $this->at('2026-10-09 15:00');
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 1]]));
        $order = Order::where('status', 'pending')->firstOrFail();
        $this->actingAs($user)->post("/orders/{$order->id}/post");

        $this->actingAs($admin)->put('/admin/settings/default', ['time' => '09:00'])->assertSessionHasNoErrors(); // earlier than "now"

        $this->assertSame('posted', $order->fresh()->status);
    }

    /* ---- the admin settings ---- */

    public function test_an_admin_can_change_the_default_deadline_and_it_is_recorded(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $this->at('2026-10-09 09:00');

        $this->actingAs($admin)->put('/admin/settings/default', ['time' => '17:30'])->assertSessionHasNoErrors();

        $this->assertSame('17:30', $this->service()->for($store)['time']);
        $change = DeadlineChange::firstOrFail();
        $this->assertNull($change->store_id);
        $this->assertSame('16:00', $change->previous_time);
        $this->assertSame('17:30', $change->new_time);
        $this->assertSame($admin->id, $change->changed_by);

        // saving the same value again is not a "change"
        $this->actingAs($admin)->put('/admin/settings/default', ['time' => '17:30']);
        $this->assertSame(1, DeadlineChange::count());
    }

    public function test_deadline_times_are_validated(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $this->at('2026-10-09 09:00');

        foreach (['', '25:00', '4pm', '16:61', '1600'] as $bad) {
            $this->actingAs($admin)->put('/admin/settings/default', ['time' => $bad])->assertSessionHasErrors('time');
            $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'time' => $bad])->assertSessionHasErrors('time');
        }
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => 9999, 'time' => '10:00'])->assertSessionHasErrors('store_id');
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'date' => '2026-10-01', 'time' => '10:00'])->assertSessionHasErrors('date');
        $this->assertSame('16:00', $this->service()->defaultTime());
    }

    public function test_store_deadlines_can_be_updated_reset_and_are_logged(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $this->at('2026-10-09 09:00');

        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'time' => '18:00']);
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $store->id, 'time' => '19:00']); // update
        $this->assertSame(1, StoreDeadline::where('store_id', $store->id)->count(), 'updated in place, not duplicated');
        $this->assertSame('19:00', $this->service()->for($store)['time']);

        $row = StoreDeadline::firstOrFail();
        $this->actingAs($admin)->delete("/admin/settings/store-deadlines/{$row->id}")->assertSessionHasNoErrors(); // reset to default
        $this->assertSame(['16:00', 'default'], [$this->service()->for($store)['time'], $this->service()->for($store)['source']]);

        $log = DeadlineChange::orderBy('id')->get();
        $this->assertSame([[null, '18:00'], ['18:00', '19:00'], ['19:00', null]], $log->map(fn ($c) => [$c->previous_time, $c->new_time])->all());
        $this->assertTrue($log->every(fn ($c) => $c->changed_by === $admin->id && $c->store_id === $store->id));
    }

    public function test_the_settings_page_shows_effective_deadlines_and_history(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeStore('AA01');
        $b = $this->makeStore('BB01');
        $this->at('2026-10-09 09:00');
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $b->id, 'time' => '18:00']);
        $this->actingAs($admin)->post('/admin/settings/store-deadlines', ['store_id' => $a->id, 'date' => '2026-10-09', 'time' => '12:00']);

        $props = $this->actingAs($admin)->get('/admin/settings')->viewData('page')['props'];

        $byCode = collect($props['stores'])->keyBy('code');
        $this->assertSame(['12:00', 'date'], [$byCode['AA01']['effective_time'], $byCode['AA01']['source']]);
        $this->assertSame(['18:00', 'store'], [$byCode['BB01']['effective_time'], $byCode['BB01']['source']]);
        $this->assertSame('16:00', $props['default']['time']);
        $this->assertCount(2, $props['history']);
        $this->assertSame($admin->name, $props['history'][0]['by']);
        $this->assertCount(1, $props['overrides']);
    }

    public function test_the_deadline_is_shared_with_store_pages_but_not_admin_pages(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->at('2026-10-09 10:00');

        $deadline = $this->actingAs($user)->get('/dashboard')->viewData('page')['props']['deadline'];
        $this->assertSame('16:00', $deadline['time']);
        $this->assertTrue($deadline['open']);

        $this->assertNull($this->actingAs($this->makeAdmin())->get('/dashboard')->viewData('page')['props']['deadline']);
        $this->assertInstanceOf(Store::class, $store);
    }
}
