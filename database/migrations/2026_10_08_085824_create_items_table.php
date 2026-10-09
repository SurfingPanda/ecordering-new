<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('bw_products')->index(); // bw_products | warehouse
            $table->string('product_code')->unique();
            $table->string('description');
            $table->string('barcode')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('retail_group', 20)->default('regular_product')->index(); // regular_product | non_product
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
