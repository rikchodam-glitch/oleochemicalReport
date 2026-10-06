<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ruangan panel beserta perangkat sensor suhu/kelembapan yang terpasang.
 *
 * Satu baris mewakili satu ruangan panel dan satu perangkat ESP32 pembaca
 * sensor di dalamnya. Data pembacaan disimpan pada model PanelPembacaan.
 */
class PanelRuangan extends Model
{
    /**
     * Pilihan cara pembacaan perangkat sensor (kolom `protokol`).
     *
     * otomatis : deteksi sendiri - JSON API, lalu JSON TCP, lalu dashboard HTML
     * api-json : firmware JSON API (HTTP perangkat, /api/status dan /api/buffer)
     * tcp-json : sketch dashboard, JSON dari port TCP (bawaan 5000)
     * html     : sketch dashboard, angka dibaca dari dashboard HTML port 80
     */
    public const PROTOKOL = [
        'otomatis' => 'Otomatis (deteksi sendiri)',
        'api-json' => 'JSON API firmware (HTTP /api/status)',
        'tcp-json' => 'JSON TCP sketch dashboard (port 5000)',
        'html'     => 'Dashboard HTML sketch (port 80)',
    ];

    protected $table = 'panel_ruangan';

    protected $fillable = [
        'nama_ruangan',
        'pt_location',
        'plant',
        'keterangan',
        'ip_address',
        'port',
        'protokol',
        'mac_address',
        'aktif',
        'ambang_suhu_atas',
        'ambang_suhu_bawah',
        'status_terakhir',
        'terakhir_terlihat',
        'sesi_boot',
        'id_pembacaan_terakhir',
        'terakhir_ditarik',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'port' => 'integer',
            'ambang_suhu_atas' => 'float',
            'ambang_suhu_bawah' => 'float',
            'id_pembacaan_terakhir' => 'integer',
            'terakhir_terlihat' => 'datetime',
            'terakhir_ditarik' => 'datetime',
        ];
    }

    /**
     * Seluruh pembacaan milik ruangan ini.
     *
     * @return HasMany<PanelPembacaan, PanelRuangan>
     */
    public function pembacaan(): HasMany
    {
        return $this->hasMany(PanelPembacaan::class, 'panel_ruangan_id');
    }

    /**
     * Pembacaan paling baru milik ruangan ini.
     *
     * @return HasOne<PanelPembacaan, PanelRuangan>
     */
    public function pembacaanTerakhir(): HasOne
    {
        return $this->hasOne(PanelPembacaan::class, 'panel_ruangan_id')->latestOfMany('waktu');
    }

    /**
     * Batasi query hanya pada ruangan yang aktif ditarik datanya.
     *
     * @param  Builder<PanelRuangan>  $query
     * @return Builder<PanelRuangan>
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    /**
     * Label protokol ruangan ini untuk ditampilkan di halaman panel.
     *
     * @return string
     */
    public function labelProtokol(): string
    {
        $cara = (string) ($this->protokol ?? '');

        return self::PROTOKOL[$cara] ?? self::PROTOKOL['otomatis'];
    }

    /**
     * Alamat dasar HTTP perangkat sensor pada ruangan ini.
     *
     * @return string Contoh: http://192.168.41.240:80
     */
    public function urlPerangkat(): string
    {
        $port = $this->port ?: 80;

        return 'http://' . ($this->ip_address ?? '') . ':' . $port;
    }

    /**
     * Label lokasi ruangan untuk ditampilkan (PT dan Plant).
     *
     * @return string
     */
    public function labelLokasi(): string
    {
        $bagian = array_filter([$this->pt_location, $this->plant]);

        return $bagian === [] ? 'Lokasi belum diisi' : implode(' - ', $bagian);
    }

    /**
     * Apakah perangkat ruangan ini dianggap online.
     *
     * Dianggap online bila perangkat berhasil dihubungi (status terakhir
     * online) dan penarikan terakhir terjadi tidak lebih dari beberapa kali
     * interval polling. Nilai ini menggambarkan konektivitas perangkat,
     * bukan kesegaran data sensornya.
     *
     * @return bool
     */
    public function apakahOnline(): bool
    {
        if ($this->status_terakhir !== 'online') {
            return false;
        }

        $batasDetik = max(30, (int) config('panel.tampilan.interval_polling_detik', 5) * 6);
        $terakhir = $this->terakhir_ditarik;

        return $terakhir !== null && $terakhir->diffInSeconds(now()) <= $batasDetik;
    }

    /**
     * Tentukan kondisi suhu terhadap ambang batas ruangan ini.
     *
     * @param  float|null  $suhu  Nilai suhu yang diperiksa.
     * @return string normal, tinggi, rendah, atau tanpa-data.
     */
    public function kondisiSuhu(?float $suhu): string
    {
        if ($suhu === null) {
            return 'tanpa-data';
        }

        if ($this->ambang_suhu_atas !== null && $suhu > $this->ambang_suhu_atas) {
            return 'tinggi';
        }

        if ($this->ambang_suhu_bawah !== null && $suhu < $this->ambang_suhu_bawah) {
            return 'rendah';
        }

        return 'normal';
    }
}
