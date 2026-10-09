<?php

namespace Tests\Feature;

use App\Models\StoreDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class TodayOrdersTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_admin_sees_who_has_and_hasnt_ordered_today(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $today = now()->toDateString();

        $done = $this->makeStore('AA01', true, 'Store Done');
        $doneUser = $this->makeStoreUser($done);
        $this->seedOrder($done, $doneUser, 'posted', $today, [[$items['bw'], 4], [$items['wh'], 2]]);

        $cancelled = $this->makeStore('BB01', true, 'Store Cancelled');
        $cancelledUser = $this->makeStoreUser($cancelled);
        $this->seedOrder($cancelled, $cancelledUser, 'cancelled', $today, [[$items['bw'], 1]]); // a cancelled TR doesn't count

        $started = $this->makeStore('CC01', true, 'Store Started');
        $startedUser = $this->makeStoreUser($started);
        $this->actingAs($startedUser)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 1]]])->assertOk();

        $this->makeStore('DD01', true, 'Store No Login'); // active but nobody can sign in
        $this->makeStore('EE01', false, 'Store Inactive'); // inactive stores are not expected to order

        // an extended deadline for one store shows up on its row
        StoreDeadline::create(['store_id' => $started->id, 'date' => $today, 'time' => '18:30']);

        $props = $this->actingAs($admin)->get('/admin/today')->assertOk()->viewData('page')['props'];

        $this->assertSame(['total' => 4, 'ordered' => 1, 'waiting' => 3, 'no_login' => 1], $props['summary']);
        $this->assertSame(['Store Done'], collect($props['ordered'])->pluck('name')->all());
        $this->assertSame(6, $props['ordered'][0]['order']['units']);

        $waiting = collect($props['waiting'])->keyBy('name');
        $this->assertEqualsCanonicalizing(['Store Cancelled', 'Store Started', 'Store No Login'], $waiting->keys()->all());
        $this->assertTrue($waiting['Store Started']['in_progress']);
        $this->assertFalse($waiting['Store Cancelled']['in_progress']);
        $this->assertFalse($waiting['Store No Login']['has_login']);
        $this->assertSame('18:30', $waiting['Store Started']['deadline_time']);
        // the store with the latest cutoff is listed last
        $this->assertSame('Store Started', collect($props['waiting'])->last()['name']);
    }

    public function test_it_can_look_at_another_day_and_is_admin_only(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $this->seedOrder($store, $user, 'posted', '2026-10-05', [[$this->makeItems()['bw'], 3]]);

        $props = $this->actingAs($admin)->get('/admin/today?date=2026-10-05')->viewData('page')['props'];
        $this->assertSame(['total' => 1, 'ordered' => 1, 'waiting' => 0, 'no_login' => 0], $props['summary']);
        $this->assertFalse($props['is_today']);

        // nonsense dates fall back to today
        $this->assertSame(now()->toDateString(), $this->actingAs($admin)->get('/admin/today?date=garbage')->viewData('page')['props']['date']);

        $this->actingAs($user)->get('/admin/today')->assertForbidden();
    }

    public function test_the_shared_data_behind_the_reminders(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $a = $this->makeStore('AA01', true, 'Alpha');
        $b = $this->makeStore('BB01', true, 'Bravo');
        $userA = $this->makeStoreUser($a);
        $userB = $this->makeStoreUser($b);

        // admin: 2 active stores, nobody has ordered; a store login gets no admin summary
        $today = $this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['ordering_today'];
        $this->assertSame([2, 2], [$today['total'], $today['not_ordered']]);
        $this->assertNull($this->actingAs($userA)->get('/dashboard')->viewData('page')['props']['ordering_today']);

        // store A hasn't ordered -> its reminder applies; once it has, it doesn't
        $this->assertFalse($this->actingAs($userA)->get('/dashboard')->viewData('page')['props']['deadline']['ordered_today']);
        $this->seedOrder($a, $userA, 'pending', now()->toDateString(), [[$items['bw'], 1]]);
        $this->assertTrue($this->actingAs($userA)->get('/dashboard')->viewData('page')['props']['deadline']['ordered_today']);
        $this->assertFalse($this->actingAs($userB)->get('/dashboard')->viewData('page')['props']['deadline']['ordered_today']);

        $this->assertSame(1, $this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['ordering_today']['not_ordered']);
    }
}
