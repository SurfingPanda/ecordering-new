<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // nullable so orders created before stores existed are preserved; the app always sets it for new orders
            $table->foreignId('store_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('posted_at')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('posted_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index(['store_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'order_date']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['posted_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
