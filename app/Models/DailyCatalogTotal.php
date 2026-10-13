<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day's total of one product (in one catalog) across every store, placed orders only. It is daily_item_totals added up
 * over the stores, kept by App\Services\OrderSummary; never edited by hand.
 */
class DailyCatalogTotal extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'daily_catalog_totals';

    protected $guarded = [];
}
