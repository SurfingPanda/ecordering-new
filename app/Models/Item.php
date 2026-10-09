<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    // The catalogs, in the order they are shown everywhere (tabs, nav, charts).
    public const SOURCES = ['bw_products', 'warehouse', 'merchandise', 'rejects'];

    public const LABELS = ['bw_products' => 'BW Products', 'warehouse' => 'Warehouse', 'merchandise' => 'Merchandise', 'rejects' => 'Rejects'];

    /** One item of a catalog, e.g. "Warehouse Item" (used in exports). */
    public const SINGULAR = ['bw_products' => 'BW Product', 'warehouse' => 'Warehouse Item', 'merchandise' => 'Merchandise Item', 'rejects' => 'Reject Item'];

    public const RETAIL_GROUPS = ['regular_product', 'non_product'];

    // archived_at is deliberately not fillable: items are archived/restored only through the admin actions.
    protected $fillable = [
        'source', 'product_code', 'description', 'barcode',
        'category', 'retail_group',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** Items that can be browsed and ordered. Items are never deleted, only archived. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('items.archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('items.archived_at');
    }

    /** Shape sent to the frontend. */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'product_code' => $this->product_code,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'category' => $this->category,
            'retail_group' => $this->retail_group,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
