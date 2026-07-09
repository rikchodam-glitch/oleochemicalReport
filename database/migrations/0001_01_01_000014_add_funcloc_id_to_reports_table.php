<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Ditempatkan setelah area_id agar kolom-kolom lokasi berkelompok
            $table->foreignId('funcloc_id')
                ->nullable()
                ->after('area_id')
                ->constrained('functional_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funcloc_id');
        });
    }
};
