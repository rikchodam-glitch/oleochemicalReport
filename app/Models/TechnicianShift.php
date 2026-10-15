<?php

namespace App\Models;

use App\Services\Shift\JamKerjaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kode shift satu teknisi pada satu tanggal, hasil import jadwal shift.
 *
 * Kode yang dikenal: 'x' (OFF), '1' (Shift 1 08.00-16.00),
 * '2' (Shift 2 16.00-24.00), '3' (Shift 3 00.00-08.00),
 * 'R' (reguler / non-shift).
 */
class TechnicianShift extends Model
{
    public const KODE_OFF     = 'x';
    public const KODE_REGULER = 'R';

    protected $fillable = [
        'shift_schedule_id',
        'technician_id',
        'nik',
        'nama',
        'group_code',
        'section_label',
        'shift_date',
        'shift_code',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
        ];
    }

    /**
     * Teknisi pemilik jadwal (null bila NIK belum terdaftar di aplikasi).
     *
     * @return BelongsTo<Technician, TechnicianShift>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Riwayat import yang menghasilkan baris ini.
     *
     * @return BelongsTo<ShiftSchedule, TechnicianShift>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id');
    }

    /**
     * Apakah tanggal ini hari OFF menurut jadwal.
     *
     * @return bool  True bila kode shift OFF
     */
    public function isOff(): bool
    {
        return strtolower($this->shift_code) === self::KODE_OFF;
    }

    /**
     * Kode shift huruf besar untuk perbandingan ('x', '1', '2', '3', 'R').
     *
     * @return string  Kode shift ternormalisasi
     */
    public function kode(): string
    {
        $kode = strtoupper(trim((string) $this->shift_code));

        return $kode === '' ? self::KODE_REGULER : $kode;
    }

    /**
     * Label shift untuk tampilan, mis. "Shift 1 (08.00-16.00)", "OFF", atau
     * "Reguler"/"Reguler Staff" untuk teknisi non-shift.
     *
     * @param  string|null $grupTeknisi Group teknisi (dipakai untuk kode 'R')
     * @return string                   Label shift
     */
    public function label(?string $grupTeknisi = null): string
    {
        $kode = strtoupper(trim((string) $this->shift_code));

        if (strtolower($kode) === self::KODE_OFF) {
            return 'OFF';
        }

        $windows = (array) config('cm.man_hour.windows', []);

        if (isset($windows[$kode])) {
            return sprintf(
                'Shift %s (%s-%s)',
                $kode,
                str_replace(':', '.', (string) $windows[$kode]['mulai']),
                str_replace(':', '.', (string) $windows[$kode]['selesai'])
            );
        }

        if ($kode === self::KODE_REGULER) {
            return app(JamKerjaService::class)->labelGroup($this->grupBerlaku($grupTeknisi));
        }

        return 'Kode ' . $kode;
    }

    /**
     * Target menit kerja pada tanggal ini: 0 untuk hari OFF (dan hari libur
     * teknisi non-shift), 480 untuk hari shift, dan jam kerja dikurangi
     * istirahat untuk kode 'R' (reguler / reguler staff).
     *
     * @param  string|null $grupTeknisi Group teknisi (dipakai untuk kode 'R')
     * @return int                      Target menit kerja
     */
    public function targetMenit(?string $grupTeknisi = null): int
    {
        if ($this->isOff()) {
            return 0;
        }

        $tanggal = $this->shift_date ?? now();

        // Hari libur nasional: target 0 untuk SEMUA grup (pekerja shift tetap
        // wajib masuk sesuai rotasi, tetapi hasilnya dihitung lembur).
        if (app(JamKerjaService::class)->apakahHariLibur($tanggal)) {
            return 0;
        }

        $kode = $this->kode();

        if (in_array($kode, ['1', '2', '3'], true)) {
            return (int) config('cm.man_hour.target_minutes', 480);
        }

        return app(JamKerjaService::class)->targetMenit(
            $this->grupBerlaku($grupTeknisi),
            $tanggal
        );
    }

    /**
     * Jendela waktu kerja yang berlaku pada tanggal ini (jam mulai & selesai),
     * dipakai untuk memeriksa apakah laporan dibuat di dalam jam kerja.
     *
     * @param  string|null $grupTeknisi Group teknisi (dipakai untuk kode 'R')
     * @return array{mulai: string, selesai: string}|null  Jendela waktu atau null bila OFF/libur
     */
    public function jendela(?string $grupTeknisi = null): ?array
    {
        if ($this->isOff()) {
            return null;
        }

        $tanggal = $this->shift_date ?? now();

        // Hari libur nasional: tidak ada jendela kerja, sehingga seluruh jam
        // yang dilaporkan pada tanggal itu dihitung lembur.
        if (app(JamKerjaService::class)->apakahHariLibur($tanggal)) {
            return null;
        }

        $kode    = $this->kode();
        $windows = (array) config('cm.man_hour.windows', []);

        if (isset($windows[$kode])) {
            return $windows[$kode];
        }

        return app(JamKerjaService::class)->jendela(
            $this->grupBerlaku($grupTeknisi),
            $tanggal
        );
    }

    /**
     * Group teknisi yang dipakai untuk menghitung target: dari parameter, atau
     * dari relasi teknisi, atau fallback 'reguler'.
     *
     * @param  string|null $grupTeknisi Group dari pemanggil (opsional)
     * @return string                   Group yang berlaku
     */
    private function grupBerlaku(?string $grupTeknisi): string
    {
        if ($grupTeknisi !== null && $grupTeknisi !== '') {
            return $grupTeknisi;
        }

        return (string) ($this->technician?->group ?? 'reguler');
    }

    /**
     * Scope: filter satu tanggal tertentu.
     *
     * @param  Builder $query Query builder
     * @param  string  $tanggal Tanggal format Y-m-d
     * @return Builder         Query builder
     */
    public function scopeUntukTanggal(Builder $query, string $tanggal): Builder
    {
        return $query->whereDate('shift_date', $tanggal);
    }

    /**
     * Scope: filter rentang tanggal (inklusif).
     *
     * Batas memakai jam 00:00:00 dan 23:59:59 supaya tetap benar baik di MySQL
     * (kolom DATE) maupun SQLite (kolom date tersimpan sebagai datetime).
     *
     * @param  Builder $query Query builder
     * @param  string  $mulai Tanggal awal Y-m-d
     * @param  string  $selesai Tanggal akhir Y-m-d
     * @return Builder         Query builder
     */
    public function scopeAntara(Builder $query, string $mulai, string $selesai): Builder
    {
        return $query->whereBetween('shift_date', [$mulai . ' 00:00:00', $selesai . ' 23:59:59']);
    }
}
