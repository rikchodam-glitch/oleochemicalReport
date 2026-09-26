<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah nilai 'Project' pada kolom reports.jenis_pekerjaan.
 *
 * Wizard laporan Telegram kini punya klasifikasi ZPM 8 - Project yang langsung
 * menentukan jenis pekerjaan 'Project' (tanpa step pilihan tambahan), dan
 * halaman Laporan menampilkannya sebagai kartu ringkasan, badge, serta pilihan
 * filter.
 *
 * Definisi ENUM diubah lewat schema builder (->change()) supaya ikut berjalan di
 * MySQL (production) maupun SQLite (database test) tanpa SQL mentah khas MySQL —
 * lihat pelajaran insiden #16 di AGENTS.md. Pada SQLite schema builder membangun
 * ulang tabel reports dengan definisi kolom yang baru.
 */
return new class extends Migration
{
    /**
     * Daftar jenis pekerjaan setelah penambahan 'Project'.
     *
     * @return array<int, string>
     */
    private function jenisPekerjaan(): array
    {
        return ['CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT', 'Project'];
    }

    /**
     * Daftar jenis pekerjaan sebelum penambahan 'Project'.
     *
     * @return array<int, string>
     */
    private function jenisPekerjaanLama(): array
    {
        return ['CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT'];
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
     * Nilai 'Project' dikosongkan lebih dulu supaya tidak ada data yang tidak
     * dikenal definisi ENUM lama (mengikuti pola migration 'IT' sebelumnya).
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('reports')->where('jenis_pekerjaan', 'Project')->update(['jenis_pekerjaan' => null]);

        $jenis = $this->jenisPekerjaanLama();

        Schema::table('reports', function (Blueprint $table) use ($jenis) {
            $table->enum('jenis_pekerjaan', $jenis)->nullable()->change();
        });
    }
};
