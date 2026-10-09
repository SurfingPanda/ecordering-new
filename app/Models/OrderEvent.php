<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'user_id', 'event', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
