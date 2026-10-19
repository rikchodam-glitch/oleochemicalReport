<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Keadaan TERKINI satu kode material hasil integrasi PR/PO Spare Part.
 *
 * Satu baris mewakili satu kode material. Setiap kali import dijalankan,
 * baris material yang sudah ada DIPERBARUI (upsert berdasarkan kode material),
 * bukan dibuat baris baru per periode. Karena itu halaman awal dapat langsung
 * menampilkan tabel keadaan tanpa perlu membuka batch/rincian.
 *
 * Kolom yang tidak dipasok berkas pada import tertentu dibiarkan seperti
 * semula (import boleh hanya memuat sebagian berkas sumber).
 */
class SpMaterial extends Model
{
    protected $table = 'sp_material';

    protected $fillable = [
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
        'pr_terbuka',
        'pr_open_nums',
        'pr_nums',
        'req_list',
        'po_no',
        'po_sisa',
        'po_line',
        'po_uniq',
        'po_closed',
        'po_open',
        'po_open_nums',
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
        'mrp_stock',
        'mrp_safety',
        'mrp_reorder',
        'mrp_unit',
        'mrp_type',
        'mrp_cmp',
        'ket',
        'stok_diupdate_at',
        'pr_diupdate_at',
        'po_diupdate_at',
        'mrp_diupdate_at',
    ];

    /**
     * Tipe data kolom keadaan material.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stok_min'        => 'float',
            'stok_tersedia'   => 'float',
            'reorder'         => 'boolean',
            'pr_qty'          => 'float',
            'pr_line'         => 'integer',
            'pr_terbuka'      => 'integer',
            'po_sisa'         => 'float',
            'po_line'         => 'integer',
            'po_uniq'         => 'integer',
            'po_closed'       => 'integer',
            'po_open'         => 'integer',
            'po_sisa_total'   => 'float',
            'po_tanggal'      => 'date',
            'g_baris'         => 'integer',
            'g_awal'          => 'float',
            'g_masuk'         => 'float',
            'g_keluar'        => 'float',
            'g_sisa'          => 'float',
            'mrp_stock'       => 'float',
            'mrp_safety'      => 'float',
            'mrp_reorder'     => 'float',
            'stok_diupdate_at' => 'datetime',
            'pr_diupdate_at'  => 'datetime',
            'po_diupdate_at'  => 'datetime',
            'mrp_diupdate_at' => 'datetime',
        ];
    }

    /**
     * Label status reorder untuk tampilan.
     *
     * @return string  Label status (REORDER, Aman, atau Belum dinilai)
     */
    public function getLabelReorderAttribute(): string
    {
        if ($this->stok_min === null) {
            return 'Belum dinilai';
        }

        return $this->reorder ? 'REORDER' : 'Aman';
    }

    // ------------------------------------------------------------------
    // Nilai kolom aksi tindak lanjut (prioritas berurutan)
    // ------------------------------------------------------------------

    /** Aksi: masih ada PO yang belum selesai dikirim. */
    public const AKSI_KEJAR = 'KEJAR KEDATANGAN (PO OPEN)';

    /** Aksi: ada PR terbuka yang belum diterbitkan PO-nya. */
    public const AKSI_BUAT_PO = 'BUAT PO';

    /** Aksi: sudah ada PR tetapi tidak ada yang berstatus terbuka. */
    public const AKSI_CEK = 'CEK / BUAT PR BARU';

    /** Aksi: belum ada PR maupun PO untuk material ini. */
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

    /**
     * Label singkat satu nilai kolom aksi untuk kartu ringkasan dan lencana.
     *
     * @param  string|null $aksi Nilai kolom aksi
     * @return string            Label singkat
     */
    public static function labelAksi(?string $aksi): string
    {
        return match ($aksi) {
            self::AKSI_KEJAR   => 'Kejar Delivery',
            self::AKSI_BUAT_PO => 'Kejar PO',
            self::AKSI_CEK     => 'Cek / Buat PR',
            self::AKSI_BUAT_PR => 'Buat PR',
            default            => 'Tidak diketahui',
        };
    }

    /**
     * Label singkat kolom aksi milik baris ini.
     *
     * @return string  Label singkat aksi
     */
    public function getLabelAksiAttribute(): string
    {
        return self::labelAksi($this->aksi);
    }

    /**
     * Penjelasan singkat arti satu nilai kolom aksi untuk kartu ringkasan.
     *
     * Dipakai supaya angka kartu tidak salah dibaca: dashboard lama menyebut
     * "Belum PO" untuk material yang permintaan pembeliannya masih terbuka
     * tetapi PO-nya belum terbit (di sini AKSI_BUAT_PO), sedangkan jumlah
     * material yang SUDAH punya nomor PO adalah angka yang berbeda.
     *
     * @param  string|null $aksi Nilai kolom aksi
     * @return string            Penjelasan singkat
     */
    public static function keteranganAksi(?string $aksi): string
    {
        return match ($aksi) {
            self::AKSI_KEJAR   => 'PO sudah terbit dan masih terbuka; barang belum diterima lengkap sehingga delivery perlu dikejar.',
            self::AKSI_BUAT_PO => 'PR sudah terbit dan masih terbuka, tetapi PO belum dibuat; segera kejar pembuatan PO.',
            self::AKSI_CEK     => 'PR pernah dibuat tetapi tidak ada yang masih terbuka; periksa apakah PO-nya sudah terbit atau PR perlu dibuat ulang.',
            self::AKSI_BUAT_PR => 'Belum ada PR maupun PO untuk material ini.',
            default            => 'Belum ada keterangan untuk nilai aksi ini.',
        };
    }

}
