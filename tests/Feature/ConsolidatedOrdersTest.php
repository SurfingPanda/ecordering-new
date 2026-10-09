<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class ConsolidatedOrdersTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private Store $a;

    private Store $b;

    private array $items;

    private $admin;

    /** Fixed data set used by every test. Order dates: Oct 7, 8, 9. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-09 10:00');

        $this->admin = $this->makeAdmin();
        $this->a = $this->makeStore('AA01', true, 'Alpha Store');
        $this->b = $this->makeStore('BB01', true, 'Bravo Store');
        $ua = $this->makeStoreUser($this->a);
        $ub = $this->makeStoreUser($this->b);
        $this->items = $this->makeItems(); // BW001 BW002 (bw) / WH001 WH002 (warehouse)
        [$bw, $bw2, $wh] = [$this->items['bw'], $this->items['bw2'], $this->items['wh']];

        $this->seedOrder($this->a, $ua, 'posted', '2026-10-07', [[$bw, 10], [$wh, 2]]);          // order 1
        $this->seedOrder($this->b, $ub, 'posted', '2026-10-08', [[$bw, 15], [$bw2, 4]]);         // order 2
        $this->seedOrder($this->a, $ua, 'pending', '2026-10-09', [[$bw, 8], [$wh, 5]]);          // order 3
        $this->seedOrder($this->b, $ub, 'pending', '2026-10-09', [[$wh, 7]]);                    // order 4
        $cancelled = $this->seedOrder($this->b, $ub, 'cancelled', '2026-10-09', [[$bw, 100]]);   // order 5 - must not count
        $cancelled->update(['cancelled_at' => now(), 'cancelled_by' => $this->admin->id, 'cancellation_reason' => 'dup']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(string $query = '')
    {
        return $this->actingAs($this->admin)->get('/admin/consolidated'.($query ? "?$query" : ''))->viewData('page')['props'];
    }

    private function rows(string $query): array
    {
        return $this->page($query)['detail']['data'];
    }

    public function test_it_defaults_to_today_and_filters_by_date_range(): void
    {
        $today = $this->rows('');
        $this->assertSame(['2026-10-09'], collect($today)->pluck('order_date')->unique()->values()->all());
        $this->assertCount(4, $today); // order 3 (2 lines) + order 4 (1 line) + order 5 (1 line, cancelled)

        $this->assertCount(4, $this->rows('from=2026-10-07&to=2026-10-08'), 'Oct 7-8: 2 + 2 lines');
        $this->assertCount(2, $this->rows('from=2026-10-08&to=2026-10-08'));
        $this->assertCount(8, $this->rows('from=2026-10-01&to=2026-10-31'));
        $this->assertCount(0, $this->rows('from=2026-09-01&to=2026-09-30'));

        // reversed range is normalised rather than returning nothing
        $this->assertCount(4, $this->rows('from=2026-10-08&to=2026-10-07'));
        // garbage dates fall back to today
        $this->assertSame('2026-10-09', $this->page('from=nope&to=junk')['filters']['from']);
    }

    public function test_filters_work_together(): void
    {
        $range = 'from=2026-10-07&to=2026-10-09';

        $this->assertCount(2, $this->rows("$range&store={$this->a->id}&status=pending"));                  // order 3 only
        $this->assertCount(1, $this->rows("$range&store={$this->a->id}&status=pending&type=warehouse"));  // its warehouse line
        $this->assertSame(['WH001'], collect($this->rows("$range&store={$this->a->id}&status=pending&type=warehouse"))->pluck('product_code')->all());
        $this->assertCount(1, $this->rows("$range&store={$this->b->id}&status=cancelled"));
        $this->assertCount(0, $this->rows("$range&store={$this->a->id}&status=cancelled"));

        // reference: the full TR number, or just part of it
        $tr3 = \App\Models\Order::find(3)->number();
        $this->assertCount(2, $this->rows('from=2026-10-01&to=2026-10-31&ref='.$tr3));
        $this->assertCount(2, $this->rows('from=2026-10-01&to=2026-10-31&ref='.strtolower($tr3)));
        $this->assertCount(0, $this->rows('from=2026-10-01&to=2026-10-31&ref=garbage'));

        // product name or SKU
        $this->assertCount(3, $this->rows("$range&product=wh001")); // WH001 is on orders 1, 3 and 4
    }

    public function test_search_by_product_name_or_sku(): void
    {
        $all = 'from=2026-10-01&to=2026-10-31';

        $bySku = collect($this->rows("$all&product=WH001"))->pluck('product_code')->unique()->all();
        $this->assertSame(['WH001'], $bySku);

        $byName = collect($this->rows("$all&product=pandesal"))->pluck('product_code')->unique()->all();
        $this->assertSame(['BW002'], $byName);

        // combined with a store filter
        $this->assertCount(0, $this->rows("$all&product=pandesal&store={$this->a->id}"));
        $this->assertCount(1, $this->rows("$all&product=pandesal&store={$this->b->id}"));
    }

    public function test_summary_numbers_exclude_cancelled_orders(): void
    {
        $s = $this->page('from=2026-10-07&to=2026-10-09')['summary'];

        $this->assertSame(2, $s['stores']);
        $this->assertSame(4, $s['orders']);            // orders 1-4; the cancelled one is not counted
        $this->assertSame(3, $s['products']);          // BW001, BW002, WH001
        $this->assertSame(10 + 2 + 15 + 4 + 8 + 5 + 7, $s['units']);
        $this->assertSame(10 + 15 + 4 + 8, $s['bw_units']);
        $this->assertSame(2 + 5 + 7, $s['warehouse_units']);
        $this->assertSame(['pending' => 2, 'posted' => 2, 'cancelled' => 1], $s['status']);
    }

    public function test_product_summary_groups_quantities_by_store(): void
    {
        $p = $this->page('from=2026-10-07&to=2026-10-09&view=products')['pivot'];

        // store columns are generated from the data
        $this->assertSame(['AA01', 'BB01'], collect($p['stores'])->pluck('code')->all());

        $rows = collect($p['rows'])->keyBy('product_code');
        $idA = $this->a->id;
        $idB = $this->b->id;

        $this->assertEquals([$idA => 18, $idB => 15], $rows['BW001']['by_store']); // 10 + 8 | 15  (cancelled 100 excluded)
        $this->assertSame(33, $rows['BW001']['total']);
        $this->assertEquals([$idB => 4], $rows['BW002']['by_store']);
        $this->assertEquals([$idA => 7, $idB => 7], $rows['WH001']['by_store']);
        $this->assertSame(14, $rows['WH001']['total']);

        // grand totals match the detail summary
        $this->assertSame(33 + 4 + 14, $p['totals']['total']);
        $this->assertEquals([$idA => 25, $idB => 26], $p['totals']['by_store']);
    }

    public function test_product_summary_respects_filters(): void
    {
        $p = $this->page("from=2026-10-07&to=2026-10-09&view=products&store={$this->a->id}&type=warehouse")['pivot'];

        $this->assertSame(['AA01'], collect($p['stores'])->pluck('code')->all());
        $this->assertSame(['WH001'], collect($p['rows'])->pluck('product_code')->all());
        $this->assertSame(7, $p['rows'][0]['total']);
    }

    public function test_sorting_and_pagination(): void
    {
        $range = 'from=2026-10-01&to=2026-10-31';

        $byQty = collect($this->rows("$range&sort=quantity&dir=desc"))->pluck('quantity')->all();
        $this->assertSame(collect($byQty)->sortDesc()->values()->all(), $byQty);

        $byCode = collect($this->rows("$range&sort=product_code&dir=asc"))->pluck('product_code')->all();
        $this->assertSame(collect($byCode)->sort()->values()->all(), $byCode);

        // an unknown sort column is ignored rather than reaching the query
        $this->actingAs($this->admin)->get("/admin/consolidated?$range&sort=password;drop")->assertOk();

        // 8 + 21 = 29 lines -> pages of 25 and 4
        $store = $this->makeStore('CC01');
        $order = $this->seedOrder($store, $this->makeStoreUser($store), 'posted', '2026-10-09');
        foreach (range(1, 21) as $i) {
            $item = Item::create(['product_code' => "X$i", 'description' => "Extra $i", 'source' => 'warehouse', 'category' => 'X', 'retail_group' => 'non_product']);
            $order->lines()->create(['item_id' => $item->id, 'product_code' => $item->product_code, 'name' => $item->description, 'source' => 'warehouse', 'category' => 'X', 'quantity' => 1]);
        }
        $page1 = $this->page("$range");
        $this->assertSame(29, $page1['detail']['total']);
        $this->assertCount(25, $page1['detail']['data']);
        $this->assertCount(4, $this->page("$range&page=2")['detail']['data']);
    }

    public function test_detail_rows_carry_everything_the_admin_needs(): void
    {
        $row = collect($this->rows('from=2026-10-07&to=2026-10-07'))->firstWhere('product_code', 'BW001');

        $this->assertSame('TR-AA01-000001', $row['number']);
        $this->assertSame('2026-10-07', $row['order_date']);
        $this->assertSame('AA01', $row['store_code']);
        $this->assertSame('Alpha Store', $row['store_name']);
        $this->assertSame('Ube Cake', $row['name']);
        $this->assertSame('CAKES', $row['category']);
        $this->assertSame('bw_products', $row['source']);
        $this->assertSame(10, $row['quantity']);
        $this->assertSame('posted', $row['status']);
        $this->assertNotNull($row['submitted_at']);
    }

    public function test_cancelled_orders_remain_visible_in_the_historical_detail(): void
    {
        $rows = $this->rows('from=2026-10-09&to=2026-10-09&status=cancelled');
        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows[0]['status']);
        $this->assertSame(100, $rows[0]['quantity']);
    }

    /* ---- export ---- */

    public function test_export_respects_the_active_filters(): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/consolidated/export?from=2026-10-07&to=2026-10-09&store={$this->b->id}&status=pending");
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF"))));

        $this->assertSame(['Order Date', 'TR Number', 'Store Code'], array_slice(str_getcsv($lines[0]), 0, 3));
        $this->assertCount(2, $lines, 'header + the single pending Bravo line');
        $row = str_getcsv($lines[1]);
        $this->assertSame('TR-BB01-000002', $row[1]);
        $this->assertSame('BB01', $row[2]);
        $this->assertStringNotContainsString('AA01', $csv);
    }

    public function test_product_export_has_one_column_per_store(): void
    {
        $csv = $this->actingAs($this->admin)->get('/admin/consolidated/export?from=2026-10-07&to=2026-10-09&view=products')->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF"))));

        $this->assertSame(['Product Code', 'Product', 'Item Type', 'Category', 'AA01 - Alpha Store', 'BB01 - Bravo Store', 'Total'], str_getcsv($lines[0]));
        $bw001 = str_getcsv(collect($lines)->first(fn ($l) => str_starts_with($l, 'BW001')));
        $this->assertSame(['BW001', 'Ube Cake', 'BW Product', 'CAKES', '18', '15', '33'], $bw001);
    }

    public function test_export_is_admin_only(): void
    {
        $user = $this->makeStoreUser($this->a, 'someone@test.test');

        $this->actingAs($user)->get('/admin/consolidated/export?from=2026-10-07&to=2026-10-09')->assertForbidden();
        $this->actingAs($user)->get('/admin/consolidated')->assertForbidden();
    }

    public function test_spreadsheet_formulas_in_text_are_neutralised(): void
    {
        $order = \App\Models\Order::firstOrFail();
        $order->lines()->create(['item_id' => null, 'product_code' => 'EVIL', 'name' => '=HYPERLINK("http://x")', 'source' => 'warehouse', 'category' => '-1', 'quantity' => 1]);

        $csv = $this->actingAs($this->admin)->get('/admin/consolidated/export?from=2026-10-07&to=2026-10-07')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }
}
