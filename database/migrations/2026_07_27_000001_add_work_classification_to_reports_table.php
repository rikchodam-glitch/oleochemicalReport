<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom klasifikasi pekerjaan ke tabel reports.
 *
 * Kolom baru:
 * - work_classification : klasifikasi pekerjaan yang dipilih manual oleh
 *   teknisi di step paling awal wizard (sebelum pencarian equipment).
 *   Nilai yang valid mengikuti App\Enums\WorkClassification: cm, pm, dcm,
 *   fabrikasi, sipil. Berbeda dari kolom report_type yang dideteksi
 *   otomatis dari hasil analisis AI.
 */
return new class extends Migration
{
    /**
     * Jalankan migration.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('work_classification', 20)
                ->nullable()
                ->after('report_type');
        });
    }

    /**
     * Batalkan migration.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('work_classification');
        });
    }
};
