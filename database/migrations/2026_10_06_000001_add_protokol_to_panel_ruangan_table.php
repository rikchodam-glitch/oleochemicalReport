<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tambah kolom `protokol` pada tabel ruangan panel.
     *
     * Perangkat pembaca suhu ruang panel bisa berjalan dengan dua bentuk
     * layanan berbeda:
     *   - firmware JSON API  : HTTP port 80 (/api/status, /api/buffer, /api/latest)
     *   - sketch dashboard   : JSON TCP port 5000 dan dashboard HTML port 80
     *
     * Kolom ini menyimpan cara pembacaan yang dipakai tiap ruangan. Nilai
     * bawaan `otomatis` membuat deteksi terjadi sendiri (JSON API lebih dulu,
     * lalu JSON TCP, lalu dashboard HTML), sehingga perangkat yang sudah
     * berjalan tidak perlu diatur ulang dan perangkat baru tetap terbaca.
     */
    public function up(): void
    {
        Schema::table('panel_ruangan', function (Blueprint $table) {
            $table->string('protokol', 20)->default('otomatis');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::table('panel_ruangan', function (Blueprint $table) {
            $table->dropColumn('protokol');
        });
    }
};
