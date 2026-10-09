<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // set for items that are kept in step with ECPOS (BW Products); null for items added by hand
            $table->timestamp('synced_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('synced_at');
        });
    }
};
