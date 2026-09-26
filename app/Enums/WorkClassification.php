<?php

namespace App\Enums;

/**
 * Klasifikasi pekerjaan yang dipilih manual oleh teknisi di step paling
 * awal wizard laporan, sebelum pencarian equipment berjalan. Berbeda dari
 * ReportType yang dideteksi otomatis dari hasil analisis AI.
 *
 * Nilai mengikuti kode ZPM internal perusahaan. Sebelum SESI 1, klasifikasi
 * ini masih memakai kode lama (cm, pm, dcm, fabrikasi, sipil) — data lama
 * dipetakan ke kode ZPM lewat migration
 * 2026_08_03_000001_migrate_work_classification_to_zpm_codes.
 */
enum WorkClassification: string
{
    case Zpm1 = 'zpm1';
    case Zpm3 = 'zpm3';
    case Zpm6 = 'zpm6';
    case Zpm5 = 'zpm5';
    case Zpm7 = 'zpm7';
    case Zpm8 = 'zpm8';

    public function label(): string
    {
        return match ($this) {
            self::Zpm1 => 'ZPM 1 - Corrective Maintenance',
            self::Zpm3 => 'ZPM 3 - Preventive Maintenance',
            self::Zpm6 => 'ZPM 6 - Planned Maintenance',
            self::Zpm5 => 'ZPM 5 - Fabrikasi',
            self::Zpm7 => 'ZPM 7 - Sipil',
            self::Zpm8 => 'ZPM 8 - Project',
        };
    }

    /**
     * Label singkat, dipakai di keyboard inline Telegram agar tombol
     * tidak terlalu panjang.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Zpm1 => 'ZPM 1',
            self::Zpm3 => 'ZPM 3',
            self::Zpm6 => 'ZPM 6',
            self::Zpm5 => 'ZPM 5',
            self::Zpm7 => 'ZPM 7',
            self::Zpm8 => 'ZPM 8',
        };
    }
}
