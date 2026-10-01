<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penambahan field Temuan (finding) pada laporan.
 *
 * Teknisi bisa mencatat temuan/kejadian yang dilihat di lapangan setelah
 * mengisi root cause. Sifatnya OPSIONAL: pada wizard Telegram ada tombol
 * "Lewati Temuan" (dan root cause juga bisa dilewati), sehingga kolom ini
 * dibuat nullable.
 */
return new class extends Migration
{
    /**
     * Jalankan migration: tambah kolom finding pada tabel reports.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('reports', 'finding')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->text('finding')->nullable()->after('root_cause');
        });
    }

    /**
     * Batalkan migration.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('reports', 'finding')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('finding');
        });
    }
};
