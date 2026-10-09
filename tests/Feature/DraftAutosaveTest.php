<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OrderingFixtures;
use Tests\TestCase;

class DraftAutosaveTest extends TestCase
{
    use OrderingFixtures, RefreshDatabase;

    public function test_quantities_are_saved_on_the_draft_and_restored_when_the_staff_comes_back(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();

        $this->actingAs($user)->putJson('/orders/draft/lines', [
            'notes' => 'early please',
            'lines' => [['item_id' => $items['bw']->id, 'quantity' => 7], ['item_id' => $items['wh']->id, 'quantity' => 3]],
        ])->assertOk();

        $props = $this->actingAs($user)->get('/orders/create')->viewData('page')['props'];
        $this->assertSame('early please', $props['saved']['notes']);
        $this->assertEqualsCanonicalizing([[$items['bw']->id, 7], [$items['wh']->id, 3]], collect($props['saved']['lines'])->map(fn ($l) => [$l['item_id'], $l['quantity']])->all());

        // changing and removing items replaces what was saved
        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 2]]])->assertOk();
        $saved = $this->actingAs($user)->get('/orders/create')->viewData('page')['props']['saved'];
        $this->assertCount(1, $saved['lines']);
        $this->assertSame(2, $saved['lines'][0]['quantity']);
    }

    public function test_a_saved_draft_is_not_an_order_and_is_used_when_placing(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();

        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 4]]])->assertOk();

        $this->actingAs($user)->get('/orders')->assertOk();
        $this->assertSame(0, Order::where('status', '!=', Order::DRAFT)->count());

        $this->actingAs($user)->post('/orders', ['order_date' => today()->toDateString(), 'lines' => [['item_id' => $items['bw']->id, 'quantity' => 4]]])->assertRedirect();
        $order = Order::where('status', 'pending')->firstOrFail();
        $this->assertSame(1, $order->lines()->count());

        // the next visit starts empty again
        $this->assertSame([], $this->actingAs($user)->get('/orders/create')->viewData('page')['props']['saved']['lines']);
    }

    public function test_autosave_validates_and_is_for_store_accounts_only(): void
    {
        $store = $this->makeStore();
        $user = $this->makeStoreUser($store);

        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => 99999, 'quantity' => 1]]])->assertStatus(422);
        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $this->makeItems()['bw']->id, 'quantity' => 0]]])->assertStatus(422);
        $this->actingAs($this->makeAdmin())->putJson('/orders/draft/lines', ['lines' => []])->assertForbidden();
    }

    public function test_starting_an_order_while_one_is_in_progress_resumes_it_instead_of_asking_again(): void
    {
        $user = $this->makeStoreUser($this->makeStore());
        $items = $this->makeItems();

        $this->actingAs($user)->postJson('/orders/draft')->assertOk()->assertJsonMissing(['resume' => true]);

        $this->actingAs($user)->putJson('/orders/draft/lines', ['lines' => [['item_id' => $items['bw']->id, 'quantity' => 2]]])->assertOk();
        $this->actingAs($user)->postJson('/orders/draft')->assertOk()->assertJson(['resume' => true]);
    }
}
