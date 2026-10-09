<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class CatalogExportTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    /** Opens the downloaded workbook and returns [sheet name => rows (array of cell arrays)]. */
    private function read(TestResponse $response): array
    {
        $response->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'read').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        $reader = new Reader;
        $reader->open($copy);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = array_map(fn ($v) => is_string($v) || $v === null ? $v : (string) $v, $row->toArray());
            }
        }
        $reader->close();
        @unlink($copy);

        return $sheets;
    }

    private function codes(array $rows): array
    {
        return collect(array_slice($rows, 1))->pluck(0)->all(); // skip the header row
    }

    private function mk(string $code, string $source = 'bw_products', array $extra = []): Item
    {
        return Item::create([
            'product_code' => $code, 'description' => "Item $code", 'source' => $source, 'category' => null,
            'retail_group' => $source === 'warehouse' ? 'non_product' : 'regular_product', ...$extra,
        ]);
    }

    public function test_an_admin_downloads_a_real_excel_file(): void
    {
        $this->at('2026-10-09 10:00');
        $this->makeItems();

        $response = $this->actingAs($this->makeAdmin())->get('/retails/export?source=bw_products');

        $response->assertOk()->assertDownload('bw-products-catalog-2026-10-09.xlsx');
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('PK', file_get_contents($response->baseResponse->getFile()->getPathname(), false, null, 0, 2), 'an .xlsx is a zip archive');
        \Carbon\Carbon::setTestNow();
    }

    public function test_the_sheet_has_the_header_and_the_current_catalogs_active_items_only(): void
    {
        $items = $this->makeItems(); // BW001 BW002 (bw) / WH001 WH002 (warehouse)
        $this->actingAs($admin = $this->makeAdmin())->post('/retails/archive', ['ids' => [$items['bw2']->id]]);

        $sheets = $this->read($this->actingAs($admin)->get('/retails/export?source=bw_products'));

        $this->assertSame(['BW Products'], array_keys($sheets));
        $rows = $sheets['BW Products'];
        $this->assertSame(['Product Code', 'Description', 'Barcode', 'Category', 'Retail Group', 'Catalog'], $rows[0]);
        $this->assertSame(['BW001'], $this->codes($rows), 'archived BW002 and the warehouse items are not exported');
        $this->assertSame(['BW001', 'Ube Cake', '', 'CAKES', 'Regular Product', 'BW Products'], $rows[1]);

        $warehouse = $this->read($this->actingAs($admin)->get('/retails/export?source=warehouse'));
        $this->assertSame(['WH001', 'WH002'], $this->codes($warehouse['Warehouse']));
        $this->assertSame('Non-product', $warehouse['Warehouse'][1][4]);
    }

    public function test_every_matching_row_is_exported_not_just_the_first_page(): void
    {
        foreach (range(1, 60) as $i) {
            $this->mk(sprintf('P%03d', $i));
        }

        $rows = $this->read($this->actingAs($this->makeAdmin())->get('/retails/export?source=bw_products'))['BW Products'];

        $this->assertCount(61, $rows, '60 items + the header (the list page shows only 50 at a time)');
    }

    public function test_the_export_follows_the_filters_and_sorting(): void
    {
        $admin = $this->makeAdmin();
        $this->mk('AAA-1', 'bw_products', ['description' => 'Ube roll']);
        $this->mk('BBB-2', 'bw_products', ['description' => 'Pandesal']);
        $this->mk('CCC-3', 'bw_products', ['description' => 'Pandesal special', 'retail_group' => 'non_product']);

        $get = fn (string $q) => $this->codes($this->read($this->actingAs($admin)->get("/retails/export?source=bw_products&$q"))['BW Products']);

        $this->assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $get(''));
        $this->assertSame(['CCC-3', 'BBB-2', 'AAA-1'], $get('sort=product_code&dir=desc'));
        $this->assertSame(['BBB-2', 'CCC-3'], $get('search=pandesal'));
        $this->assertSame(['CCC-3'], $get('search=pandesal&group=non_product'));
        $this->assertSame(['AAA-1', 'BBB-2'], $get('group=regular_product'));
        $this->assertSame(['BBB-2'], $get('search=BBB'));
    }

    public function test_text_is_kept_exactly_as_text(): void
    {
        $this->mk('Z-1', 'bw_products', ['description' => '=HYPERLINK("http://x","click")', 'barcode' => '0012345678905']);

        $rows = $this->read($this->actingAs($this->makeAdmin())->get('/retails/export?source=bw_products'))['BW Products'];

        $this->assertSame('=HYPERLINK("http://x","click")', $rows[1][1], 'a formula-looking description stays plain text');
        $this->assertSame('0012345678905', $rows[1][2], 'a barcode keeps its leading zeros');
    }

    public function test_the_archived_list_can_be_exported_with_its_archive_date(): void
    {
        $this->at('2026-10-09 15:30');
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]]);

        $sheets = $this->read($this->actingAs($admin)->get('/retails/export?source=bw_products&archived=1'));

        $this->assertSame(['BW Products (archived)'], array_keys($sheets));
        $rows = $sheets['BW Products (archived)'];
        $this->assertSame('Archived On', $rows[0][6]);
        $this->assertSame(['BW001'], $this->codes($rows));
        $this->assertSame('2026-10-09 15:30', $rows[1][6]);
        \Carbon\Carbon::setTestNow();
    }

    public function test_an_empty_list_still_gives_a_valid_file(): void
    {
        $rows = $this->read($this->actingAs($this->makeAdmin())->get('/retails/export?source=warehouse'))['Warehouse'];

        $this->assertCount(1, $rows);
        $this->assertSame('Product Code', $rows[0][0]);
    }

    public function test_bad_parameters_fall_back_safely(): void
    {
        $this->mk('K-1');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/retails/export?source=moon&group=zzz&sort=password;drop&dir=sideways')->assertOk();
        $rows = $this->read($this->actingAs($admin)->get('/retails/export?source=moon'));
        $this->assertSame(['K-1'], $this->codes($rows['BW Products']), 'an unknown catalog falls back to BW Products');
    }

    public function test_only_admins_can_export(): void
    {
        $this->makeItems();
        $store = $this->makeStoreUser($this->makeStore());

        $this->actingAs($store)->get('/retails/export?source=bw_products')->assertForbidden();
        $this->actingAs($store)->get('/retails/export?source=warehouse&archived=1')->assertForbidden();

        auth()->logout();
        $this->get('/retails/export?source=bw_products')->assertRedirect('/login');
    }

    public function test_the_catalog_page_is_unchanged_for_stores(): void
    {
        $this->makeItems();
        Category::create(['source' => 'bw_products', 'name' => 'CAKES']);
        $store = $this->makeStoreUser($this->makeStore());

        $this->actingAs($store)->get('/retails?source=bw_products')->assertOk();
    }
}
