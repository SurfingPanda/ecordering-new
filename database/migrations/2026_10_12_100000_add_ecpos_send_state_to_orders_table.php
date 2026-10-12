<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // where the order is on its way to ECPOS: queued | sending | sent | failed (NULL = not applicable)
            $table->string('ecpos_state', 12)->nullable()->index()->after('ecpos_note');
            $table->unsignedTinyInteger('ecpos_attempts')->default(0)->after('ecpos_state');
            $table->text('ecpos_error')->nullable()->after('ecpos_attempts');
            $table->boolean('ecpos_uncertain')->default(false)->after('ecpos_error'); // failed in a way that may have created the order in ECPOS
            $table->timestamp('ecpos_state_at')->nullable()->after('ecpos_uncertain'); // when the state last changed
            $table->timestamp('ecpos_next_at')->nullable()->after('ecpos_state_at');   // next automatic try, if one is scheduled
        });

        // orders that were already sent before this existed
        DB::table('orders')->whereNotNull('ecpos_sent_at')->update(['ecpos_state' => 'sent', 'ecpos_state_at' => DB::raw('ecpos_sent_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ecpos_state', 'ecpos_attempts', 'ecpos_error', 'ecpos_uncertain', 'ecpos_state_at', 'ecpos_next_at']);
        });
    }
};
