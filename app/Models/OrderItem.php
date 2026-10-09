<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'item_id', 'product_code', 'name', 'source', 'category', 'quantity'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
