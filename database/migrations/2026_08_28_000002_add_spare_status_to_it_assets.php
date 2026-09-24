<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Tambah nilai 'spare' pada kolom status tabel it_assets, untuk menandai
     * perangkat IT cadangan (di Excel ditandai warna abu-abu / teks "Spare").
     * Migrasi memakai SQL mentah karena mengubah definisi ENUM (bukan sekadar
     * menambah kolom) dan harus presisi terhadap engine MySQL.
     *
     * @return void
     */
    public function up(): void
    {
        // Selalu dijalankan di atas MySQL (produksi). Pada driver lain (SQLite
        // saat test) sintaks MODIFY tidak dikenal, jadi dilewati - pengujian
        // tidak membutuhkan definisi ENUM.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE it_assets MODIFY status ENUM('active', 'inactive', 'needs_review', 'spare') NOT NULL DEFAULT 'active'");
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE it_assets MODIFY status ENUM('active', 'inactive', 'needs_review') NOT NULL DEFAULT 'active'");
    }
};
