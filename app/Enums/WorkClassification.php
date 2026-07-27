<?php

namespace App\Enums;

/**
 * Klasifikasi pekerjaan yang dipilih manual oleh teknisi di step paling
 * awal wizard laporan, sebelum pencarian equipment berjalan. Berbeda dari
 * ReportType yang dideteksi otomatis dari hasil analisis AI.
 */
enum WorkClassification: string
{
    case CM        = 'cm';
    case PM        = 'pm';
    case DCM       = 'dcm';
    case Fabrikasi = 'fabrikasi';
    case Sipil     = 'sipil';

    public function label(): string
    {
        return match ($this) {
            self::CM        => 'CM (Corrective Maintenance)',
            self::PM        => 'PM (Preventif Maintenance)',
            self::DCM       => 'dCM (Deffered Corrective Maintenance)',
            self::Fabrikasi => 'Fabrikasi',
            self::Sipil     => 'Sipil',
        };
    }

    /**
     * Label singkat, dipakai di keyboard inline Telegram agar tombol
     * tidak terlalu panjang.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::CM        => 'CM',
            self::PM        => 'PM',
            self::DCM       => 'dCM',
            self::Fabrikasi => 'Fabrikasi',
            self::Sipil     => 'Sipil',
        };
    }
}
