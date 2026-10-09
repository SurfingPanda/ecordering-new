<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedInteger('tr_counter')->default(0); // last TR sequence number handed out for this store
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('store_seq')->nullable()->after('store_id');
            $table->string('tr_number', 48)->nullable()->unique()->after('store_seq'); // e.g. TR-BW0018-000001
            $table->unique(['store_id', 'store_seq']);
        });

        // Orders that already exist get their store's next numbers, in the order they were created.
        $prefix = fn ($store) => strtoupper($store->ecpos_store_id ?: $store->code);
        foreach (DB::table('stores')->get() as $store) {
            $seq = 0;
            foreach (DB::table('orders')->where('store_id', $store->id)->orderBy('id')->pluck('id') as $orderId) {
                $seq++;
                DB::table('orders')->where('id', $orderId)->update(['store_seq' => $seq, 'tr_number' => 'TR-'.$prefix($store).'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT)]);
            }
            DB::table('stores')->where('id', $store->id)->update(['tr_counter' => $seq]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'store_seq']);
            $table->dropUnique(['tr_number']);
            $table->dropColumn(['store_seq', 'tr_number']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('tr_counter');
        });
    }
};
