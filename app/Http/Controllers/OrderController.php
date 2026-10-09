<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\SubmissionDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(private readonly SubmissionDeadline $deadlines) {}

    /* ------------------------------------------------------------------ list / detail */

    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = in_array($request->query('status'), Order::STATUSES, true) ? $request->query('status') : null;
        $catalog = in_array($request->query('catalog'), Item::SOURCES, true) ? $request->query('catalog') : null;
        $storeId = $user->isAdmin() && $request->query('store') ? (int) $request->query('store') : null;
        $search = trim((string) $request->query('search', ''));

        $orders = Order::with(['user:id,name', 'store:id,code,name'])
            ->visibleTo($user)
            ->where('status', '!=', Order::DRAFT)
            ->withUnits()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($catalog, fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('source', $catalog)))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
                $q->orWhere('tr_number', 'like', "%{$search}%"); // "TR-BW0018-000001", "BW0018" or "000001"
            }))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Order $o) => $this->summary($o));

        return Inertia::render('orders/index', [
            'orders' => $orders,
            'status' => $status,
            'catalog' => $catalog,
            'store' => $storeId,
            'search' => $search,
            'stores' => $user->isAdmin() ? Store::orderBy('name')->get(['id', 'code', 'name']) : [],
        ]);
    }

    public function show(Request $request, Order $order): Response|RedirectResponse
    {
        if ($order->status === Order::DRAFT) {
            return $request->user()->isStore() ? to_route('orders.create') : abort(404);
        }

        $order = Order::withUnits()
            ->with(['lines', 'user:id,name', 'store:id,code,name', 'cancelledBy:id,name', 'events.user:id,name'])
            ->findOrFail($order->id);

        $user = $request->user();

        return Inertia::render('orders/show', [
            'order' => [
                ...$this->summary($order),
                'notes' => $order->notes,
                'posted_at' => $order->posted_at?->toIso8601String(),
                'ecpos' => $order->ecpos_sent_at ? [
                    'journal_id' => $order->ecpos_journal_id,
                    'sent_at' => $order->ecpos_sent_at->toIso8601String(),
                    'note' => $order->ecpos_note,
                ] : null,
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'cancelled_by' => $order->cancelledBy?->name,
                'cancellation_reason' => $order->cancellation_reason,
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_code' => $l->product_code,
                    'name' => $l->name,
                    'source' => $l->source,
                    'category' => $l->category,
                    'quantity' => $l->quantity,
                ]),
                'events' => $order->events->map(fn ($e) => [
                    'id' => $e->id,
                    'event' => $e->event,
                    'note' => $e->note,
                    'by' => $e->user?->name,
                    'at' => $e->created_at->toIso8601String(),
                ]),
            ],
            // what the signed-in user may do with this order (the server re-checks on every action)
            'can' => [
                'post' => $user->isStore() && $order->status === 'pending',
                'reset' => $user->isStore() && $order->status === 'pending',
                'cancel' => $user->isAdmin() && $order->status === 'posted',
                'cancel_needs_reason' => true,
                'send_ecpos' => $order->status === 'posted' && ! $order->ecpos_sent_at && app(\App\Services\EcposOrders::class)->enabled(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ draft (reserves the TR number) */

    /**
     * The store user's open draft, created on demand. The draft is what gives a new order its TR number
     * before any items are entered; reusing it means abandoned "New order" clicks don't burn numbers.
     */
    private function draftFor(User $user): Order
    {
        return Order::firstOrCreate(
            ['user_id' => $user->id, 'store_id' => $user->store_id, 'status' => Order::DRAFT],
            ['order_date' => today()->toDateString()],
        );
    }

    /**
     * Opening the "New order" prompt reserves (or returns) the draft and starts the date at today.
     * Refuses up front when the store can't order right now so the prompt can explain why.
     */
    public function draft(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertStoreCanOrder($user);

        // the date the prompt starts on: today, unless the store asked for another one ("order for another date")
        $date = $request->validate(['date' => ['sometimes', ...$this->dateWindow()]])['date'] ?? today()->toDateString();

        // A store can only have one TR per day. If it already has one for this date, say so right away
        // (nothing is reserved), so the prompt can tell the user instead of letting them fill in a form.
        if ($existing = $this->existingOrderOn($user->store, $date)) {
            return response()->json([
                'error' => 'already_ordered',
                'message' => $this->alreadyOrderedMessage($user->store, $existing, $date),
                'order' => [
                    'id' => $existing->id,
                    'number' => $existing->number(),
                    'status' => $existing->status,
                    'order_date' => $existing->order_date->toDateString(),
                    'url' => route('orders.show', $existing),
                ],
            ], 409);
        }

        $draft = $this->draftFor($user);

        // an order already in progress (items entered): carry on with it, don't ask for a date again
        if (! $request->has('date') && $draft->lines()->exists()) {
            return response()->json(['resume' => true, 'id' => $draft->id, 'number' => $draft->number(), 'order_date' => $draft->order_date->toDateString()]);
        }

        $draft->update(['order_date' => $date]);

        return response()->json([
            'id' => $draft->id,
            'number' => $draft->number(),
            'order_date' => $draft->order_date->toDateString(),
        ]);
    }

    /** Saves the date chosen in the prompt, then opens the order sheet. */
    public function updateDraft(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->assertStoreCanOrder($user);

        $data = $request->validate(['order_date' => $this->dateRules()]);
        $draft = $this->draftFor($user);
        $this->assertDateIsFree($user->store, $data['order_date'], $draft);
        $draft->update($data);

        return to_route('orders.create');
    }

    /**
     * "Copy last order": the store's most recent placed order (any status except a draft) as item quantities, so the
     * order sheet can be filled in one tap. Items that are no longer on the catalog are counted, not copied.
     */
    public function last(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isStore() && $user->store_id, 403, 'Only store accounts can place orders.');

        $order = Order::where('store_id', $user->store_id)->where('status', '!=', Order::DRAFT)->with('lines')->orderByDesc('id')->first();
        if (! $order) {
            return response()->json(['order' => null]);
        }

        $active = Item::active()->whereIn('id', $order->lines->pluck('item_id')->filter())->pluck('id');
        $lines = $order->lines->filter(fn ($l) => $active->contains($l->item_id));

        return response()->json(['order' => [
            'number' => $order->number(),
            'order_date' => $order->order_date->toDateString(),
            'status' => $order->status,
            'lines' => $lines->map(fn ($l) => ['item_id' => $l->item_id, 'quantity' => $l->quantity])->values(),
            'unavailable' => $order->lines->count() - $lines->count(),
        ]]);
    }

    /**
     * Autosave for the order sheet: keeps the quantities, notes and date on the store's draft so the staff can leave the
     * page and come back to the same order. A draft is invisible to every list and report, so nothing here counts as an order;
     * the deadline and one-order-per-day rules are enforced when the order is actually placed.
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isStore() && $user->store_id, 403, 'Only store accounts can place orders.');

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array', 'max:2000'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('archived_at')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $draft = $this->draftFor($user);

        DB::transaction(function () use ($draft, $data) {
            // the order date was chosen when the order was started and is not editable from the sheet
            $draft->update(['notes' => $data['notes'] ?? null]);

            $quantities = collect($data['lines'])->groupBy('item_id')->map(fn ($g) => $g->sum('quantity'));
            $items = Item::whereIn('id', $quantities->keys())->get()->keyBy('id');

            $draft->lines()->delete();
            foreach ($quantities as $itemId => $qty) {
                $item = $items[$itemId];
                $draft->lines()->create([
                    'item_id' => $item->id, 'product_code' => $item->product_code, 'name' => $item->description,
                    'source' => $item->source, 'category' => $item->category, 'quantity' => min(9999, $qty),
                ]);
            }
        });

        return response()->json(['saved_at' => now()->toIso8601String(), 'lines' => count($data['lines'])]);
    }

    /** A real date, within a year either side of today so a typo can't bury an order. */
    private function dateRules(): array
    {
        return ['required', ...$this->dateWindow()];
    }

    private function dateWindow(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:'.today()->subYear()->toDateString(), 'before_or_equal:'.today()->addYear()->toDateString()];
    }

    /* ------------------------------------------------------------------ place / post / cancel */

    public function create(Request $request): Response
    {
        $user = $request->user();
        $draft = $this->draftFor($user);

        return Inertia::render('orders/create', [
            'order' => ['id' => $draft->id, 'number' => $draft->number(), 'order_date' => $draft->order_date->toDateString()],
            // what was already entered on this draft (autosaved), so leaving the page doesn't lose it
            'saved' => [
                'notes' => $draft->notes ?? '',
                'lines' => $draft->lines()->whereIn('item_id', Item::active()->select('id'))->get(['item_id', 'quantity'])->map(fn ($l) => ['item_id' => $l->item_id, 'quantity' => $l->quantity]),
            ],
            'blocked' => $this->closedReason($user) ?? ($this->existingOrderOn($user->store, $draft->order_date->toDateString(), $draft)
                ? $this->alreadyOrderedMessage($user->store, $this->existingOrderOn($user->store, $draft->order_date->toDateString(), $draft), $draft->order_date->toDateString())
                : null),
            'items' => Item::active()->orderBy('description')->get()
                ->map(fn (Item $i) => [
                    'id' => $i->id,
                    'source' => $i->source,
                    'product_code' => $i->product_code,
                    'description' => $i->description,
                    'category' => $i->category,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // the order date is whatever was chosen when the order was started; the sheet can't change it
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('archived_at')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ], [
            'lines.required' => 'Enter a quantity for at least one item.',
            'lines.min' => 'Enter a quantity for at least one item.',
        ]);

        $order = DB::transaction(function () use ($data, $user) {
            // Serialise submissions per store, then re-check everything inside the lock so two requests
            // racing the cutoff can't both slip through, and a failure leaves no partial order behind.
            $store = $this->lockedStore($user);
            $this->assertStoreCanOrder($user, $store);

            $order = $this->draftFor($user);
            $data['order_date'] = $order->order_date->toDateString();
            $this->assertDateIsFree($store, $data['order_date'], $order);

            // Quantities for the same item are merged; item data is read from the database, never the client.
            $quantities = collect($data['lines'])->groupBy('item_id')->map(fn ($g) => $g->sum('quantity'));
            $items = Item::whereIn('id', $quantities->keys())->get()->keyBy('id');

            $order->lines()->delete();
            $order->update(['order_date' => $data['order_date'], 'notes' => $data['notes'] ?? null, 'status' => 'pending']);

            foreach ($quantities as $itemId => $qty) {
                $item = $items[$itemId];
                $order->lines()->create([
                    'item_id' => $item->id,
                    'product_code' => $item->product_code,
                    'name' => $item->description,
                    'source' => $item->source,
                    'category' => $item->category,
                    'quantity' => $qty,
                ]);
            }

            $order->record('placed', $user);

            return $order;
        });

        return to_route('orders.show', $order);
    }

    /**
     * A store takes its own PENDING order back to a draft so it can fix it (a missed item, a wrong quantity) and place it again.
     * Same TR number, items and notes are kept; it just stops counting until it is placed again. Posted orders can't be reset
     * (only an admin can cancel those), and a reset needs the store to still be able to order, otherwise it couldn't be placed again.
     */
    public function reset(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($order, $user) {
            $store = $this->lockedStore($user);
            $this->assertStoreCanOrder($user, $store);

            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'pending') {
                throw ValidationException::withMessages(['reset' => "Only a pending order can be reset. {$fresh->number()} is {$fresh->status}."]);
            }

            // one working draft per user: an empty one is simply replaced, one with items entered is never thrown away
            $current = Order::where('user_id', $user->id)->where('store_id', $user->store_id)->where('status', Order::DRAFT)->first();
            if ($current && $current->lines()->exists()) {
                throw ValidationException::withMessages(['reset' => 'You have another order in progress. Place it first, then reset this one.']);
            }
            $current?->delete();

            $fresh->update(['status' => Order::DRAFT, 'posted_at' => null]);
            $fresh->record('reset', $user);
        });

        return to_route('orders.create');
    }

    /** Live ECPOS status of an order that was sent (the order page shows it and has a refresh button). */
    public function ecposStatus(Order $order): JsonResponse
    {
        abort_unless($order->ecpos_journal_id, 404);

        try {
            return response()->json(app(\App\Services\EcposOrders::class)->status($order->ecpos_journal_id));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * Sends a POSTED order that never reached ECPOS (for example it was posted while sending was switched off, or ECPOS
     * was unreachable). The order's own store or an admin can do it; it only ever happens once per order.
     */
    public function sendToEcpos(Request $request, Order $order): RedirectResponse
    {
        $orders = app(\App\Services\EcposOrders::class);
        abort_unless($orders->enabled(), 404);

        DB::transaction(function () use ($order, $orders, $request) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'posted' || $fresh->ecpos_sent_at) {
                throw ValidationException::withMessages(['ecpos' => $fresh->ecpos_sent_at ? "{$fresh->number()} was already sent to ECPOS." : 'Only a posted order can be sent to ECPOS.']);
            }

            try {
                $sent = $orders->send($fresh);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['ecpos' => $e->getMessage()]);
            }

            $fresh->update(['ecpos_journal_id' => $sent['journal_id'], 'ecpos_sent_at' => now(), 'ecpos_note' => $sent['note']]);
            $fresh->record('sent', $request->user(), 'Sent to ECPOS'.($sent['journal_id'] ? ', journal '.$sent['journal_id'] : ''));
        });

        return back()->with('success', "Order {$order->number()} was sent to ECPOS.");
    }

    /** A store posts (submits) its own pending order. */
    public function post(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isStore(), 403);

        DB::transaction(function () use ($order, $user) {
            $store = $this->lockedStore($user);
            $this->assertStoreCanOrder($user, $store);

            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->status === 'pending', 422, 'Only pending orders can be posted.');

            $ecpos = [];
            $orders = app(\App\Services\EcposOrders::class);
            if ($orders->enabled()) {
                // Send first: if ECPOS can't take the order, the TR stays pending and the store can try again.
                try {
                    $sent = $orders->send($fresh);
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages(['ecpos' => $e->getMessage()]);
                }
                $ecpos = ['ecpos_journal_id' => $sent['journal_id'], 'ecpos_sent_at' => now(), 'ecpos_note' => $sent['note']];
            }

            $fresh->update(['status' => 'posted', 'posted_at' => now()] + $ecpos);
            $fresh->record('posted', $user, $ecpos && $ecpos['ecpos_journal_id'] ? 'Sent to ECPOS, journal '.$ecpos['ecpos_journal_id'] : null);
        });

        return back()->with('success', "Order {$order->number()} posted.");
    }

    /**
     * Cancels a TR but never deletes it or its items.
     *  - Admin: only a POSTED TR; a reason is required. This frees the store to order again for that day.
     *  - Store: only its own PENDING order (reason optional).
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only an admin can cancel an order. Use Reset to change a pending one.');

        $data = $request->validate([
            'reason' => [$user->isAdmin() ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Please give a reason for cancelling this TR.',
            'reason.min' => 'The reason is too short.',
        ]);

        $allowed = $user->isAdmin() ? 'posted' : 'pending';

        DB::transaction(function () use ($order, $user, $data, $allowed) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== $allowed) {
                throw ValidationException::withMessages([
                    'reason' => $fresh->status === 'cancelled'
                        ? "{$fresh->number()} is already cancelled."
                        : ($user->isAdmin()
                            ? "Only a posted TR can be cancelled. {$fresh->number()} is {$fresh->status}."
                            : "Only a pending order can be cancelled. {$fresh->number()} is {$fresh->status}."),
                ]);
            }

            $reason = $data['reason'] ?? 'Cancelled by the store.';
            $fresh->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);
            $fresh->record('cancelled', $user, $reason);
        });

        return back()->with('success', "Order {$order->number()} cancelled."
            .($user->isAdmin() ? ' The store can now place a new order for that date.' : ''));
    }

    /* ------------------------------------------------------------------ rules */

    private function lockedStore(User $user): Store
    {
        abort_unless($user->isStore() && $user->store_id, 403, 'Only store accounts can place orders.');

        return Store::whereKey($user->store_id)->lockForUpdate()->firstOrFail();
    }

    /** Why this user can't place/post right now, or null if they can. */
    private function closedReason(User $user): ?string
    {
        if (! $user->isStore() || ! $user->store) {
            return 'Only store accounts can place orders.';
        }
        if (! $user->store->is_active) {
            return 'Your store is deactivated, so new orders can’t be submitted. Please contact an admin.';
        }
        if (! $this->deadlines->isOpen($user->store)) {
            $d = $this->deadlines->for($user->store);

            return 'Today’s submission deadline ('.$d['at']->format('g:i A').') has passed. New orders can’t be submitted until tomorrow, unless an admin extends your deadline.';
        }

        return null;
    }

    private function assertStoreCanOrder(User $user, ?Store $store = null): void
    {
        abort_unless($user->isStore(), 403, 'Only store accounts can place orders.');

        $user->setRelation('store', $store ?? $user->store);

        if ($reason = $this->closedReason($user)) {
            throw ValidationException::withMessages(['deadline' => $reason]);
        }
    }

    /** One active (pending/posted) order per store per order date, when that setting is on. */
    /** The store's pending/posted TR for that date, if the one-order-per-day rule is on and there is one. */
    private function existingOrderOn(Store $store, string $date, ?Order $ignore = null): ?Order
    {
        if (AppSetting::read('one_order_per_day', '1') !== '1') {
            return null;
        }

        return Order::where('store_id', $store->id)
            ->whereDate('order_date', $date)
            ->whereIn('status', Order::ACTIVE)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->first();
    }

    private function alreadyOrderedMessage(Store $store, Order $existing, string $date): string
    {
        $when = CarbonImmutable::parse($date)->format('M j, Y');

        return "{$store->name} already has {$existing->number()} ({$existing->status}) for {$when}. "
            .($existing->status === 'posted' ? 'Ask an admin to cancel it if the order needs to be redone.' : 'Post it, or cancel it if you need to start over.');
    }

    private function assertDateIsFree(Store $store, string $date, Order $ignore): void
    {
        if ($existing = $this->existingOrderOn($store, $date, $ignore)) {
            throw ValidationException::withMessages(['order_date' => $this->alreadyOrderedMessage($store, $existing, $date)]);
        }
    }

    private function summary(Order $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number(),
            'ordered_by' => $o->user->name,
            'store' => $o->store ? ['id' => $o->store->id, 'code' => $o->store->code, 'name' => $o->store->name] : null,
            'status' => $o->status,
            'lines_count' => (int) $o->lines_count,
            'units' => (int) $o->units,
            'bw_units' => (int) $o->bw_units,
            'warehouse_units' => (int) $o->warehouse_units,
            'merchandise_units' => (int) $o->merchandise_units,
            'rejects_units' => (int) $o->rejects_units,
            'order_date' => $o->order_date->toDateString(),
            'created_at' => $o->created_at->toIso8601String(),
        ];
    }
}
