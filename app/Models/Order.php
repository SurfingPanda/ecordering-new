<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Statuses a placed order can have:
     *  pending   - placed, not yet posted
     *  posted    - submitted by the store (final for the store)
     *  cancelled - voided by an admin (a posted TR) or by the store (a pending one); kept for the audit trail
     */
    public const STATUSES = ['pending', 'posted', 'cancelled'];

    /** Statuses that count as real, standing orders (reports, one-order-per-day rule). */
    public const ACTIVE = ['pending', 'posted'];

    /** An order that has an ID but has not been placed yet. */
    public const DRAFT = 'draft';

    // Controllers only ever pass validated, server-built arrays here (never raw request input), so status,
    // store_id and the posting/cancellation columns can't be injected by a client.
    protected $fillable = [
        'user_id', 'store_id', 'order_date', 'notes', 'staff_name', 'status',
        'posted_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'ecpos_journal_id', 'ecpos_sent_at', 'ecpos_note',
        'ecpos_state', 'ecpos_attempts', 'ecpos_error', 'ecpos_uncertain', 'ecpos_state_at', 'ecpos_next_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'ecpos_sent_at' => 'datetime',
            'ecpos_state_at' => 'datetime',
            'ecpos_next_at' => 'datetime',
            'ecpos_uncertain' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The day the order is for. Always stored as a plain YYYY-MM-DD string (never with a time part), so date
     * comparisons and range filters behave the same on MySQL and SQLite; read back as a Carbon date.
     */
    protected function orderDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value)->startOfDay() : null,
            set: fn ($value) => $value ? Carbon::parse($value)->toDateString() : null,
        );
    }

    /** Adds line count, total units and units per catalog (bw_units / warehouse_units / merchandise_units / rejects_units) to the query. */
    public function scopeWithUnits($query)
    {
        return $query
            ->withCount('lines')
            ->withSum('lines as units', 'quantity')
            ->withSum(['lines as bw_units' => fn ($q) => $q->where('source', 'bw_products')], 'quantity')
            ->withSum(['lines as warehouse_units' => fn ($q) => $q->where('source', 'warehouse')], 'quantity')
            ->withSum(['lines as merchandise_units' => fn ($q) => $q->where('source', 'merchandise')], 'quantity')
            ->withSum(['lines as rejects_units' => fn ($q) => $q->where('source', 'rejects')], 'quantity');
    }

    /**
     * Posted orders that did not reach ECPOS and need someone: a failed send, or one stuck in the queue for 10+ minutes
     * (is the worker running?). One rule shared by the sidebar badge, the daily summary and the ECPOS Queue page.
     */
    public function scopeEcposProblems(Builder $query): Builder
    {
        return $query->where('orders.status', 'posted')->where(fn ($q) => $q
            ->where('orders.ecpos_state', 'failed')
            ->orWhere(fn ($q) => $q->whereIn('orders.ecpos_state', ['queued', 'sending'])->where('orders.ecpos_state_at', '<', now()->subMinutes(10))));
    }

    /** Admins see every store's orders; an area manager the stores given to them; a store account only its own. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->visibleStoreIds();

        return $ids === null ? $query : $query->whereIn('orders.store_id', $ids);
    }

    /**
     * Route binding is scoped to what the signed-in user may see, so a store account that edits an ID in a
     * URL or request gets a 404 for another store's order instead of its data.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery();

        if ($user = auth()->user()) {
            $query->visibleTo($user);
        }

        return $query->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Every store has its own series, e.g. TR-BW0018-000001, TR-BW0018-000002 ... The number is fixed when the order
     * (or its draft) is created, so renaming a store or changing its ECPOS ID later never changes an old TR.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->tr_number || ! $order->store_id) {
                return;
            }

            // a short transaction on the store row, so two orders created at the same moment can't get the same number
            [$seq, $number] = DB::transaction(function () use ($order) {
                $store = Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
                $seq = $store->tr_counter + 1;
                $store->forceFill(['tr_counter' => $seq])->save();
                $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($store->ecpos_store_id ?: $store->code));

                return [$seq, 'TR-'.$prefix.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT)];
            });

            $order->store_seq = $seq;
            $order->tr_number = $number;
        });
    }

    /**
     * The TR number people see. Once the order is in ECPOS it is "TR" + ECPOS's journal ID (e.g. TR4700000005), the same
     * number ECPOS uses. Until then (a draft or pending order) it is our own per-store reference, see reference().
     */
    public function number(): string
    {
        return self::displayNumber($this->ecpos_journal_id, $this->tr_number, $this->id);
    }

    /** Our own per-store reference (TR-BW0018-000001): reserved when the order is started and never changes. */
    public function reference(): string
    {
        return $this->tr_number ?: 'TR'.str_pad((string) $this->id, 12, '0', STR_PAD_LEFT);
    }

    public static function displayNumber(?string $journalId, ?string $reference, int|string|null $id = null): string
    {
        if ($journalId !== null && $journalId !== '') {
            return 'TR'.$journalId;
        }

        return $reference ?: 'TR'.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
    }

    /** Appends a row to the order's audit trail. */
    public function record(string $event, ?User $by = null, ?string $note = null): void
    {
        $this->events()->create(['user_id' => $by?->id, 'event' => $event, 'note' => $note]);
    }
}
