<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // null = active; a date = archived (hidden from the catalog and from ordering, but never deleted)
            $table->timestamp('archived_at')->nullable()->after('retail_group')->index();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
