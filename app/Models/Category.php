<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product category. Each one belongs to a single catalog (BW Products or Warehouse), so the same wording
 * can exist in both. Items keep the category name as text, so order history is never rewritten.
 */
class Category extends Model
{
    protected $fillable = ['source', 'name'];

    /** Number of items currently filed under this category. */
    public function itemsCount(): int
    {
        return Item::where('source', $this->source)->where('category', $this->name)->count();
    }
}
