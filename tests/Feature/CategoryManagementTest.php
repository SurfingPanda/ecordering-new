<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private int $barcodeSeq = 0;

    private function item(array $overrides = []): array
    {
        return [
            'source' => 'bw_products', 'product_code' => 'NEW-1', 'description' => 'New thing',
            'barcode' => (string) (2000000000000 + ++$this->barcodeSeq), // a different barcode on every call
            'category' => 'BW BREADS', 'retail_group' => 'regular_product', ...$overrides,
        ];
    }

    public function test_an_admin_can_add_categories_to_each_catalog(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => 'bw breads'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'warehouse', 'name' => 'Toppers '])->assertSessionHasNoErrors();

        // names are stored trimmed and upper-case, like the existing ones
        $this->assertSame(['BW BREADS'], Category::where('source', 'bw_products')->pluck('name')->all());
        $this->assertSame(['TOPPERS'], Category::where('source', 'warehouse')->pluck('name')->all());
    }

    public function test_the_same_name_can_exist_in_both_catalogs_but_not_twice_in_one(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => 'PACKAGING'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/categories', ['source' => 'warehouse', 'name' => 'PACKAGING'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => 'packaging'])->assertSessionHasErrors('name');

        $this->assertSame(2, Category::where('name', 'PACKAGING')->count());
    }

    public function test_category_input_is_validated(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'bw_products', 'name' => str_repeat('x', 101)])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/admin/categories', ['source' => 'moon', 'name' => 'OK'])->assertSessionHasErrors('source');
        $this->actingAs($admin)->post('/admin/categories', ['name' => 'OK'])->assertSessionHasErrors('source');

        $this->assertSame(0, Category::count());
    }

    public function test_store_accounts_cannot_manage_categories(): void
    {
        $cat = Category::create(['source' => 'warehouse', 'name' => 'TOPPERS']);
        $user = $this->makeStoreUser($this->makeStore());

        $this->actingAs($user)->get('/admin/categories')->assertForbidden();
        $this->actingAs($user)->post('/admin/categories', ['source' => 'warehouse', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->put("/admin/categories/{$cat->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($user)->delete("/admin/categories/{$cat->id}")->assertForbidden();

        $this->assertSame(['TOPPERS'], Category::pluck('name')->all());
    }

    public function test_an_item_can_only_use_a_category_from_its_own_catalog(): void
    {
        $admin = $this->makeAdmin();
        Category::create(['source' => 'bw_products', 'name' => 'BW BREADS']);
        Category::create(['source' => 'warehouse', 'name' => 'TOPPERS']);

        // right catalog -> accepted
        $this->actingAs($admin)->post('/retails', $this->item())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/retails', $this->item(['source' => 'warehouse', 'product_code' => 'W-1', 'category' => 'TOPPERS', 'retail_group' => 'non_product']))->assertSessionHasNoErrors();

        // a Warehouse category on a BW item, and vice versa -> rejected
        $this->actingAs($admin)->post('/retails', $this->item(['product_code' => 'X-1', 'category' => 'TOPPERS']))->assertSessionHasErrors('category');
        $this->actingAs($admin)->post('/retails', $this->item(['source' => 'warehouse', 'product_code' => 'X-2', 'category' => 'BW BREADS', 'retail_group' => 'non_product']))->assertSessionHasErrors('category');
        // a category that doesn't exist at all
        $this->actingAs($admin)->post('/retails', $this->item(['product_code' => 'X-3', 'category' => 'MADE UP']))->assertSessionHasErrors('category');

        $this->assertSame(['NEW-1', 'W-1'], Item::orderBy('product_code')->pluck('product_code')->all());
    }

    public function test_the_category_is_required(): void
    {
        Category::create(['source' => 'bw_products', 'name' => 'BW BREADS']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/retails', $this->item(['category' => null]))->assertSessionHasErrors(['category' => 'Please choose a category.']);
        $this->actingAs($admin)->post('/retails', $this->item(['category' => '']))->assertSessionHasErrors('category');
        $this->actingAs($admin)->post('/retails', collect($this->item())->except('category')->all())->assertSessionHasErrors('category');

        $this->assertSame(0, Item::count(), 'an item without a category is never created');
    }

    public function test_the_item_form_gets_categories_grouped_by_catalog(): void
    {
        Category::create(['source' => 'bw_products', 'name' => 'BW BREADS']);
        Category::create(['source' => 'bw_products', 'name' => 'BW CAKES']);
        Category::create(['source' => 'warehouse', 'name' => 'TOPPERS']);

        $props = $this->actingAs($this->makeAdmin())->get('/retails')->viewData('page')['props'];

        $this->assertSame(['BW BREADS', 'BW CAKES'], $props['categories']['bw_products']);
        $this->assertSame(['TOPPERS'], $props['categories']['warehouse']);

        // both keys exist even when a catalog has none yet
        Category::where('source', 'warehouse')->delete();
        $props = $this->actingAs($this->makeAdmin('b@test.test'))->get('/retails')->viewData('page')['props'];
        $this->assertSame([], $props['categories']['warehouse']);
    }

    public function test_the_categories_page_lists_them_with_item_counts(): void
    {
        $this->makeItems(); // BW001 CAKES, BW002 BREADS, WH001 CLEANING, WH002 TOPPERS (as item text)
        Category::create(['source' => 'bw_products', 'name' => 'CAKES']);
        Category::create(['source' => 'bw_products', 'name' => 'UNUSED']);
        Category::create(['source' => 'warehouse', 'name' => 'CAKES']); // same wording, other catalog: no items

        $props = $this->actingAs($this->makeAdmin())->get('/admin/categories')->viewData('page')['props']['categories'];

        $bw = collect($props['bw_products'])->keyBy('name');
        $this->assertSame(1, $bw['CAKES']['items_count']);
        $this->assertSame(0, $bw['UNUSED']['items_count']);
        $this->assertSame(0, collect($props['warehouse'])->firstWhere('name', 'CAKES')['items_count'], 'counted per catalog');
    }

    public function test_renaming_moves_the_items_but_not_past_order_lines(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems();
        $cat = Category::create(['source' => 'bw_products', 'name' => 'CAKES']);
        $other = Category::create(['source' => 'warehouse', 'name' => 'CAKES']);
        $store = $this->makeStore();
        $order = $this->seedOrder($store, $this->makeStoreUser($store), 'posted', null, [[$items['bw'], 3]]);

        $this->actingAs($admin)->put("/admin/categories/{$cat->id}", ['name' => 'round cakes'])->assertSessionHasNoErrors();

        $this->assertSame('ROUND CAKES', $cat->fresh()->name);
        $this->assertSame('ROUND CAKES', $items['bw']->fresh()->category, 'the item follows the rename');
        $this->assertSame('CAKES', OrderItem::where('order_id', $order->id)->value('category'), 'order history is not rewritten');
        $this->assertSame('CAKES', $other->fresh()->name, 'the other catalog is untouched');
    }

    public function test_renaming_cannot_collide_or_change_catalog(): void
    {
        $admin = $this->makeAdmin();
        Category::create(['source' => 'bw_products', 'name' => 'BREADS']);
        $cakes = Category::create(['source' => 'bw_products', 'name' => 'CAKES']);

        $this->actingAs($admin)->put("/admin/categories/{$cakes->id}", ['name' => 'breads'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->put("/admin/categories/{$cakes->id}", ['name' => ''])->assertSessionHasErrors('name');

        $this->actingAs($admin)->put("/admin/categories/{$cakes->id}", ['name' => 'CAKES AND PIES', 'source' => 'warehouse'])->assertSessionHasNoErrors();
        $this->assertSame('bw_products', $cakes->fresh()->source, 'a rename never moves a category between catalogs');
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $unused = Category::create(['source' => 'bw_products', 'name' => 'UNUSED']);

        $this->actingAs($this->makeAdmin())->delete("/admin/categories/{$unused->id}")->assertSessionHasNoErrors()->assertSessionHas('success', 'UNUSED was deleted.');
        $this->assertFalse(Category::whereKey($unused->id)->exists());
    }

    public function test_a_category_in_use_can_be_deleted_and_its_items_become_uncategorized(): void
    {
        $admin = $this->makeAdmin();
        $items = $this->makeItems(); // BW001 CAKES, BW002 BREADS (bw) / WH001 CLEANING, WH002 TOPPERS (warehouse)
        $used = Category::create(['source' => 'bw_products', 'name' => 'CAKES']);
        $otherCatalog = Category::create(['source' => 'warehouse', 'name' => 'CAKES']);
        Item::create(['product_code' => 'WH-CAKES', 'description' => 'Warehouse cake thing', 'source' => 'warehouse', 'category' => 'CAKES', 'retail_group' => 'non_product']);
        $store = $this->makeStore();
        $order = $this->seedOrder($store, $this->makeStoreUser($store), 'posted', null, [[$items['bw'], 4]]);

        $this->actingAs($admin)->delete("/admin/categories/{$used->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'CAKES was deleted. 1 item is now uncategorized.');

        $this->assertFalse(Category::whereKey($used->id)->exists());
        $this->assertNull($items['bw']->fresh()->category, 'the item is kept, just uncategorized');
        $this->assertSame('BREADS', $items['bw2']->fresh()->category, 'unrelated items are untouched');

        // the other catalog's category with the same wording, and its item, are untouched
        $this->assertTrue(Category::whereKey($otherCatalog->id)->exists());
        $this->assertSame('CAKES', Item::where('product_code', 'WH-CAKES')->value('category'));

        // order history keeps the category it was placed with
        $this->assertSame('CAKES', OrderItem::where('order_id', $order->id)->value('category'));
        $this->assertSame(1, $order->lines()->count());
    }

    public function test_store_accounts_still_cannot_delete_a_category(): void
    {
        $cat = Category::create(['source' => 'warehouse', 'name' => 'TOPPERS']);

        $this->actingAs($this->makeStoreUser($this->makeStore()))->delete("/admin/categories/{$cat->id}")->assertForbidden();
        $this->assertTrue(Category::whereKey($cat->id)->exists());
    }
}
