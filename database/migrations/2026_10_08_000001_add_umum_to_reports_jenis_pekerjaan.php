<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah nilai 'UMUM' pada kolom reports.jenis_pekerjaan.
 *
 * Wizard laporan Telegram kini punya tombol jenis pekerjaan 'UMUM' tepat setelah
 * 'ZPM 8 - Project'. Tombol itu SENGAJA tidak memakai kode ZPM: kolom
 * work_classification dibiarkan kosong dan hanya jenis_pekerjaan diisi 'UMUM'.
 * Halaman Laporan menampilkannya sebagai kartu ringkasan, badge, dan pilihan
 * filter.
 *
 * Definisi ENUM diubah lewat schema builder (->change()) supaya ikut berjalan di
 * MySQL (production) maupun SQLite (database test) tanpa SQL mentah khas MySQL —
 * lihat pelajaran insiden #16 di AGENTS.md.
 */
return new class extends Migration
{
    /**
     * Daftar jenis pekerjaan setelah penambahan 'UMUM'.
     *
     * @return array<int, string>
     */
    private function jenisPekerjaan(): array
    {
        return ['CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT', 'Project', 'UMUM'];
    }

    /**
     * Daftar jenis pekerjaan sebelum penambahan 'UMUM'.
     *
     * @return array<int, string>
     */
    private function jenisPekerjaanLama(): array
    {
        return ['CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT', 'Project'];
    }

    /**
     * Jalankan migration: perluas definisi kolom jenis_pekerjaan.
     *
     * @return void
     */
    public function up(): void
    {
        $jenis = $this->jenisPekerjaan();

        Schema::table('reports', function (Blueprint $table) use ($jenis) {
            $table->enum('jenis_pekerjaan', $jenis)->nullable()->change();
        });
    }

    /**
     * Batalkan migration.
     *
     * Nilai 'UMUM' dikosongkan lebih dulu supaya tidak ada data yang tidak
     * dikenal definisi ENUM lama (mengikuti pola migration 'Project' dan 'IT').
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('reports')->where('jenis_pekerjaan', 'UMUM')->update(['jenis_pekerjaan' => null]);

        $jenis = $this->jenisPekerjaanLama();

        Schema::table('reports', function (Blueprint $table) use ($jenis) {
            $table->enum('jenis_pekerjaan', $jenis)->nullable()->change();
        });
    }
};
