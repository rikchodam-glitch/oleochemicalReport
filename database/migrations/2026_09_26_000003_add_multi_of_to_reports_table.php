<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penautan laporan salinan untuk pekerjaan multi equipment/lokasi.
 *
 * Satu pekerjaan bisa menyentuh beberapa alat/lokasi. Mengikuti pola kolaborasi
 * teknisi: laporan INDUK menyimpan detail pekerjaan + foto, sedangkan setiap
 * alat/lokasi tambahan mendapat baris salinan sendiri (kolom multi_of menunjuk
 * ke laporan induk) supaya riwayat alat maupun halaman lokasi tetap menampilkan
 * pekerjaan tersebut. Halaman laporan cukup menampilkan baris induk beserta
 * daftar alat terkait.
 */
return new class extends Migration
{
    /**
     * Jalankan migration: tambah kolom multi_of.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('reports', 'multi_of')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('multi_of')
                ->nullable()
                ->after('collaborator_of')
                ->constrained('reports')
                ->nullOnDelete();
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('reports', 'multi_of')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['multi_of']);
            $table->dropColumn('multi_of');
        });
    }
};
