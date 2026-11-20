<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tabel lokasi penyimpanan alat.
 *
 * Lokasi di sini adalah master data khusus Management Tools, TERPISAH dari
 * functional_locations yang dipakai aset/report. Administrator bebas membuat
 * nama lokasi sesuai tempat penyimpanan fisik.
 */
return new class extends Migration
{
    /**
     * Membuat tabel tool_locations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Menghapus tabel tool_locations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_locations');
    }
};
