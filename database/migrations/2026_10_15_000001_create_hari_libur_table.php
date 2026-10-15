<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel hari libur nasional / perusahaan.
     *
     * Satu baris = satu tanggal libur (unik) beserta namanya, mis.
     * "17 Agustus 2026 - Hari Kemerdekaan". Hari libur dipakai perhitungan
     * man hour: target menit 0 untuk SEMUA teknisi dan seluruh jam yang
     * dilaporkan pada tanggal itu dihitung lembur (diputuskan user 15/10/2026).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('hari_libur', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->unique();
            $table->string('nama', 150);
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
        Schema::dropIfExists('hari_libur');
    }
};
