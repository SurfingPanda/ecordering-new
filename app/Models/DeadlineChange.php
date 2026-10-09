<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadlineChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'applies_on', 'previous_time', 'new_time', 'changed_by'];

    protected function casts(): array
    {
        return [];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
