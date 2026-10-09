<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Existing accounts stay valid: they get the 'store' default and are promoted by the seeder/admin.
            $table->string('role', 20)->default('store')->after('password')->index(); // admin | store
            $table->foreignId('store_id')->nullable()->after('role')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('role');
        });
    }
};
