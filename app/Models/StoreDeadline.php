<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDeadline extends Model
{
    protected $fillable = ['store_id', 'date', 'time', 'updated_by'];

    protected function casts(): array
    {
        return [];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
