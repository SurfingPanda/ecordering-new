<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Services\BarcodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // every item needs a category from its own catalog
        \App\Models\Category::create(['source' => 'bw_products', 'name' => 'BW BREADS']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'source' => 'bw_products', 'product_code' => 'NEW-1', 'description' => 'New thing', 'barcode' => '2000000000017',
            'category' => 'BW BREADS', 'retail_group' => 'regular_product', ...$overrides,
        ];
    }

    /* ---- the generator ---- */

    public function test_a_generated_barcode_is_13_digits_with_a_correct_check_digit(): void
    {
        $generator = app(BarcodeGenerator::class);

        foreach (range(1, 50) as $_) {
            $code = $generator->generate();
            $this->assertMatchesRegularExpression('/^\d{13}$/', $code);
            $this->assertTrue(BarcodeGenerator::isValid($code), "$code has a wrong EAN-13 check digit");
            $this->assertStringStartsWith('2', $code, 'in-store range, so it never clashes with a manufacturer barcode');
        }
    }

    public function test_the_check_digit_matches_known_ean13_values(): void
    {
        // well-known EAN-13 examples
        $this->assertSame(1, BarcodeGenerator::checkDigit('400638133393'));   // 4006381333931
        $this->assertSame(7, BarcodeGenerator::checkDigit('590123412345'));   // 5901234123457
    }

    public function test_known_valid_and_invalid_codes_are_told_apart(): void
    {
        $this->assertTrue(BarcodeGenerator::isValid('4006381333931'));
        $this->assertTrue(BarcodeGenerator::isValid('5901234123457'));
        $this->assertFalse(BarcodeGenerator::isValid('4006381333932'), 'wrong check digit');
        $this->assertFalse(BarcodeGenerator::isValid('400638133393'), 'too short');
        $this->assertFalse(BarcodeGenerator::isValid('40063813339311'), 'too long');
        $this->assertFalse(BarcodeGenerator::isValid('40063813339A1'), 'not all digits');
    }

    public function test_a_code_already_on_an_item_is_never_returned(): void
    {
        // the first candidate collides with an existing item, so the generator must move on to the next
        $taken = '2'.'12345678901';
        $takenCode = $taken.BarcodeGenerator::checkDigit($taken);
        Item::create(['product_code' => 'T-1', 'description' => 'x', 'source' => 'bw_products', 'barcode' => $takenCode, 'retail_group' => 'regular_product']);

        $sequence = ['12345678901', '98765432109'];
        $code = app(BarcodeGenerator::class)->generate(function () use (&$sequence) {
            return array_shift($sequence);
        });

        $this->assertNotSame($takenCode, $code);
        $this->assertStringStartsWith('298765432109', $code);
        $this->assertSame([], $sequence, 'it tried the colliding one first, then the next');
    }

    public function test_it_gives_up_with_a_clear_error_if_nothing_is_free(): void
    {
        $body = '2'.'00000000000';
        Item::create(['product_code' => 'T-1', 'description' => 'x', 'source' => 'bw_products', 'barcode' => $body.BarcodeGenerator::checkDigit($body), 'retail_group' => 'regular_product']);

        $this->expectException(\RuntimeException::class);
        app(BarcodeGenerator::class)->generate(fn () => '00000000000');
    }

    /* ---- the endpoint behind the refresh button ---- */

    public function test_the_admin_gets_a_fresh_barcode_each_time(): void
    {
        $admin = $this->makeAdmin();

        $codes = collect(range(1, 5))->map(fn () => $this->actingAs($admin)->getJson('/retails/barcode')->assertOk()->json('barcode'));

        $this->assertCount(5, $codes->unique(), 'pressing refresh gives a different code');
        $codes->each(fn ($c) => $this->assertTrue(BarcodeGenerator::isValid($c)));
    }

    public function test_the_endpoint_skips_barcodes_already_in_use(): void
    {
        $admin = $this->makeAdmin();
        $code = $this->actingAs($admin)->getJson('/retails/barcode')->json('barcode');
        Item::create(['product_code' => 'T-1', 'description' => 'x', 'source' => 'bw_products', 'barcode' => $code, 'retail_group' => 'regular_product']);

        foreach (range(1, 5) as $_) {
            $this->assertNotSame($code, $this->actingAs($admin)->getJson('/retails/barcode')->json('barcode'));
        }
    }

    public function test_only_admins_can_generate_barcodes(): void
    {
        $this->actingAs($this->makeStoreUser($this->makeStore()))->getJson('/retails/barcode')->assertForbidden();

        auth()->logout();
        $this->getJson('/retails/barcode')->assertUnauthorized();
    }

    /* ---- saving an item with a barcode ---- */

    public function test_an_item_can_be_saved_with_a_generated_barcode(): void
    {
        $admin = $this->makeAdmin();
        $code = $this->actingAs($admin)->getJson('/retails/barcode')->json('barcode');

        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => $code]))->assertSessionHasNoErrors();

        $this->assertSame($code, Item::where('product_code', 'NEW-1')->value('barcode'));
    }

    public function test_the_barcode_must_be_exactly_13_digits(): void
    {
        $admin = $this->makeAdmin();

        foreach (['123', '200000000001', '20000000000123', '20000000000AB', '2000 0000 0017', '-200000000017'] as $bad) {
            $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => $bad]))
                ->assertSessionHasErrors(['barcode' => 'The barcode must be exactly 13 digits.']);
        }
        $this->assertSame(0, Item::count());
    }

    public function test_a_barcode_cannot_be_used_twice_even_by_an_archived_item(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => '2000000000017']))->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/retails', $this->payload(['product_code' => 'NEW-2', 'barcode' => '2000000000017']))
            ->assertSessionHasErrors(['barcode' => 'That barcode already belongs to another item.']);

        // still taken after the first item is archived
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [Item::where('product_code', 'NEW-1')->value('id')]]);
        $this->actingAs($admin)->post('/retails', $this->payload(['product_code' => 'NEW-3', 'barcode' => '2000000000017']))->assertSessionHasErrors('barcode');
    }

    public function test_the_barcode_is_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => null]))
            ->assertSessionHasErrors(['barcode' => 'A barcode is required. Use the arrows in the field to generate one.']);
        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => '']))->assertSessionHasErrors('barcode');
        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => '   ']))->assertSessionHasErrors('barcode');
        $this->actingAs($admin)->post('/retails', collect($this->payload())->except('barcode')->all())->assertSessionHasErrors('barcode');

        $this->assertSame(0, Item::count(), 'an item without a barcode is never created');
    }

    /* ---- the live duplicate check behind the form ---- */

    public function test_the_check_says_a_unused_barcode_is_available(): void
    {
        $this->actingAs($this->makeAdmin())->getJson('/retails/barcode/check?barcode=2000000000017')
            ->assertOk()->assertExactJson(['valid' => true, 'available' => true, 'used_by' => null]);
    }

    public function test_the_check_names_the_item_that_already_uses_a_barcode(): void
    {
        $admin = $this->makeAdmin();
        Item::create(['product_code' => 'BRE-PRO-024', 'description' => 'UBE CHEESEDESAL X8', 'source' => 'bw_products', 'barcode' => '2000000000017', 'retail_group' => 'regular_product']);

        $this->actingAs($admin)->getJson('/retails/barcode/check?barcode=2000000000017')
            ->assertOk()
            ->assertJson(['valid' => true, 'available' => false, 'used_by' => ['product_code' => 'BRE-PRO-024', 'description' => 'UBE CHEESEDESAL X8', 'source' => 'bw_products', 'archived' => false]]);
    }

    public function test_the_check_also_catches_barcodes_of_archived_items(): void
    {
        $admin = $this->makeAdmin();
        $item = Item::create(['product_code' => 'OLD-1', 'description' => 'Old', 'source' => 'warehouse', 'barcode' => '2000000000024', 'retail_group' => 'non_product']);
        $this->actingAs($admin)->post('/retails/archive', ['ids' => [$item->id]]);

        $this->actingAs($admin)->getJson('/retails/barcode/check?barcode=2000000000024')
            ->assertOk()->assertJson(['available' => false, 'used_by' => ['product_code' => 'OLD-1', 'archived' => true]]);
    }

    public function test_the_check_rejects_anything_that_is_not_13_digits(): void
    {
        $admin = $this->makeAdmin();

        foreach (['', '123', '20000000000123', '20000000000AB', ' 2000000000017 x'] as $bad) {
            $this->actingAs($admin)->getJson('/retails/barcode/check?barcode='.urlencode($bad))
                ->assertOk()->assertExactJson(['valid' => false, 'available' => false, 'used_by' => null]);
        }
        $this->actingAs($admin)->getJson('/retails/barcode/check')->assertOk()->assertJson(['valid' => false]);
    }

    public function test_a_barcode_the_check_called_available_is_what_saving_accepts_and_a_taken_one_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $free = '2000000000017';
        $this->actingAs($admin)->getJson("/retails/barcode/check?barcode=$free")->assertJson(['available' => true]);
        $this->actingAs($admin)->post('/retails', $this->payload(['barcode' => $free]))->assertSessionHasNoErrors();

        // now the same code is reported as taken, and saving another item with it is refused
        $this->actingAs($admin)->getJson("/retails/barcode/check?barcode=$free")->assertJson(['available' => false, 'used_by' => ['product_code' => 'NEW-1']]);
        $this->actingAs($admin)->post('/retails', $this->payload(['product_code' => 'NEW-2', 'barcode' => $free]))->assertSessionHasErrors('barcode');
        $this->assertSame(1, Item::where('barcode', $free)->count(), 'never two items with the same barcode');
    }

    public function test_the_database_itself_refuses_a_duplicate_barcode(): void
    {
        Item::create(['product_code' => 'A-1', 'description' => 'a', 'source' => 'bw_products', 'barcode' => '2000000000017', 'retail_group' => 'regular_product']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Item::create(['product_code' => 'A-2', 'description' => 'b', 'source' => 'warehouse', 'barcode' => '2000000000017', 'retail_group' => 'non_product']);
    }

    public function test_only_admins_can_use_the_barcode_check(): void
    {
        $this->actingAs($this->makeStoreUser($this->makeStore()))->getJson('/retails/barcode/check?barcode=2000000000017')->assertForbidden();

        auth()->logout();
        $this->getJson('/retails/barcode/check?barcode=2000000000017')->assertUnauthorized();
    }

    public function test_existing_items_keep_their_barcodes(): void
    {
        $item = Item::create(['product_code' => 'OLD-1', 'description' => 'old', 'source' => 'bw_products', 'barcode' => '8563632521238', 'retail_group' => 'regular_product']);

        // an existing 13-digit barcode that isn't a "valid EAN" is still accepted when the item is edited
        $this->actingAs($this->makeAdmin())->put("/retails/{$item->id}", $this->payload(['product_code' => 'OLD-1', 'barcode' => '8563632521238', 'description' => 'renamed']))->assertSessionHasNoErrors();

        $this->assertSame('8563632521238', $item->fresh()->barcode);
        $this->assertSame('renamed', $item->fresh()->description);
    }
}
