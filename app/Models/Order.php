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
        'user_id', 'store_id', 'order_date', 'notes', 'status',
        'posted_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'ecpos_journal_id', 'ecpos_sent_at', 'ecpos_note',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'ecpos_sent_at' => 'datetime',
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

    /** Admins see every store's orders; a store account only ever sees its own. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('orders.store_id', $user->store_id ?? 0);
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

    public function number(): string
    {
        return $this->tr_number ?: 'TR'.str_pad((string) $this->id, 12, '0', STR_PAD_LEFT);
    }

    /** Appends a row to the order's audit trail. */
    public function record(string $event, ?User $by = null, ?string $note = null): void
    {
        $this->events()->create(['user_id' => $by?->id, 'event' => $event, 'note' => $note]);
    }
}
