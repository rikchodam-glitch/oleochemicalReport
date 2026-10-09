<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris hasil hitung Integrasi PR/PO Spare Part untuk satu material.
 *
 * Isinya gabungan tiga hal:
 * 1. monitoring stok (stok minimum, stok tersedia, penanda REORDER),
 * 2. pemilihan dokumen PR/PO yang dianggap paling relevan beserta statusnya,
 * 3. rekap stok gudang (G001) dan nilai MRP (MD07) sebagai pembanding.
 *
 * Aturan pengisian kolom `aksi` mengikuti prioritas berikut:
 * 1. KEJAR KEDATANGAN (PO OPEN) - ada PO yang masih terbuka,
 * 2. BUAT PO - ada PR terbuka yang belum menjadi PO,
 * 3. CEK / BUAT PR BARU - ada PR tetapi tidak ada yang terbuka,
 * 4. BUAT PR - belum ada PR maupun PO sama sekali.
 */
class SpIntegrasi extends Model
{
    protected $table = 'sp_integrasi';

    /**
     * AKSI: masih ada PO yang belum selesai dikirim.
     */
    public const AKSI_KEJAR = 'KEJAR KEDATANGAN (PO OPEN)';

    /**
     * AKSI: ada PR terbuka yang belum diterbitkan PO-nya.
     */
    public const AKSI_BUAT_PO = 'BUAT PO';

    /**
     * AKSI: sudah ada PR tetapi tidak ada yang berstatus terbuka.
     */
    public const AKSI_CEK = 'CEK / BUAT PR BARU';

    /**
     * AKSI: belum ada PR maupun PO untuk material ini.
     */
    public const AKSI_BUAT_PR = 'BUAT PR';

    /**
     * Seluruh nilai kolom aksi beserta urutan tampilannya.
     *
     * @var array<int, string>
     */
    public const AKSI = [
        self::AKSI_KEJAR,
        self::AKSI_BUAT_PO,
        self::AKSI_CEK,
        self::AKSI_BUAT_PR,
    ];

    protected $fillable = [
        'batch_id',
        'kode_material',
        'deskripsi',
        'stok_min',
        'stok_tersedia',
        'reorder',
        'penanda',
        'aksi',
        'pr_no',
        'pr_status_aksi',
        'pr_status',
        'pr_qty',
        'pr_line',
        'pr_nums',
        'req_list',
        'po_no',
        'po_sisa',
        'po_line',
        'po_uniq',
        'po_closed',
        'po_open',
        'po_sisa_total',
        'po_tanggal',
        'po_tanggal_teks',
        'po_nums',
        'purch',
        'vendor',
        'po_prev',
        'vendor_prev',
        'purch_last',
        'vendor_last',
        'g_baris',
        'g_awal',
        'g_masuk',
        'g_keluar',
        'g_sisa',
        'g_satuan',
        'g_lokasi',
        'mrp_plant',
        'mrp_safety',
        'mrp_reorder',
        'min_cmp',
        'ket',
    ];

    /**
     * Tipe data kolom hasil integrasi.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stok_min'      => 'float',
            'stok_tersedia' => 'float',
            'reorder'       => 'boolean',
            'pr_qty'        => 'float',
            'pr_line'       => 'integer',
            'po_sisa'       => 'float',
            'po_line'       => 'integer',
            'po_uniq'       => 'integer',
            'po_closed'     => 'integer',
            'po_open'       => 'integer',
            'po_sisa_total' => 'float',
            'po_tanggal'    => 'date',
            'g_baris'       => 'integer',
            'g_awal'        => 'float',
            'g_masuk'       => 'float',
            'g_keluar'      => 'float',
            'g_sisa'        => 'float',
            'mrp_plant'     => 'float',
            'mrp_safety'    => 'float',
            'mrp_reorder'   => 'float',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpIntegrasi>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }

    /**
     * Menyaring hasil ke batch tertentu, dipakai seluruh halaman modul.
     *
     * @param  Builder<SpIntegrasi> $query Query yang sedang dibangun
     * @param  int                  $id    Id batch
     * @return Builder<SpIntegrasi>        Query yang sudah disaring
     */
    public function scopeBatch(Builder $query, int $id): Builder
    {
        return $query->where('batch_id', $id);
    }

    /**
     * Menyaring baris yang perlu ditindaklanjuti (penanda REORDER).
     *
     * @param  Builder<SpIntegrasi> $query Query yang sedang dibangun
     * @return Builder<SpIntegrasi>        Query yang sudah disaring
     */
    public function scopeReorder(Builder $query): Builder
    {
        return $query->where('reorder', true);
    }

    /**
     * Label singkat kolom aksi untuk kartu ringkasan dan lencana tabel.
     *
     * @return string  Label aksi (misalnya "Kejar PO", "Buat PO")
     */
    public function getLabelAksiAttribute(): string
    {
        return self::labelAksi($this->aksi);
    }

    /**
     * Label singkat untuk satu nilai aksi.
     *
     * @param  string|null $aksi Nilai kolom aksi
     * @return string             Label singkat
     */
    public static function labelAksi(?string $aksi): string
    {
        return match ($aksi) {
            self::AKSI_KEJAR   => 'Kejar PO',
            self::AKSI_BUAT_PO => 'Buat PO',
            self::AKSI_CEK     => 'Cek / Buat PR',
            self::AKSI_BUAT_PR => 'Buat PR',
            default            => 'Tidak diketahui',
        };
    }
}
