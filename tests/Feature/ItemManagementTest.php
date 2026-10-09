<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private array $valid = [
        'source' => 'warehouse', 'product_code' => 'NEW-001', 'description' => 'Brand new item',
        'barcode' => '2000000000017', 'category' => 'TOPPERS', 'retail_group' => 'non_product',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // the category an item uses must exist for its catalog
        Category::create(['source' => 'warehouse', 'name' => 'TOPPERS']);
        $this->at('2026-10-09 10:00');
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function codes($response, string $key = 'items'): array
    {
        return collect($response->viewData('page')['props'][$key]['data'])->pluck('product_code')->sort()->values()->all();
    }

    /* ---- adding ---- */

    public function test_an_admin_can_add_an_item(): void
    {
        $this->actingAs($this->makeAdmin())->post('/retails', $this->valid)->assertRedirect('/retails?source=warehouse');

        $item = Item::where('product_code', 'NEW-001')->firstOrFail();
        $this->assertSame('warehouse', $item->source);
        $this->assertNull($item->archived_at, 'new items start active');
    }

    public function test_a_new_item_shows_up_on_a_stores_order_sheet(): void
    {
        $this->makeItems();
        $this->actingAs($this->makeAdmin())->post('/retails', $this->valid);
        $user = $this->makeStoreUser($this->makeStore());

        $codes = collect($this->actingAs($user)->get('/orders/create')->viewData('page')['props']['items'])->pluck('product_code');
        $this->assertTrue($codes->contains('NEW-001'));
    }

    public function test_item_fields_are_validated_and_codes_must_be_unique(): void
    {
        $admin = $this->makeAdmin();
        $this->makeItems(); // BW001 exists

        $this->actingAs($admin)->post('/retails', [...$this->valid, 'product_code' => 'BW001'])->assertSessionHasErrors('product_code');
        $this->actingAs($admin)->post('/retails', [...$this->valid, 'product_code' => ''])->assertSessionHasErrors('product_code');
        $this->actingAs($admin)->post('/retails', [...$this->valid, 'description' => ''])->assertSessionHasErrors('description');
        $this->actingAs($admin)->post('/retails', [...$this->valid, 'source' => 'moon'])->assertSessionHasErrors('source');
        $this->actingAs($admin)->post('/retails', [...$this->valid, 'retail_group' => 'other'])->assertSessionHasErrors('retail_group');

        $this->assertSame(0, Item::where('product_code', 'NEW-001')->count());
    }

    public function test_every_field_on_the_form_is_required(): void
    {
        $admin = $this->makeAdmin();

        // sanity: the full form is accepted...
        $this->actingAs($admin)->post('/retails', [...$this->valid, 'product_code' => 'FULL-1', 'barcode' => '2000000000024'])->assertSessionHasNoErrors();

        // ...and leaving out any one field is refused, with that field named in the error
        foreach (['source', 'product_code', 'description', 'barcode', 'category', 'retail_group'] as $field) {
            foreach ([null, ''] as $blank) {
                $this->actingAs($admin)->post('/retails', [...$this->valid, 'product_code' => 'REQ-'.$field, 'barcode' => '2000000000031', $field => $blank])
                    ->assertSessionHasErrors($field);
            }
            $payload = [...$this->valid, 'product_code' => 'REQ-'.$field, 'barcode' => '2000000000031'];
            unset($payload[$field]);
            $this->actingAs($admin)->post('/retails', $payload)->assertSessionHasErrors($field);
        }

        $this->assertSame(['FULL-1'], Item::pluck('product_code')->all(), 'no incomplete item was saved');
    }

    public function test_an_archived_items_code_stays_reserved(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]]);

        $this->actingAs($admin)->post('/retails', [...$this->valid, 'product_code' => 'BW001'])->assertSessionHasErrors('product_code');
    }

    /* ---- there is no delete ---- */

    public function test_items_cannot_be_deleted_by_anyone(): void
    {
        $items = $this->makeItems();
        $admin = $this->makeAdmin();
        $user = $this->makeStoreUser($this->makeStore());

        foreach ([$admin, $user] as $who) {
            $this->actingAs($who)->delete('/retails', ['ids' => [$items['bw']->id]])->assertStatus(405);
            $this->actingAs($who)->delete("/retails/{$items['bw']->id}")->assertStatus(405);
        }

        $this->assertSame(4, Item::count());
    }

    /* ---- archive / restore ---- */

    public function test_an_admin_can_archive_items_without_deleting_them(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $store = $this->makeStore();
        $order = $this->seedOrder($store, $this->makeStoreUser($store), 'posted', null, [[$items['bw'], 5], [$items['wh'], 2]]);

        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id, $items['bw2']->id]])
            ->assertRedirect()->assertSessionHas('success', '2 items archived.');

        // still in the table, just flagged
        $this->assertSame(4, Item::count());
        $this->assertNotNull($items['bw']->fresh()->archived_at);
        $this->assertNull($items['wh']->fresh()->archived_at, 'items that were not selected stay active');

        // the past order is untouched
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame('Ube Cake', OrderItem::where('order_id', $order->id)->where('product_code', 'BW001')->value('name'));
    }

    public function test_archived_items_leave_the_catalog_and_appear_on_the_archived_page(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]]);

        $this->assertSame(['BW002'], $this->codes($this->actingAs($admin)->get('/retails?source=bw_products')));

        $archived = $this->actingAs($admin)->get('/admin/archived-products?source=bw_products');
        $this->assertSame(['BW001'], $this->codes($archived));
        $props = $archived->viewData('page')['props'];
        $this->assertSame('archived', $props['view']);
        $this->assertSame('/admin/archived-products', $props['basePath']);
        $this->assertSame(['bw_products' => 1, 'warehouse' => 0, 'merchandise' => 0, 'rejects' => 0], collect($props['counts'])->all());
        $this->assertNotNull($props['items']['data'][0]['archived_at']);

        // the other catalog's archived list is separate
        $this->assertSame([], $this->codes($this->actingAs($admin)->get('/admin/archived-products?source=warehouse')));
    }

    public function test_the_archived_page_supports_search_and_sorting(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id, $items['bw2']->id]]);

        $this->assertSame(['BW002'], $this->codes($this->actingAs($admin)->get('/admin/archived-products?search=pandesal')));
        $this->actingAs($admin)->get('/admin/archived-products?sort=archived_at&dir=asc')->assertOk();
        $this->actingAs($admin)->get('/retails?sort=archived_at')->assertOk(); // not a sort for the live catalog: ignored
    }

    public function test_archived_items_cannot_be_ordered_or_seen_by_stores(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $user = $this->makeStoreUser($this->makeStore());
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]]);

        // not on the order sheet or in the store's catalog
        $sheet = collect($this->actingAs($user)->get('/orders/create')->viewData('page')['props']['items'])->pluck('product_code');
        $this->assertFalse($sheet->contains('BW001'));
        $this->assertTrue($sheet->contains('BW002'));
        $this->assertSame(['BW002'], $this->codes($this->actingAs($user)->get('/retails?source=bw_products')));

        // and the server refuses it even if the request is crafted by hand
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw2'], 1], [$items['bw'], 4]]))->assertSessionHasErrors('lines.1.item_id');
        $this->assertSame(0, Order::where('status', '!=', 'draft')->count());

        // active items still work
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw2'], 1]]))->assertSessionHasNoErrors();
        $this->assertSame(1, Order::where('status', 'pending')->count());
    }

    public function test_archived_items_are_left_out_of_the_not_ordered_report(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['wh2']->id]]);

        $codes = collect($this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['not_ordered'])->pluck('product_code');
        $this->assertFalse($codes->contains('WH002'));
        $this->assertTrue($codes->contains('WH001'));
    }

    public function test_an_admin_can_restore_archived_items(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $user = $this->makeStoreUser($this->makeStore());
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id, $items['wh']->id]]);

        $this->actingAs($admin)->post('/retails/restore', ['ids' => [$items['bw']->id]])
            ->assertRedirect('/admin/archived-products?source=bw_products')
            ->assertSessionHas('success', '1 item restored.');

        $this->assertNull($items['bw']->fresh()->archived_at);
        $this->assertNotNull($items['wh']->fresh()->archived_at, 'only the selected item is restored');

        $this->assertContains('BW001', collect($this->actingAs($user)->get('/orders/create')->viewData('page')['props']['items'])->pluck('product_code')->all());
        $this->actingAs($user)->post('/orders', $this->payload([[$items['bw'], 3]]))->assertSessionHasNoErrors();
    }

    public function test_archiving_and_restoring_only_touch_the_right_state(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]]);
        $stamp = $items['bw']->fresh()->archived_at;

        // archiving an already-archived item changes nothing (the date is kept)
        $this->at('2026-10-10 10:00');
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['bw']->id]])->assertSessionHas('success', '0 items archived.');
        $this->assertEquals($stamp, $items['bw']->fresh()->archived_at);

        // restoring an item that isn't archived is a no-op too
        $this->actingAs($admin)->post('/retails/restore', ['ids' => [$items['wh']->id]])->assertSessionHas('success', '0 items restored.');
        $this->assertNull($items['wh']->fresh()->archived_at);
    }

    public function test_archive_and_restore_need_a_selection(): void
    {
        $admin = $this->makeAdmin();
        $this->makeItems();

        foreach (['/retails/archive', '/retails/restore'] as $url) {
            $this->actingAs($admin)->post($url, [])->assertSessionHasErrors('ids');
            $this->actingAs($admin)->post($url, ['ids' => []])->assertSessionHasErrors('ids');
            $this->actingAs($admin)->post($url, ['ids' => ['abc']])->assertSessionHasErrors('ids.0');
        }
    }

    public function test_store_accounts_cannot_add_archive_restore_or_open_the_archive(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $user = $this->makeStoreUser($this->makeStore());
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$items['wh']->id]]);

        $this->actingAs($user)->post('/retails', $this->valid)->assertForbidden();
        $this->actingAs($user)->post('/retails/archive', ['ids' => [$items['bw']->id]])->assertForbidden();
        $this->actingAs($user)->post('/retails/restore', ['ids' => [$items['wh']->id]])->assertForbidden();
        $this->actingAs($user)->get('/admin/archived-products')->assertForbidden();

        $this->assertNull($items['bw']->fresh()->archived_at);
        $this->assertNotNull($items['wh']->fresh()->archived_at);
        $this->assertFalse(Item::where('product_code', 'NEW-001')->exists());
    }

    public function test_a_duplicate_description_is_rejected_within_a_catalog_but_allowed_in_the_other(): void
    {
        $admin = $this->makeAdmin();
        Category::create(['source' => 'bw_products', 'name' => 'CAKES']);
        $this->actingAs($admin)->post('/retails', $this->valid)->assertSessionHasNoErrors();

        // same catalog, even with different case / surrounding spaces
        $this->actingAs($admin)->post('/retails', ['product_code' => 'NEW-002', 'barcode' => '2000000000024', 'description' => '  Brand new item '] + $this->valid)
            ->assertSessionHasErrors('description');

        // the other catalog may reuse the wording
        $this->actingAs($admin)->post('/retails', ['source' => 'bw_products', 'category' => 'CAKES', 'retail_group' => 'regular_product', 'product_code' => 'NEW-003', 'barcode' => '2000000000031'] + $this->valid)
            ->assertSessionHasNoErrors();
    }

    public function test_the_form_can_check_for_duplicates_as_you_type(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/retails', $this->valid)->assertSessionHasNoErrors();

        $check = fn (array $q) => $this->actingAs($admin)->getJson('/retails/duplicate/check?'.http_build_query($q));

        $check(['field' => 'product_code', 'value' => 'NEW-001'])->assertOk()->assertJson(['taken' => true, 'used_by' => ['product_code' => 'NEW-001']]);
        $check(['field' => 'product_code', 'value' => 'FREE-1'])->assertOk()->assertJson(['taken' => false]);
        $check(['field' => 'description', 'value' => 'Brand new item', 'source' => 'warehouse'])->assertJson(['taken' => true]);
        $check(['field' => 'description', 'value' => 'Brand new item', 'source' => 'bw_products'])->assertJson(['taken' => false]);
        $check(['field' => 'barcode', 'value' => 'x'])->assertStatus(422);

        $this->actingAs($this->makeStoreUser($this->makeStore()))->getJson('/retails/duplicate/check?field=product_code&value=x')->assertForbidden();
    }
    public function test_the_catalog_refresh_snapshot_lists_active_items_for_every_role(): void
    {
        $items = $this->makeItems();
        $items['bw2']->forceFill(['archived_at' => now()])->save();

        foreach ([$this->makeAdmin(), $this->makeStoreUser($this->makeStore())] as $user) {
            $ids = collect($this->actingAs($user)->getJson('/retails/snapshot')->assertOk()->json('items'))->pluck('id')->all();
            $this->assertEqualsCanonicalizing([$items['bw']->id, $items['wh']->id, $items['wh2']->id], $ids);
        }
    }
}
