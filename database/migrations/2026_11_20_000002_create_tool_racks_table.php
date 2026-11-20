<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel rak penyimpanan alat.
 *
 * Satu rak selalu berada di bawah satu lokasi. Nama rak cukup unik di dalam
 * lokasinya sehingga lokasi berbeda boleh memakai nama rak yang sama.
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_racks.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_location_id')->constrained('tool_locations')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tool_location_id', 'name']);
        });
    }

    /**
     * Menghapus tabel tool_racks.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_racks');
    }
};
