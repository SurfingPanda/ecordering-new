<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class EcposSyncTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function feed(array $rows): void
    {
        config(['ecpos.api_key' => 'test-key', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders', 'ecpos.skip_groups' => []]);
        Http::swap(new \Illuminate\Http\Client\Factory); // a fresh fake each time, otherwise the first one keeps answering
        Http::fake(['ecpos.test/*' => Http::response($rows, 200)]);
    }

    private function row(string $id, string $name, string $group = 'BW BREADS', string $dept = 'REGULAR PRODUCT'): array
    {
        return ['itemid' => $id, 'itemname' => $name, 'itemgroup' => $group, 'itemdepartment' => $dept, 'moq' => null];
    }

    public function test_sync_adds_new_bw_products_with_their_group_as_category(): void
    {
        $this->feed([$this->row('BRE-1', 'Pandesal'), $this->row('PRM-9', 'Coffee', 'BW PROMO', 'NON PRODUCT')]);

        $this->artisan('ecpos:sync-items')->assertSuccessful();

        $a = Item::where('product_code', 'BRE-1')->firstOrFail();
        $this->assertSame(['bw_products', 'Pandesal', 'BW BREADS', 'regular_product'], [$a->source, $a->description, $a->category, $a->retail_group]);
        $this->assertNull($a->barcode);
        $this->assertNotNull($a->synced_at);
        $this->assertSame('non_product', Item::where('product_code', 'PRM-9')->value('retail_group'));
        $this->assertTrue(Category::where('source', 'bw_products')->where('name', 'BW PROMO')->exists());
        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Key', 'test-key') && $r->method() === 'GET');
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET'); // read-only towards ECPOS
    }

    public function test_sync_updates_archives_and_restores_but_never_touches_warehouse_or_hand_made_items(): void
    {
        $items = $this->makeItems(); // BW001, BW002 (hand made), WH001, WH002 (warehouse)

        $this->feed([$this->row('BRE-1', 'Pandesal'), $this->row('BRE-2', 'Monay')]);
        app(\App\Services\EcposCatalog::class)->sync();

        // rename + the item ECPOS stops listing is archived
        $this->feed([$this->row('BRE-1', 'Pandesal Large')]);
        $stats = app(\App\Services\EcposCatalog::class)->sync();

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $stats['archived']);
        $this->assertSame('Pandesal Large', Item::where('product_code', 'BRE-1')->value('description'));
        $this->assertNotNull(Item::where('product_code', 'BRE-2')->value('archived_at'));

        // it comes back -> restored
        $this->feed([$this->row('BRE-1', 'Pandesal Large'), $this->row('BRE-2', 'Monay')]);
        $stats = app(\App\Services\EcposCatalog::class)->sync();
        $this->assertSame(1, $stats['restored']);
        $this->assertNull(Item::where('product_code', 'BRE-2')->value('archived_at'));

        // hand-made BW items and warehouse items were never archived or changed
        foreach (['bw', 'bw2', 'wh', 'wh2'] as $k) {
            $this->assertNull($items[$k]->fresh()->archived_at, "$k stays active");
        }
        $this->assertSame('Floor Cleaner', $items['wh']->fresh()->description);
    }

    public function test_a_broken_or_empty_answer_changes_nothing(): void
    {
        $this->feed([$this->row('BRE-1', 'Pandesal')]);
        app(\App\Services\EcposCatalog::class)->sync();

        $this->feed([]);
        $this->artisan('ecpos:sync-items')->assertFailed();
        $this->assertNull(Item::where('product_code', 'BRE-1')->value('archived_at'));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => Http::response('nope', 500)]);
        $this->artisan('ecpos:sync-items')->assertFailed();
        $this->assertSame(1, Item::where('source', 'bw_products')->count());
    }

    public function test_a_dry_run_writes_nothing_and_skipped_groups_are_left_out(): void
    {
        $this->feed([$this->row('BRE-1', 'Pandesal'), $this->row('REJ-1', 'Rejected', 'BW REJECTS')]);
        config(['ecpos.skip_groups' => ['bw rejects']]);

        $this->artisan('ecpos:sync-items --dry-run')->assertSuccessful();
        $this->assertSame(0, Item::count());

        $stats = app(\App\Services\EcposCatalog::class)->sync();
        $this->assertSame(1, $stats['added']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertNull(Item::where('product_code', 'REJ-1')->first());
    }

    public function test_only_admins_can_run_the_sync_from_the_catalog_page(): void
    {
        $this->feed([$this->row('BRE-1', 'Pandesal')]);

        $this->actingAs($this->makeStoreUser($this->makeStore()))->postJson('/retails/ecpos-sync')->assertForbidden();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson('/retails/ecpos-sync')->assertOk()->assertJson(['added' => 1]);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => Http::response([], 401)]);
        $this->actingAs($admin)->postJson('/retails/ecpos-sync')->assertStatus(502)->assertJsonStructure(['message']);
    }

    public function test_merchandise_and_reject_groups_get_their_own_catalogs_and_existing_items_move_over(): void
    {
        // synced earlier as BW Products...
        $this->feed([$this->row('MER-1', 'Gift Tag', 'MERCHANDISE', 'NON PRODUCT'), $this->row('REJ-1', 'Cracked Cake', 'BW REJECTS'), $this->row('BRE-1', 'Pandesal')]);
        config(['ecpos.skip_groups' => []]);
        Item::create(['product_code' => 'MER-1', 'description' => 'Gift Tag', 'source' => 'bw_products', 'category' => 'MERCHANDISE', 'retail_group' => 'non_product'])
            ->forceFill(['synced_at' => now()])->save();

        $stats = app(\App\Services\EcposCatalog::class)->sync();

        $this->assertSame('merchandise', Item::where('product_code', 'MER-1')->value('source'));
        $this->assertSame(1, Item::where('product_code', 'MER-1')->count());
        $this->assertSame('rejects', Item::where('product_code', 'REJ-1')->value('source'));
        $this->assertSame('bw_products', Item::where('product_code', 'BRE-1')->value('source'));
        $this->assertSame([2, 1], [$stats['added'], $stats['updated']]); // REJ-1 + BRE-1 added, MER-1 moved
        foreach ([['merchandise', 'MERCHANDISE'], ['rejects', 'BW REJECTS'], ['bw_products', 'BW BREADS']] as [$src, $cat]) {
            $this->assertTrue(Category::where('source', $src)->where('name', $cat)->exists(), "$src has its category");
        }
    }}
