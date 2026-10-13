<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the dashboard, trends and consolidated pages group and count by product_code
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['product_code']);
        });
    }
};
