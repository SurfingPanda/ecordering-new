<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // the store's ID in ECPOS (e.g. BW0018); sent with orders. NULL = not linked to ECPOS.
            $table->string('ecpos_store_id', 32)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['ecpos_store_id']);
            $table->dropColumn('ecpos_store_id');
        });
    }
};
