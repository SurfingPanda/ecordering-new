<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20); // bw_products | warehouse - a category belongs to one catalog
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['source', 'name']);
        });

        // Keep what already exists: every category an item currently uses becomes a category of that item's catalog.
        $now = now();
        DB::table('items')
            ->whereNotNull('category')->where('category', '!=', '')
            ->select('source', 'category')->distinct()->get()
            ->each(fn ($row) => DB::table('categories')->insertOrIgnore([
                'source' => $row->source, 'name' => $row->category, 'created_at' => $now, 'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
