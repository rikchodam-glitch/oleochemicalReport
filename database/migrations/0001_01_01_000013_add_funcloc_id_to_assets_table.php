<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Kolom funcloc_id ditempatkan setelah kolom functional_loc (string lama)
            // agar keduanya berdampingan dan mudah dibandingkan saat migrasi data
            $table->foreignId('funcloc_id')
                ->nullable()
                ->after('functional_loc')
                ->constrained('functional_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funcloc_id');
        });
    }
};
