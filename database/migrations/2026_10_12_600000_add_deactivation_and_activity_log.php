<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // set = this login can no longer sign in (history is kept, nothing is deleted)
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
        });

        // Append-only record of security-relevant actions: sign-ins, password changes, user / store / catalog changes.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who did it (null = nobody signed in / the system)
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('subject', 255)->nullable(); // what it was done to, in words ("Maria Cruz (maria@bw.test)")
            $table->string('note', 1000)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
