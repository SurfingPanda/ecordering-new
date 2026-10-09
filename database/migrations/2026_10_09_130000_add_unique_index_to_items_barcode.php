<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two items must never share a barcode. Refuse (with a clear message) rather than guess which one to change.
        $duplicates = DB::table('items')->whereNotNull('barcode')->select('barcode')->groupBy('barcode')->havingRaw('COUNT(*) > 1')->pluck('barcode');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot add a unique barcode index: these barcodes are used by more than one item: '.$duplicates->implode(', ').'. Give each item its own barcode and run the migration again.');
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unique('barcode'); // NULLs are allowed any number of times
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
        });
    }
};
