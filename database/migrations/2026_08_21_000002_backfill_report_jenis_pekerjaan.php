<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill kolom reports.jenis_pekerjaan untuk report yang sudah ada
 * berdasarkan klasifikasi ZPM (work_classification).
 *
 * Pemetaan:
 * - zpm1 / zpm6 : EI   (default sementara, bisa disesuaikan via halaman edit)
 * - zpm3        : CM/PM
 * - zpm5        : Fabrikasi
 * - zpm7        : Sipil
 *
 * Hanya mengisi report yang jenis_pekerjaan-nya masih NULL supaya tidak
 * menimpa nilai yang sudah dipilih manual oleh user.
 */
return new class extends Migration
{
    /**
     * Pemetaan kode ZPM ke nilai jenis pekerjaan.
     *
     * @var array<string, string>
     */
    private array $mapping = [
        'zpm1' => 'EI',
        'zpm6' => 'EI',
        'zpm3' => 'CM/PM',
        'zpm5' => 'Fabrikasi',
        'zpm7' => 'Sipil',
    ];

    /**
     * Jalankan backfill untuk report dengan jenis_pekerjaan masih kosong.
     */
    public function up(): void
    {
        foreach ($this->mapping as $classification => $jenis) {
            DB::table('reports')
                ->where('work_classification', $classification)
                ->whereNull('jenis_pekerjaan')
                ->update(['jenis_pekerjaan' => $jenis]);
        }
    }

    /**
     * Batalkan backfill: kembalikan jenis_pekerjaan hasil mapping ini ke NULL.
     */
    public function down(): void
    {
        foreach ($this->mapping as $classification => $jenis) {
            DB::table('reports')
                ->where('work_classification', $classification)
                ->where('jenis_pekerjaan', $jenis)
                ->update(['jenis_pekerjaan' => null]);
        }
    }
};
