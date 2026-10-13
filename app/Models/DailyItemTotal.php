<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day's total of one product (in one catalog) ordered by one store, counting placed orders only.
 * Never edited by hand: App\Services\OrderSummary rebuilds it from the order lines.
 */
class DailyItemTotal extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'daily_item_totals';

    protected $guarded = [];
}
