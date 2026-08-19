<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom equipment_type ke tabel assets.
 *
 * Kolom ini menyimpan tipe equipment yang lebih ramah-pengguna (mis. AC, P, M)
 * dibanding object_type (kode ZPM seperti ZPM-ACE). Nilai bisa NULL untuk
 * asset yang datanya tidak menyertakan equipment type — asset tersebut tetap
 * diimport tapi tidak ditampilkan di hierarki pemilihan equipment di bot.
 */
return new class extends Migration
{
    /**
     * Jalankan migrasi.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('equipment_type')->nullable()->after('object_type');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('equipment_type');
        });
    }
};
