<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan kolom jam mulai dan jam selesai pengerjaan ke tabel reports.
 *
 * Kolom ini menyimpan rentang waktu kerja yang diketik teknisi di Step 4
 * wizard (contoh "08:00 - 09:00") agar bisa ditampilkan di halaman laporan,
 * terpisah dari work_duration_minutes yang hanya menyimpan selisih menit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('start_time', 5)
                ->nullable()
                ->after('work_duration_minutes')
                ->comment('Jam mulai kerja HH:MM');

            $table->string('end_time', 5)
                ->nullable()
                ->after('start_time')
                ->comment('Jam selesai kerja HH:MM');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
