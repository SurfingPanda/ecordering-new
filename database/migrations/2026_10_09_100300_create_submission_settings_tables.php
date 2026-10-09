<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simple key/value settings (default deadline, one-order-per-day rule, ...).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Store-specific deadlines. date = null -> recurring for that store; date set -> that calendar day only.
        Schema::create('store_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date')->nullable();
            $table->string('time', 5); // HH:MM, 24h
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'date']);
        });

        // Audit trail of every deadline change.
        Schema::create('deadline_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // null = the global default
            $table->date('applies_on')->nullable(); // set for a date-specific deadline
            $table->string('previous_time', 5)->nullable();
            $table->string('new_time', 5)->nullable(); // null = override removed / reset to default
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('app_settings')->insert([
            ['key' => 'default_deadline', 'value' => '16:00', 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'one_order_per_day', 'value' => '1', 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_changes');
        Schema::dropIfExists('store_deadlines');
        Schema::dropIfExists('app_settings');
    }
};
