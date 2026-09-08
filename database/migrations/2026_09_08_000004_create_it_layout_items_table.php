<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Membuat tabel it_layout_items: posisi (x/y dalam persen 0-100) sebuah
     * perangkat IT di atas sebuah denah (it_layouts). Kolom it_asset_id dibuat
     * UNIQUE agar satu perangkat hanya bisa ditempatkan di SATU layout —
     * asset yang sudah terpasang tidak bisa ditandai di layout lain.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('it_layout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layout_id')->constrained('it_layouts')->cascadeOnDelete();
            $table->foreignId('it_asset_id')->unique()->constrained('it_assets')->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->timestamps();
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('it_layout_items');
    }
};
