<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status pekerjaan "continue" + penautan laporan lanjutan.
 *
 * Wizard Telegram kini menawarkan dua penyelesaian di Step 8:
 *   - Done     → status 'completed' (pekerjaan tuntas hari itu)
 *   - Continue → status 'continue' (pekerjaan belum tuntas, akan dilanjutkan)
 *
 * Laporan lanjutan (dibuat dari kode laporan 'continue' yang diketik teknisi)
 * menunjuk balik ke laporan asalnya lewat kolom continued_from_id, sehingga
 * rantai pekerjaan beberapa hari bisa ditampilkan di halaman detail laporan
 * tanpa menggandakan deskripsi pekerjaannya.
 *
 * Definisi ENUM status diubah lewat schema builder (->change()) supaya berjalan
 * di MySQL (production) maupun SQLite (database test) tanpa SQL mentah khas
 * MySQL — lihat insiden #16 di AGENTS.md.
 */
return new class extends Migration
{
    /**
     * Daftar status laporan setelah penambahan 'continue'.
     *
     * @return array<int, string>
     */
    private function statusLaporan(): array
    {
        return ['draft', 'needs_review', 'completed', 'continue'];
    }

    /**
     * Jalankan migration: perluas ENUM status lalu tambah kolom penautan.
     *
     * @return void
     */
    public function up(): void
    {
        $status = $this->statusLaporan();

        // 1) ENUM status (perubahan definisi kolom) — dijalankan lebih dulu
        //    karena pada SQLite schema builder membangun ulang tabel reports.
        Schema::table('reports', function (Blueprint $table) use ($status) {
            $table->enum('status', $status)->default('draft')->change();
        });

        // 2) Kolom penautan laporan lanjutan (dibuat setelah rebuild tabel).
        if (!Schema::hasColumn('reports', 'continued_from_id')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->foreignId('continued_from_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('reports')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Batalkan migration.
     *
     * Laporan ber-status 'continue' dikembalikan ke 'draft' lebih dulu supaya
     * tidak ada nilai yang tidak dikenal definisi ENUM lama.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('reports')->where('status', 'continue')->update(['status' => 'draft']);
        DB::table('reports')->whereNotNull('continued_from_id')->update(['continued_from_id' => null]);

        if (Schema::hasColumn('reports', 'continued_from_id')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropForeign(['continued_from_id']);
                $table->dropColumn('continued_from_id');
            });
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->enum('status', ['draft', 'needs_review', 'completed'])->default('draft')->change();
        });
    }
};
