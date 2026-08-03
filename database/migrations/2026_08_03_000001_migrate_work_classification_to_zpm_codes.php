<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memetakan ulang data reports.work_classification dari kode lama
 * (cm, pm, dcm, fabrikasi, sipil) ke kode ZPM baru sesuai
 * App\Enums\WorkClassification versi terbaru.
 *
 * Pemetaan:
 * - cm        -> zpm1 (ZPM 1 - Corrective Maintenance)
 * - pm        -> zpm3 (ZPM 3 - Preventive Maintenance)
 * - dcm       -> zpm6 (ZPM 6 - Planned Maintenance, padanan terdekat
 *                karena dCM tidak punya kode ZPM tersendiri)
 * - fabrikasi -> zpm5 (ZPM 5 - Fabrikasi)
 * - sipil     -> zpm7 (ZPM 7 - Sipil)
 *
 * Migration ini idempoten: up() hanya mengubah baris yang masih memakai
 * kode lama, dan down() mengembalikan ke kode lama jika perlu rollback.
 */
return new class extends Migration
{
    /**
     * Pasangan kode lama ke kode ZPM baru.
     *
     * @var array<string, string>
     */
    private array $mapping = [
        'cm'        => 'zpm1',
        'pm'        => 'zpm3',
        'dcm'       => 'zpm6',
        'fabrikasi' => 'zpm5',
        'sipil'     => 'zpm7',
    ];

    /**
     * Jalankan migration: ubah kode lama menjadi kode ZPM baru.
     */
    public function up(): void
    {
        foreach ($this->mapping as $oldCode => $newCode) {
            DB::table('reports')
                ->where('work_classification', $oldCode)
                ->update(['work_classification' => $newCode]);
        }
    }

    /**
     * Batalkan migration: kembalikan kode ZPM baru menjadi kode lama.
     */
    public function down(): void
    {
        foreach (array_flip($this->mapping) as $newCode => $oldCode) {
            DB::table('reports')
                ->where('work_classification', $newCode)
                ->update(['work_classification' => $oldCode]);
        }
    }
};
