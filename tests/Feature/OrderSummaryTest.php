<?php

namespace Tests\Feature;

use App\Models\DailyItemTotal;
use App\Models\Order;
use App\Services\OrderSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

/** daily_item_totals (what the dashboard and trends add up) must always equal the placed orders' lines. */
class OrderSummaryTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    /** What the totals should be, worked out straight from the order lines. */
    private function expected(): array
    {
        return Order::whereIn('status', Order::ACTIVE)->with('lines')->get()
            ->flatMap(fn (Order $o) => $o->lines->map(fn ($l) => [
                'key' => $o->order_date->toDateString().'|'.$o->store_id.'|'.$l->product_code.'|'.$l->source,
                'units' => $l->quantity,
            ]))
            ->groupBy('key')->map(fn ($g) => $g->sum('units'))->sortKeys()->all();
    }

    private function actual(): array
    {
        return DailyItemTotal::all()
            ->mapWithKeys(fn ($r) => [substr((string) $r->order_date, 0, 10).'|'.$r->store_id.'|'.$r->product_code.'|'.$r->source => (int) $r->units])
            ->sortKeys()->all();
    }

    /** The all-stores rollup the admin pages read must equal the per-store rows added up. */
    private function assertCatalogRollupMatches(): void
    {
        $fromStores = DailyItemTotal::all()
            ->groupBy(fn ($r) => substr((string) $r->order_date, 0, 10).'|'.$r->product_code.'|'.$r->source)
            ->map(fn ($g) => [(int) $g->sum('units'), (int) $g->sum('order_count')])->sortKeys()->all();

        $rollup = \App\Models\DailyCatalogTotal::all()
            ->mapWithKeys(fn ($r) => [substr((string) $r->order_date, 0, 10).'|'.$r->product_code.'|'.$r->source => [(int) $r->units, (int) $r->order_count]])
            ->sortKeys()->all();

        $this->assertSame($fromStores, $rollup);
    }

    public function test_placing_posting_resetting_and_cancelling_keep_the_totals_in_step(): void
    {
        $store = $this->makeStore('A1');
        $user = $this->makeStoreUser($store);
        $admin = $this->makeAdmin();
        $items = $this->makeItems();

        // a draft is not counted
        $this->actingAs($user)->put('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 4]]])->assertOk();
        $this->assertSame([], $this->actual());

        // placing it counts it
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 4], [$items['wh'], 3]]))->assertRedirect();
        $order = Order::where('status', 'pending')->firstOrFail();
        $this->assertSame($this->expected(), $this->actual());
        $this->assertCount(2, $this->actual());
        $this->assertSame(1, (int) DailyItemTotal::where('product_code', $items['bw']->product_code)->value('order_count'));

        // posting changes nothing
        $this->actingAs($user)->post("/orders/{$order->id}/post");
        $this->assertSame($this->expected(), $this->actual());

        // an admin cancelling it takes it out again
        $this->actingAs($admin)->post("/orders/{$order->id}/cancel", ['reason' => 'wrong store'])->assertRedirect();
        $this->assertSame([], $this->actual());
        $this->assertSame(0, \App\Models\DailyCatalogTotal::count());
        $this->assertSame([], $this->expected());
    }

    public function test_resetting_a_pending_order_takes_it_out_and_placing_it_again_puts_it_back(): void
    {
        $store = $this->makeStore('A1');
        $user = $this->makeStoreUser($store);
        $items = $this->makeItems();

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 2]]));
        $order = Order::where('status', 'pending')->firstOrFail();
        $this->assertCount(1, $this->actual());

        $this->actingAs($user)->post("/orders/{$order->id}/reset")->assertRedirect();
        $this->assertSame([], $this->actual());

        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 7], [$items['bw2'], 1]]));
        $this->assertSame($this->expected(), $this->actual());
        $this->assertSame(7, (int) DailyItemTotal::where('product_code', $items['bw']->product_code)->value('units'));
    }

    public function test_several_stores_and_days_add_up_separately_and_a_full_rebuild_matches(): void
    {
        $items = $this->makeItems();
        $a = $this->makeStore('A1');
        $b = $this->makeStore('B1');
        $ua = $this->makeStoreUser($a);
        $ub = $this->makeStoreUser($b);

        $this->seedOrder($a, $ua, 'posted', '2026-10-01', [[$items['bw'], 5], [$items['wh'], 2]]);
        $this->seedOrder($a, $ua, 'pending', '2026-10-02', [[$items['bw'], 3]]);
        $this->seedOrder($b, $ub, 'posted', '2026-10-01', [[$items['bw'], 10]]);
        $this->seedOrder($b, $ub, 'cancelled', '2026-10-03', [[$items['bw'], 99]]); // never counted
        $this->seedOrder($b, $ub, 'draft', '2026-10-04', [[$items['bw'], 98]]);     // never counted

        $this->assertSame($this->expected(), $this->actual());
        $this->assertCount(4, $this->actual());
        $this->assertCatalogRollupMatches();
        $this->assertSame(15, (int) \App\Models\DailyCatalogTotal::where('order_date', '2026-10-01')->where('product_code', $items['bw']->product_code)->value('units')); // 5 + 10 across both stores
        $this->assertSame(2, (int) \App\Models\DailyCatalogTotal::where('order_date', '2026-10-01')->where('product_code', $items['bw']->product_code)->value('order_count'));

        // the nightly rebuild (and the migration backfill) gives exactly the same answer as the live updates
        $live = $this->actual();
        OrderSummary::rebuild();
        $this->assertSame($live, $this->actual());
        $this->assertCatalogRollupMatches();

        // and putting it right after someone tampered with the table
        DailyItemTotal::query()->delete();
        \App\Models\DailyCatalogTotal::query()->delete();
        $this->artisan('orders:rebuild-summary')->assertSuccessful();
        $this->assertSame($live, $this->actual());
        $this->assertCatalogRollupMatches();
    }

    public function test_the_dashboard_totals_match_the_order_lines(): void
    {
        $items = $this->makeItems();
        $a = $this->makeStore('A1');
        $ua = $this->makeStoreUser($a);
        $this->seedOrder($a, $ua, 'posted', now()->toDateString(), [[$items['bw'], 6], [$items['wh'], 4]]);

        $page = $this->actingAs($this->makeAdmin())->get('/dashboard')->viewData('page')['props'];

        $this->assertSame(10, $page['kpis']['units']['value']);
        $this->assertSame(2, $page['kpis']['items_ordered']);
        $this->assertSame(6, $page['split']['bw_products']['units']);
        $this->assertSame(1, $page['top_items']['bw_products'][0]['orders']);
    }
}
