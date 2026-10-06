<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan pengiriman pengingat harian ruang panel kepada satu teknisi.
 *
 * Satu baris mewakili satu pengiriman per teknisi per tanggal. Baris ini yang
 * mencegah pengingat terkirim berulang saat perintah penjadwal berjalan tiap
 * menit (tanpa catatan ini, teknisi akan menerima pesan berkali-kali pada
 * menit-menit setelah jam masuk kerjanya).
 */
class PanelNotifikasiTerkirim extends Model
{
    protected $table = 'panel_notifikasi_terkirim';

    protected $fillable = [
        'technician_id',
        'tanggal',
        'jam_kerja',
        'jumlah_ruangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal'         => 'date',
            'jumlah_ruangan'  => 'integer',
        ];
    }

    /**
     * Teknisi penerima pengingat.
     *
     * @return BelongsTo<Technician, PanelNotifikasiTerkirim>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
