<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ecpos_journal_id', 64)->nullable()->index()->after('posted_at'); // ECPOS's reference for the posted order
            $table->timestamp('ecpos_sent_at')->nullable()->after('ecpos_journal_id');
            $table->text('ecpos_note')->nullable()->after('ecpos_sent_at'); // e.g. items that are not in ECPOS and were left out
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ecpos_journal_id', 'ecpos_sent_at', 'ecpos_note']);
        });
    }
};
