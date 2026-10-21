<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuti satu teknisi.
 *
 * Cuti yang dicatat Admin Reguler langsung berlaku dan langsung memengaruhi
 * target man hour; tidak ada status pengajuan/persetujuan.
 */
class CutiTeknisi extends Model
{
    public const JENIS = [
        'tahunan' => 'Cuti Tahunan',
        'sakit'   => 'Sakit',
        'izin'    => 'Izin',
        'dinas'   => 'Dinas Luar',
        'lainnya' => 'Lainnya',
    ];

    /**
     * Kolom yang boleh diisi massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'technician_id',
        'tanggal_mulai',
        'tanggal_selesai',
        'jenis',
        'alasan',
        'diajukan_via',
        'disetujui_oleh',
        'disetujui_at',
        'catatan_approver',
    ];

    /**
     * Cast kolom tanggal/waktu.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_mulai'   => 'date',
            'tanggal_selesai' => 'date',
            'disetujui_at'    => 'datetime',
        ];
    }

    /**
     * Teknisi yang mengajukan cuti.
     *
     * @return BelongsTo<Technician, CutiTeknisi>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Admin yang menyetujui/menolak.
     *
     * @return BelongsTo<User, CutiTeknisi>
     */
    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    /**
     * Label jenis cuti.
     *
     * @return string
     */
    public function labelJenis(): string
    {
        return self::JENIS[$this->jenis] ?? ucfirst((string) $this->jenis);
    }

    /**
     * Jumlah hari kalender pada cuti.
     *
     * @return int
     */
    public function jumlahHari(): int
    {
        if ($this->tanggal_mulai === null || $this->tanggal_selesai === null) {
            return 0;
        }

        return (int) $this->tanggal_mulai->diffInDays($this->tanggal_selesai) + 1;
    }
}
