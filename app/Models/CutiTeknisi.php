<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan cuti satu teknisi.
 *
 * Cuti hanya mengurangi target man hour setelah berstatus "disetujui".
 * Pengajuan "pending" tetap disimpan agar admin dapat memeriksa/menyetujui.
 */
class CutiTeknisi extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_DISETUJUI  = 'disetujui';
    public const STATUS_DITOLAK    = 'ditolak';
    public const STATUS_DIBATALKAN = 'dibatalkan';

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
        'status',
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
     * Scope cuti yang sudah disetujui.
     *
     * @param  Builder<CutiTeknisi> $query
     * @return Builder<CutiTeknisi>
     */
    public function scopeDisetujui(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISETUJUI);
    }

    /**
     * Scope cuti yang masih menunggu persetujuan.
     *
     * @param  Builder<CutiTeknisi> $query
     * @return Builder<CutiTeknisi>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
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
     * Label status pengajuan.
     *
     * @return string
     */
    public function labelStatus(): string
    {
        return match ($this->status) {
            self::STATUS_DISETUJUI  => 'Disetujui',
            self::STATUS_DITOLAK    => 'Ditolak',
            self::STATUS_DIBATALKAN => 'Dibatalkan',
            default                 => 'Menunggu',
        };
    }

    /**
     * Jumlah hari kalender pada pengajuan.
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
