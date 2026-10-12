<?php

namespace Tests\Feature;

use App\Models\CatalogRule;
use App\Models\Category;
use App\Models\Item;
use App\Services\CatalogRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class SyncRulesTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function synced(string $code, string $category, string $source = 'bw_products'): Item
    {
        $item = Item::create(['product_code' => $code, 'description' => 'Item '.$code, 'source' => $source, 'category' => $category, 'retail_group' => 'non_product']);
        $item->forceFill(['synced_at' => now()])->save();

        return $item;
    }

    public function test_the_rules_the_sync_always_used_are_there_to_start_with(): void
    {
        $r = new CatalogRouter;

        $this->assertSame('warehouse', $r->sourceFor('ACC-SUP-086', 'BW PREM CAKES')); // by code
        $this->assertSame('warehouse', $r->sourceFor('x-sup-1', null));                  // capitals don't matter
        $this->assertSame('merchandise', $r->sourceFor('MER-1', 'merchandise'));         // by group
        $this->assertSame('rejects', $r->sourceFor('REJ-1', ' BW REJECTS '));
        $this->assertSame('warehouse', $r->sourceFor('BOX-1', 'STORE SUPPLIES'));
        $this->assertSame('bw_products', $r->sourceFor('BRE-1', 'BW BREADS'));           // nothing matches
        $this->assertSame('warehouse', $r->sourceFor('MER-SUP-1', 'MERCHANDISE'));        // a code rule beats a group rule
    }

    public function test_a_new_rule_changes_where_the_sync_files_items_without_any_code_change(): void
    {
        CatalogRule::create(['type' => 'group', 'match' => 'BW PREM CAKES', 'source' => 'merchandise']);

        $this->assertSame('merchandise', (new CatalogRouter)->sourceFor('CAK-1', 'bw prem cakes'));

        CatalogRule::where('match', '-SUP-')->delete();
        $this->assertSame('bw_products', (new CatalogRouter)->sourceFor('ACC-SUP-086', 'BW PREM CAKES'.'X'));
    }

    public function test_the_sync_uses_the_rules(): void
    {
        config(['ecpos.skip_groups' => [], 'ecpos.api_key' => 'k']);
        CatalogRule::create(['type' => 'group', 'match' => 'GIFT BOXES', 'source' => 'merchandise']);

        $stats = app(\App\Services\EcposCatalog::class)->sync(false, [
            ['itemid' => 'GB-1', 'itemname' => 'Gift Box', 'itemgroup' => 'GIFT BOXES', 'itemdepartment' => 'NON PRODUCT'],
            ['itemid' => 'ACC-SUP-9', 'itemname' => 'Tape', 'itemgroup' => 'BW PREM CAKES', 'itemdepartment' => 'NON PRODUCT'],
            ['itemid' => 'BR-1', 'itemname' => 'Pandesal', 'itemgroup' => 'BW BREADS', 'itemdepartment' => 'REGULAR PRODUCT'],
        ]);

        $this->assertSame(3, $stats['added']);
        $this->assertSame('merchandise', Item::where('product_code', 'GB-1')->value('source'));
        $this->assertSame('warehouse', Item::where('product_code', 'ACC-SUP-9')->value('source'));
        $this->assertSame('bw_products', Item::where('product_code', 'BR-1')->value('source'));
        $this->assertTrue(Category::where('source', 'merchandise')->where('name', 'GIFT BOXES')->exists());
    }

    public function test_admins_manage_rules_and_stores_cannot(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/admin/sync-rules')->assertOk()->assertInertia(fn ($p) => $p->has('rules', 4));

        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'group', 'match' => ' toppers ', 'source' => 'warehouse'])->assertSessionHasNoErrors();
        $rule = CatalogRule::where('match', 'TOPPERS')->firstOrFail(); // stored in capitals
        $this->assertSame('warehouse', $rule->source);

        $this->actingAs($admin)->put("/admin/sync-rules/{$rule->id}", ['type' => 'group', 'match' => 'TOPPERS', 'source' => 'rejects'])->assertSessionHasNoErrors();
        $this->assertSame('rejects', $rule->fresh()->source);

        $this->actingAs($admin)->delete("/admin/sync-rules/{$rule->id}")->assertSessionHasNoErrors();
        $this->assertNull(CatalogRule::find($rule->id));

        $store = $this->makeStoreUser($this->makeStore('AA01'));
        $this->actingAs($store)->get('/admin/sync-rules')->assertForbidden();
        $this->actingAs($store)->post('/admin/sync-rules', ['type' => 'group', 'match' => 'ABC', 'source' => 'warehouse'])->assertForbidden();
        $this->actingAs($store)->post('/admin/sync-rules/apply')->assertForbidden();
    }

    public function test_rules_are_validated(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'code_contains', 'match' => '-', 'source' => 'warehouse'])->assertSessionHasErrors('match'); // would match everything
        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'code_contains', 'match' => '-sup-', 'source' => 'warehouse'])->assertSessionHasErrors('match'); // already exists
        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'nonsense', 'match' => 'ABC', 'source' => 'warehouse'])->assertSessionHasErrors('type');
        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'group', 'match' => 'ABC', 'source' => 'nowhere'])->assertSessionHasErrors('source');
        $this->actingAs($admin)->post('/admin/sync-rules', ['type' => 'group', 'match' => '-SUP-', 'source' => 'rejects'])->assertSessionHasNoErrors(); // same text is fine under another type
    }

    public function test_move_them_now_files_the_items_from_ecpos_and_leaves_hand_made_ones_alone(): void
    {
        $admin = $this->makeAdmin();
        $in_bw = $this->synced('ACC-SUP-1', 'BW PREM CAKES');
        $ok = $this->synced('BRE-1', 'BW BREADS');
        $hand = Item::create(['product_code' => 'HAND-SUP-1', 'description' => 'By hand', 'source' => 'bw_products', 'category' => 'X', 'retail_group' => 'non_product']); // never synced
        $archived = $this->synced('OLD-SUP-1', 'X');
        $archived->forceFill(['archived_at' => now()])->save();

        $page = $this->actingAs($admin)->get('/admin/sync-rules')->viewData('page')['props'];
        $this->assertSame(1, $page['misfiled']);
        $this->assertSame('warehouse', $page['misfiled_sample'][0]['to']);

        $this->actingAs($admin)->post('/admin/sync-rules/apply')->assertSessionHasNoErrors();

        $this->assertSame('warehouse', $in_bw->fresh()->source);
        $this->assertTrue(Category::where('source', 'warehouse')->where('name', 'BW PREM CAKES')->exists());
        $this->assertSame('bw_products', $ok->fresh()->source);
        $this->assertSame('bw_products', $hand->fresh()->source);
        $this->assertSame('bw_products', $archived->fresh()->source);

        $this->assertSame(0, $this->actingAs($admin)->get('/admin/sync-rules')->viewData('page')['props']['misfiled']);
    }
}
