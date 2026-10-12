<?php

namespace Tests\Feature;

use App\Models\DeadlineChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private ?\App\Models\User $admin = null;

    private function props(string $query = ''): array
    {
        return $this->actingAs($this->admin ??= $this->makeAdmin())->get('/admin/audit-log'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_it_lists_order_events_and_deadline_changes_newest_first(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);
        $order = $this->seedOrder($store, $user, 'posted', null, [[$this->makeItems()['bw'], 5]]);
        $this->travelTo(now()->subMinutes(3));
        $order->record('placed', $user);
        $this->travelTo(now()->addMinute());
        $order->record('send_failed', null, 'ECPOS said no');
        $this->travelTo(now()->addMinute());
        DeadlineChange::create(['store_id' => null, 'previous_time' => '16:00', 'new_time' => '17:00', 'changed_by' => $user->id]);

        $rows = collect($this->props()['entries']['data']);

        $this->assertSame(['deadline', 'send_failed', 'placed'], $rows->pluck('event')->all());
        $this->assertNull($rows[1]['by']); // the system, not a person
        $this->assertSame('ECPOS said no', $rows[1]['note']);
        $this->assertSame($order->number(), $rows[2]['order']['number']);
        $this->assertSame('All stores (default)', $rows[0]['store']);
        $this->assertSame(['previous' => '16:00', 'new' => '17:00', 'applies_on' => null], $rows[0]['deadline']);
    }

    public function test_it_can_be_narrowed_by_type_store_and_tr_number(): void
    {
        $a = $this->makeStore('AA01', true, 'Alpha');
        $b = $this->makeStore('BB01', true, 'Beta');
        $items = $this->makeItems();
        $one = $this->seedOrder($a, $this->makeStoreUser($a), 'posted', null, [[$items['bw'], 1]]);
        $two = $this->seedOrder($b, $this->makeStoreUser($b, 'b@test.test'), 'posted', null, [[$items['bw'], 1]]);
        $one->record('placed');
        $one->record('cancelled');
        $two->record('placed');
        DeadlineChange::create(['store_id' => null, 'previous_time' => null, 'new_time' => '16:00']);

        $this->assertCount(2, $this->props('?type=placed')['entries']['data']);
        $this->assertCount(2, $this->props("?store={$a->id}")['entries']['data']);
        $this->assertCount(1, $this->props('?type=deadline')['entries']['data']);

        $found = $this->props('?search='.urlencode($two->reference()))['entries']['data'];
        $this->assertCount(1, $found);
        $this->assertSame($two->id, $found[0]['order']['id']);
    }

    public function test_it_pages_with_a_choice_of_rows_per_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            DeadlineChange::create(['store_id' => null, 'previous_time' => null, 'new_time' => '16:00']);
        }

        $default = $this->props();
        $this->assertSame(50, $default['filters']['per_page']);
        $this->assertCount(30, $default['entries']['data']);

        $small = $this->props('?per_page=10&page=3');
        $this->assertSame(10, $small['filters']['per_page']);
        $this->assertSame(3, $small['entries']['current_page']);
        $this->assertSame(3, $small['entries']['last_page']);
        $this->assertCount(10, $small['entries']['data']);

        $this->assertSame(50, $this->props('?per_page=9999')['filters']['per_page']); // only the listed sizes are accepted
    }

    public function test_only_admins_can_open_it(): void
    {
        $store = $this->makeStore();
        $this->actingAs($this->makeStoreUser($store))->get('/admin/audit-log')->assertForbidden();
        $this->get('/logout');
        auth()->logout();
        $this->get('/admin/audit-log')->assertRedirect('/login');
    }
}
