<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali proses import Integrasi PR/PO Spare Part (header batch).
 *
 * Seluruh tabel mentah (sp_monitoring, sp_g001, sp_pr, sp_po, sp_mrp) dan
 * tabel hasil (sp_integrasi) selalu terikat pada satu batch.
 */
class SpBatch extends Model
{
    protected $table = 'sp_batch';

    protected $fillable = [
        'periode',
        'folder',
        'status',
        'ringkasan',
        'jumlah_monitoring',
        'jumlah_g001',
        'jumlah_pr',
        'jumlah_po',
        'jumlah_mrp',
        'jumlah_hasil',
        'pesan',
        'oleh',
        'selesai_at',
    ];

    /**
     * Tipe data kolom batch.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ringkasan'         => 'array',
            'jumlah_monitoring' => 'integer',
            'jumlah_g001'       => 'integer',
            'jumlah_pr'         => 'integer',
            'jumlah_po'         => 'integer',
            'jumlah_mrp'        => 'integer',
            'jumlah_hasil'      => 'integer',
            'selesai_at'        => 'datetime',
        ];
    }

    /**
     * Baris monitoring (stok min / stok tersedia) milik batch ini.
     *
     * @return HasMany<SpMonitoring>
     */
    public function monitoring(): HasMany
    {
        return $this->hasMany(SpMonitoring::class, 'batch_id');
    }

    /**
     * Baris stok gudang G001 milik batch ini.
     *
     * @return HasMany<SpG001>
     */
    public function stokGudang(): HasMany
    {
        return $this->hasMany(SpG001::class, 'batch_id');
    }

    /**
     * Baris permintaan pembelian (PR) milik batch ini.
     *
     * @return HasMany<SpPr>
     */
    public function pr(): HasMany
    {
        return $this->hasMany(SpPr::class, 'batch_id');
    }

    /**
     * Baris pesanan pembelian (PO) milik batch ini.
     *
     * @return HasMany<SpPo>
     */
    public function po(): HasMany
    {
        return $this->hasMany(SpPo::class, 'batch_id');
    }

    /**
     * Baris MRP (MD07) milik batch ini.
     *
     * @return HasMany<SpMrp>
     */
    public function mrp(): HasMany
    {
        return $this->hasMany(SpMrp::class, 'batch_id');
    }

    /**
     * Baris hasil hitung integrasi milik batch ini.
     *
     * @return HasMany<SpIntegrasi>
     */
    public function hasil(): HasMany
    {
        return $this->hasMany(SpIntegrasi::class, 'batch_id');
    }

    /**
     * User yang menjalankan import.
     *
     * @return BelongsTo<User, SpBatch>
     */
    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh');
    }

    /**
     * Label status batch dalam Bahasa Indonesia.
     *
     * @return string  Label status (Diproses, Selesai, atau Gagal)
     */
    public function getLabelStatusAttribute(): string
    {
        return match ($this->status) {
            'selesai' => 'Selesai',
            'gagal'   => 'Gagal',
            default   => 'Diproses',
        };
    }
}
