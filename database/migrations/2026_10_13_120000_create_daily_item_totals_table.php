<?php

use App\Services\OrderSummary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_item_totals', function (Blueprint $table) {
            $table->date('order_date');
            $table->unsignedBigInteger('store_id')->default(0); // 0 = an order without a store
            $table->string('product_code');
            $table->string('source', 20);
            $table->string('category')->nullable();
            $table->string('name');
            $table->unsignedInteger('units');
            $table->unsignedInteger('order_count'); // how many placed orders that day included this product

            $table->primary(['order_date', 'store_id', 'product_code', 'source']);
            $table->index(['store_id', 'order_date']);
            $table->index(['source', 'order_date']);
        });

        // The same totals added up across ALL stores: one row per day + product + catalog, so an admin's dashboard and
        // trends read a fraction of the rows (there is one row per store for each of these in the table above).
        Schema::create('daily_catalog_totals', function (Blueprint $table) {
            $table->date('order_date');
            $table->string('product_code');
            $table->string('source', 20);
            $table->string('category')->nullable();
            $table->string('name');
            $table->unsignedInteger('units');
            $table->unsignedInteger('order_count');

            $table->primary(['order_date', 'product_code', 'source']);
            $table->index(['source', 'order_date']);
        });

        // fill both from the orders that already exist
        OrderSummary::rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_catalog_totals');
        Schema::dropIfExists('daily_item_totals');
    }
};
