<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class EcposStoresTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    private function feed(array $rows): void
    {
        config(['ecpos.api_key' => 'test-key', 'ecpos.base_url' => 'https://ecpos.test/api/external/orders']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ecpos.test/*' => Http::response($rows, 200)]);
    }

    private function row(string $id, string $name, string $address = 'N/A', string $phone = 'N/A'): array
    {
        return ['STOREID' => $id, 'NAME' => $name, 'ADDRESS' => $address, 'PHONE' => $phone];
    }

    public function test_sync_links_existing_stores_by_name_and_adds_the_rest_without_touching_existing_data(): void
    {
        $angeles = $this->makeStore('AN01', true, 'BW Angeles');
        $angeles->update(['address' => 'My own address']);
        $fernando = $this->makeStore('SF01', true, 'BW San Fernando');

        $this->feed([
            $this->row('BW0018', 'ANGELES'),
            $this->row('BW0020', 'SAN-FERNANDO'),
            $this->row('BW0011', 'ANCHETA', '1 Main St', '0917 123 4567'),
            $this->row('BW0046', 'WM-ANTIPOLO'),
            $this->row('C00003', 'DEMO STORE 1'),
        ]);

        $this->artisan('ecpos:sync-stores')->assertSuccessful();

        // existing stores: only linked, nothing else changed
        $this->assertSame('BW0018', $angeles->fresh()->ecpos_store_id);
        $this->assertSame(['AN01', 'BW Angeles', 'My own address'], [$angeles->fresh()->code, $angeles->fresh()->name, $angeles->fresh()->address]);
        $this->assertSame('BW0020', $fernando->fresh()->ecpos_store_id);

        // new stores
        $new = Store::where('ecpos_store_id', 'BW0011')->firstOrFail();
        $this->assertSame(['BW0011', 'BW Ancheta', '1 Main St', '0917 123 4567', true], [$new->code, $new->name, $new->address, $new->contact_number, $new->is_active]);
        $this->assertSame('BW WM Antipolo', Store::where('ecpos_store_id', 'BW0046')->value('name'));

        // test stores are skipped
        $this->assertNull(Store::where('ecpos_store_id', 'C00003')->first());
        $this->assertSame(4, Store::count()); // 2 existing + 2 new, none for the demo store
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->feed([$this->row('BW0011', 'ANCHETA')]);
        $first = app(\App\Services\EcposStores::class)->sync();
        $again = app(\App\Services\EcposStores::class)->sync();

        $this->assertSame(1, $first['added']);
        $this->assertSame(['added' => 0, 'linked' => 0, 'unchanged' => 1], ['added' => $again['added'], 'linked' => $again['linked'], 'unchanged' => $again['unchanged']]);
        $this->assertSame(1, Store::count());
    }

    public function test_dry_run_and_bad_answers_change_nothing(): void
    {
        $this->feed([$this->row('BW0011', 'ANCHETA')]);
        $this->artisan('ecpos:sync-stores --dry-run')->assertSuccessful();
        $this->assertSame(0, Store::count());

        $this->feed([]);
        $this->artisan('ecpos:sync-stores')->assertFailed();
    }

    public function test_only_admins_can_sync_and_the_ecpos_id_can_be_edited_but_not_shared(): void
    {
        $this->feed([$this->row('BW0011', 'ANCHETA')]);
        $this->actingAs($this->makeStoreUser($this->makeStore()))->postJson('/admin/stores/ecpos-sync')->assertForbidden();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson('/admin/stores/ecpos-sync')->assertOk()->assertJson(['added' => 1]);

        $a = $this->makeStore('AA01', true, 'Store A');
        $b = $this->makeStore('BB01', true, 'Store B');
        $this->actingAs($admin)->put("/admin/stores/{$a->id}", ['name' => 'Store A', 'code' => 'AA01', 'is_active' => true, 'ecpos_store_id' => 'bw0099'])->assertSessionHasNoErrors();
        $this->assertSame('BW0099', $a->fresh()->ecpos_store_id);

        $this->actingAs($admin)->put("/admin/stores/{$b->id}", ['name' => 'Store B', 'code' => 'BB01', 'is_active' => true, 'ecpos_store_id' => 'BW0099'])->assertSessionHasErrors('ecpos_store_id');
        $this->actingAs($admin)->put("/admin/stores/{$a->id}", ['name' => 'Store A', 'code' => 'AA01', 'is_active' => true, 'ecpos_store_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->ecpos_store_id);
    }
}
