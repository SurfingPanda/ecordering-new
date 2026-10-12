<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalog an item synced from ECPOS lands in. Until now these rules lived in code; admins edit them on the
 * "Sync Rules" page. Everything that matches no rule goes to BW Products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // code_contains | group
            $table->string('match', 100); // stored in capitals
            $table->string('source', 30); // bw_products | warehouse | merchandise | rejects
            $table->timestamps();

            $table->unique(['type', 'match']);
        });

        // the rules the sync used before they became editable
        $now = now();
        DB::table('catalog_rules')->insert([
            ['type' => 'code_contains', 'match' => '-SUP-', 'source' => 'warehouse', 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'group', 'match' => 'MERCHANDISE', 'source' => 'merchandise', 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'group', 'match' => 'BW REJECTS', 'source' => 'rejects', 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'group', 'match' => 'STORE SUPPLIES', 'source' => 'warehouse', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_rules');
    }
};
