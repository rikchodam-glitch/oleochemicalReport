<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah nilai 'IT' pada kolom reports.jenis_pekerjaan.
 *
 * Wizard laporan Telegram di step jenis pekerjaan (klasifikasi ZPM 1 dan ZPM 6)
 * kini punya tiga pilihan: EI, Mekanik, dan IT. Pilihan IT mengambil equipment
 * khusus dari data IT Assets (baris cermin di tabel assets dengan
 * data_source = 'it_sync').
 *
 * Migrasi memakai SQL mentah karena mengubah definisi ENUM (bukan menambah
 * kolom), mengikuti pola migration 2026_08_28_000002_add_spare_status_to_it_assets.
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * @return void
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE reports MODIFY jenis_pekerjaan ENUM('CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT') NULL");
    }

    /**
     * Batalkan migration.
     *
     * Nilai 'IT' dikosongkan lebih dulu supaya tidak ada data yang tidak
     * dikenal definisi ENUM lama.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('reports')->where('jenis_pekerjaan', 'IT')->update(['jenis_pekerjaan' => null]);

        DB::statement("ALTER TABLE reports MODIFY jenis_pekerjaan ENUM('CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil') NULL");
    }
};
