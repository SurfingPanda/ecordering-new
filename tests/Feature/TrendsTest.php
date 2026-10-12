<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class TrendsTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private ?\App\Models\User $admin = null;

    private function props(string $query = ''): array
    {
        return $this->actingAs($this->admin ??= $this->makeAdmin())->get('/admin/trends'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_weekly_units_are_counted_per_store_and_compared_with_the_week_before(): void
    {
        $items = $this->makeItems();
        $busy = $this->makeStore('AA01', true, 'Busy Store');
        $busyUser = $this->makeStoreUser($busy);
        $quiet = $this->makeStore('BB01', true, 'Quiet Store');
        $quietUser = $this->makeStoreUser($quiet);

        $thisWeek = CarbonImmutable::today()->startOfWeek();
        $lastWeek = $thisWeek->subWeek()->addDay()->toDateString();   // inside the last complete week
        $weekBefore = $thisWeek->subWeeks(2)->addDay()->toDateString();

        $this->seedOrder($busy, $busyUser, 'posted', $weekBefore, [[$items['bw'], 10]]);
        $this->seedOrder($busy, $busyUser, 'pending', $lastWeek, [[$items['bw'], 15], [$items['wh'], 5]]);
        $this->seedOrder($busy, $busyUser, 'cancelled', $lastWeek, [[$items['bw'], 999]]); // cancelled never counts
        $this->seedOrder($busy, $busyUser, 'posted', $thisWeek->toDateString(), [[$items['bw'], 7]]); // running week: shown, not compared
        $this->seedOrder($quiet, $quietUser, 'posted', $weekBefore, [[$items['bw'], 4]]);              // ordered before, nothing last week

        $p = $this->props();

        $this->assertSame('week', $p['by']);
        $this->assertCount(12, $p['periods']);
        $this->assertTrue($p['periods'][11]['partial']);
        $this->assertFalse($p['periods'][10]['partial']);

        $busyRow = collect($p['stores'])->firstWhere('code', 'AA01');
        $this->assertSame([20, 10, 100, 37], [$busyRow['latest'], $busyRow['previous'], $busyRow['change'], $busyRow['sum']]);
        $this->assertSame(7, $busyRow['series'][11]);

        $this->assertSame([20, 14], [$p['overall']['latest'], $p['overall']['previous']]);
        $this->assertSame('Busy Store', $p['stores'][0]['name']); // busiest last week first
        $this->assertSame(['Quiet Store'], $p['quiet_stores']);
    }

    public function test_items_can_be_limited_to_one_catalog_and_months_work_too(): void
    {
        $items = $this->makeItems();
        $store = $this->makeStore('AA01');
        $user = $this->makeStoreUser($store);
        $lastMonth = CarbonImmutable::today()->startOfMonth()->subMonth()->addDays(2)->toDateString();
        $this->seedOrder($store, $user, 'posted', $lastMonth, [[$items['bw'], 6], [$items['wh'], 3]]);

        $all = $this->props('?by=month');
        $this->assertSame('month', $all['by']);
        $this->assertCount(2, $all['items']);
        $this->assertSame(9, $all['overall']['latest']);

        $only = $this->props('?by=month&source=warehouse');
        $this->assertSame('warehouse', $only['source']);
        $this->assertSame([$items['wh']->product_code], array_column($only['items'], 'code'));
        $this->assertSame(3, $only['items'][0]['latest']);

        $this->assertSame('week', $this->props('?by=nonsense&source=junk')['by']); // bad input falls back
    }

    public function test_only_admins_can_open_it(): void
    {
        $user = $this->makeStoreUser($this->makeStore('AA01'));
        $this->actingAs($user)->get('/admin/trends')->assertForbidden();
    }
}
