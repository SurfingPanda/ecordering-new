<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    // never filled from request input: only App\Support\Activity writes these
    protected $fillable = ['user_id', 'store_id', 'event', 'subject', 'note', 'ip'];
}
