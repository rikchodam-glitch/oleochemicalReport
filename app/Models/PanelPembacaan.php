<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pembacaan suhu dan kelembapan dari perangkat sensor ruangan panel.
 *
 * Perangkat tidak punya jam sendiri (tidak ada RTC), sehingga kolom waktu
 * dihitung dari selisih uptime perangkat pada saat data ditarik server.
 */
class PanelPembacaan extends Model
{
    protected $table = 'panel_pembacaan';

    protected $fillable = [
        'panel_ruangan_id',
        'waktu',
        'suhu',
        'kelembapan',
        'valid',
        'sesi_boot',
        'id_perangkat',
        'sumber',
    ];

    protected function casts(): array
    {
        return [
            'waktu' => 'datetime',
            'suhu' => 'float',
            'kelembapan' => 'float',
            'valid' => 'boolean',
            'id_perangkat' => 'integer',
        ];
    }

    /**
     * Ruangan panel pemilik pembacaan ini.
     *
     * @return BelongsTo<PanelRuangan, PanelPembacaan>
     */
    public function panelRuangan(): BelongsTo
    {
        return $this->belongsTo(PanelRuangan::class, 'panel_ruangan_id');
    }

    /**
     * Teks ringkas pembacaan untuk ditampilkan di tabel.
     *
     * @return string Contoh: 28.5 C / 61.0 %
     */
    public function ringkasan(): string
    {
        $suhu = $this->suhu === null ? '-' : number_format($this->suhu, 1);
        $kelembapan = $this->kelembapan === null ? '-' : number_format($this->kelembapan, 1);

        return $suhu . ' C / ' . $kelembapan . ' %';
    }
}
