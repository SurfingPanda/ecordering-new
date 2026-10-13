<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "orders that have lines in this catalog" (Orders list filter)
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'source']);
        });

        // the dashboard, trends and consolidated pages pick orders by status and date
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'source']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'order_date']);
        });
    }
};
