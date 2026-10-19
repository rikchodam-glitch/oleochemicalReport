<?php

namespace App\Services\Sparepart;

use App\Models\SpImpor;
use App\Models\SpMaterial;
use App\Models\SpPantau;
use App\Services\Sparepart\Concerns\SparepartHitungTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Import berkas Integrasi PR/PO Spare Part.
 *
 * Alur satu kali import:
 * 1. baca berkas yang diunggah (format dikunci SparepartReader; berkas salah
 *    format membatalkan import SEBELUM ada baris database yang ditulis),
 * 2. upsert keadaan per kode material pada tabel sp_material (hanya kode yang
 *    ada di daftar pantau),
 * 3. hitung ulang status REORDER dan kolom aksi,
 * 4. simpan analisa import pada tabel sp_impor.
 *
 * Berkas boleh sebagian: bagian yang tidak diunggah memakai nilai terakhir
 * yang tersimpan, sehingga update mingguan cukup mengunggah berkas yang
 * berubah.
 */
class SparepartImportService
{
    use SparepartHitungTrait;

    /** Kunci berkas sumber laporan stok gudang. */
    public const SUMBER_STOK = 'stok';

    /** Kunci berkas sumber EXPORT PR (SAP ME5A). */
    public const SUMBER_PR = 'pr';

    /** Kunci berkas sumber EXPORT PO (SAP ME2M). */
    public const SUMBER_PO = 'po';

    /** Kunci berkas sumber EXPORT MRP (SAP MD07). */
    public const SUMBER_MRP = 'mrp';

    /** Nama berkas tiap sumber untuk kalimat catatan analisa. */
    private const LABEL_BERKAS = [
        self::SUMBER_STOK => 'laporan stok gudang',
        self::SUMBER_PR   => 'EXPORT PR',
        self::SUMBER_PO   => 'EXPORT PO',
        self::SUMBER_MRP  => 'EXPORT MRP',
    ];

    /**
     * Cakupan berkas sumber di bawah angka ini (persen dari daftar pantau)
     * dianggap cuplikan sebagian bulan, bukan ekspor riwayat penuh.
     */
    private const AMBANG_CAKUPAN_SEMPIT = 50;

    /**
     * Kolom tabel sp_material yang disalin dari baris lama bila berkas
     * sumbernya TIDAK ikut diunggah pada import ini.
     */
    private const BLOK_STOK = [
        'stok_tersedia', 'g_baris', 'g_awal', 'g_masuk', 'g_keluar', 'g_sisa', 'g_satuan', 'g_lokasi',
    ];

    private const BLOK_PR = [
        'pr_no', 'pr_status_aksi', 'pr_status', 'pr_qty', 'pr_line', 'pr_terbuka', 'pr_nums', 'req_list',
    ];

    private const BLOK_PO = [
        'po_no', 'po_sisa', 'po_line', 'po_uniq', 'po_closed', 'po_open', 'po_open_nums', 'po_sisa_total',
        'po_tanggal', 'po_tanggal_teks', 'po_nums', 'purch', 'vendor', 'po_prev', 'vendor_prev',
        'purch_last', 'vendor_last',
    ];

    private const BLOK_MRP = [
        'mrp_stock', 'mrp_safety', 'mrp_reorder', 'mrp_unit', 'mrp_type', 'mrp_cmp', 'mrp_diupdate_at',
    ];

    /**
     * @param  SparepartReader $pembaca Pembaca berkas sumber
     */
    public function __construct(private SparepartReader $pembaca)
    {
    }

    /**
     * Menjalankan satu kali import.
     *
     * @param  array<string, string|null> $berkas Path berkas per kunci sumber (boleh sebagian)
     * @param  int|null                   $oleh   Id pengguna yang menjalankan import
     * @param  string|null                $label  Catatan label import
     * @return SpImpor                            Riwayat import yang sudah selesai
     *
     * @throws RuntimeException Bila tidak ada berkas yang bisa dibaca
     */
    public function import(array $berkas, ?int $oleh = null, ?string $label = null): SpImpor
    {
        $dipakai = [];

        foreach ([self::SUMBER_STOK, self::SUMBER_PR, self::SUMBER_PO, self::SUMBER_MRP] as $kunci) {
            $path = $berkas[$kunci] ?? null;

            if (is_string($path) && $path !== '' && is_file($path)) {
                $dipakai[$kunci] = $path;
            }
        }

        if ($dipakai === []) {
            throw new RuntimeException('Tidak ada berkas yang bisa dibaca. Pilih minimal satu berkas sumber.');
        }

        $baca  = $this->baca($dipakai);
        $impor = SpImpor::create([
            'label'  => $label,
            'status' => SpImpor::STATUS_PROSES,
            'oleh'   => $oleh,
        ]);

        try {
            $hasil = DB::transaction(fn (): array => $this->jalankan($baca));
        } catch (Throwable $e) {
            $impor->update([
                'status' => SpImpor::STATUS_GAGAL,
                'pesan'  => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        $analisa = $hasil['analisa'];

        $impor->update([
            'berkas'         => $analisa['sumber'],
            'analisa'        => $analisa,
            'status'         => SpImpor::STATUS_SELESAI,
            'jumlah_pantau'  => (int) ($analisa['pantau']['total'] ?? 0),
            'jumlah_stok'    => (int) ($analisa['sumber'][self::SUMBER_STOK]['baris'] ?? 0),
            'jumlah_pr'      => (int) ($analisa['sumber'][self::SUMBER_PR]['baris'] ?? 0),
            'jumlah_po'      => (int) ($analisa['sumber'][self::SUMBER_PO]['baris'] ?? 0),
            'jumlah_mrp'     => (int) ($analisa['sumber'][self::SUMBER_MRP]['baris'] ?? 0),
            'jumlah_keadaan' => (int) ($analisa['keadaan']['baru'] ?? 0) + (int) ($analisa['keadaan']['diperbarui'] ?? 0),
            'selesai_at'     => Carbon::now(),
        ]);

        return $impor->refresh();
    }

    /**
     * Membaca seluruh berkas yang diunggah memakai pembaca berbasis format.
     *
     * @param  array<string, string> $dipakai Path berkas per kunci sumber
     * @return array<string, array<string, mixed>> Hasil baca per kunci sumber
     */
    private function baca(array $dipakai): array
    {
        $baca = [];

        foreach ($dipakai as $kunci => $path) {
            $baca[$kunci] = match ($kunci) {
                self::SUMBER_STOK => $this->pembaca->bacaStok($path),
                self::SUMBER_PR   => $this->pembaca->bacaPr($path),
                self::SUMBER_PO   => $this->pembaca->bacaPo($path),
                self::SUMBER_MRP  => $this->pembaca->bacaMrp($path),
            };

            $baca[$kunci]['nama'] = basename($path);
        }

        return $baca;
    }

    /**
     * Inti import: upsert keadaan material dan menyusun analisa.
     *
     * @param  array<string, array<string, mixed>> $baca Hasil baca berkas per kunci sumber
     * @return array<string, mixed>                      Analisa hasil import
     */
    private function jalankan(array $baca): array
    {
        $kini = Carbon::now();

        $pantau = SpPantau::query()->where('aktif', true)->get()->keyBy('kode_material');
        $lama   = SpMaterial::query()->get()->keyBy('kode_material');

        $adaStok = array_key_exists(self::SUMBER_STOK, $baca);
        $adaPr   = array_key_exists(self::SUMBER_PR, $baca);
        $adaPo   = array_key_exists(self::SUMBER_PO, $baca);
        $adaMrp  = array_key_exists(self::SUMBER_MRP, $baca);

        $stok = $adaStok ? (array) $baca[self::SUMBER_STOK]['data'] : [];
        $pr   = $adaPr ? (array) $baca[self::SUMBER_PR]['data'] : [];
        $po   = $adaPo ? (array) $baca[self::SUMBER_PO]['data'] : [];
        $mrp  = $adaMrp ? (array) $baca[self::SUMBER_MRP]['data'] : [];

        $kodePantau = $pantau->keys()->all();

        // Cakupan setiap berkas terhadap daftar pantau dipakai untuk mengenali
        // berkas cuplikan (mis. ekspor PR/PO bulan berjalan) yang membuat kolom
        // Aksi tampak seperti seluruh material meminta pengadaan baru.
        $cakupan = [
            self::SUMBER_STOK => $this->cakupanSumber($adaStok, $stok, $kodePantau),
            self::SUMBER_PR   => $this->cakupanSumber($adaPr, $pr, $kodePantau),
            self::SUMBER_PO   => $this->cakupanSumber($adaPo, $po, $kodePantau),
            self::SUMBER_MRP  => $this->cakupanSumber($adaMrp, $mrp, $kodePantau),
        ];

        $analisa = [
            'sumber'      => $this->analisaSumber($baca),
            'pantau'      => [
                'total'              => $pantau->count(),
                'sumber'             => $cakupan,
                'bercatatan_stok'    => $cakupan[self::SUMBER_STOK]['punya'] ?? null,
                'tanpa_catatan_stok' => $cakupan[self::SUMBER_STOK]['tanpa'] ?? null,
            ],
            'luar_pantau' => [],
            'keadaan'     => ['baru' => 0, 'diperbarui' => 0, 'tetap' => 0],
            'status'      => ['reorder' => 0, 'aman' => 0, 'belum_dinilai' => 0, 'naik_reorder' => [], 'turun_aman' => []],
            'aksi'        => [],
            'catatan'     => [],
        ];

        foreach (SpMaterial::AKSI as $nilai) {
            $analisa['aksi'][$nilai] = 0;
        }

        foreach ([self::SUMBER_STOK => $stok, self::SUMBER_PR => $pr, self::SUMBER_PO => $po, self::SUMBER_MRP => $mrp] as $sumber => $data) {
            if (! array_key_exists($sumber, $baca)) {
                continue;
            }

            $luar = array_values(array_map(
                fn (mixed $k): string => (string) $k,
                array_diff(array_keys($data), $kodePantau)
            ));

            if ($luar !== []) {
                $analisa['catatan'][] = count($luar) . ' material pada berkas ' . $sumber
                    . ' tidak ada di daftar pantau sehingga tidak ikut dihitung.';
            }

            $analisa['luar_pantau'][$sumber] = [
                'jumlah' => count($luar),
                'contoh' => array_slice($luar, 0, 20),
            ];
        }

        foreach ($cakupan as $sumber => $barisCakupan) {
            if ($barisCakupan === null || $barisCakupan['tanpa'] === 0) {
                continue;
            }

            $analisa['catatan'][] = $this->catatanCakupan((string) $sumber, $barisCakupan, $pantau->count());
        }

        $tulis = [];

        foreach ($pantau as $kode => $barisPantau) {
            $sebelum = $lama->get($kode);

            $payload = $this->keadaanPantau(
                (string) $kode,
                $barisPantau,
                $sebelum,
                $adaStok ? ($stok[$kode] ?? null) : null,
                $adaStok,
                $adaPr ? (array) ($pr[$kode] ?? []) : [],
                $adaPr,
                $adaPo ? (array) ($po[$kode] ?? []) : [],
                $adaPo,
                $adaMrp ? ($mrp[$kode] ?? null) : null,
                $adaMrp,
                $kini
            );

            $this->catatPerubahan($analisa, $payload, $sebelum);

            $tulis[] = $payload;
        }

        if ($tulis !== []) {
            SpMaterial::upsert(
                $tulis,
                ['kode_material'],
                array_values(array_diff(array_keys($tulis[0]), ['kode_material', 'created_at']))
            );
        }

        return ['analisa' => $analisa];
    }

    /**
     * Menyusun nilai satu baris sp_material untuk satu kode pantau.
     *
     * Bagian berkas yang TIDAK diunggah pada import ini disalin dari baris
     * lama (nilai terakhir), lalu penanda, status reorder, kolom aksi, dan
     * keterangan dihitung ulang dari gabungan sumber yang sudah lengkap.
     *
     * @param  string                           $kode      Kode material
     * @param  SpPantau                         $pantau    Baris daftar pantau material
     * @param  SpMaterial|null                  $sebelum   Keadaan sebelumnya (null bila material baru)
     * @param  array<string, mixed>|null        $g         Ringkasan stok gudang material
     * @param  bool                             $adaStok   Apakah berkas stok gudang diunggah
     * @param  array<int, array<string, mixed>> $prBaris   Baris PR material
     * @param  bool                             $adaPr     Apakah berkas PR diunggah
     * @param  array<int, array<string, mixed>> $poBaris   Baris PO material
     * @param  bool                             $adaPo     Apakah berkas PO diunggah
     * @param  array<string, mixed>|null        $mrp       Baris MRP material
     * @param  bool                             $adaMrp    Apakah berkas MRP diunggah
     * @param  Carbon                           $kini      Cap waktu import
     * @return array<string, mixed>                        Baris siap upsert
     */
    private function keadaanPantau(
        string $kode,
        SpPantau $pantau,
        ?SpMaterial $sebelum,
        ?array $g,
        bool $adaStok,
        array $prBaris,
        bool $adaPr,
        array $poBaris,
        bool $adaPo,
        ?array $mrp,
        bool $adaMrp,
        Carbon $kini
    ): array {
        $stokTersedia = $adaStok ? ($g === null ? null : (float) $g['sisa']) : null;

        $payload = $this->keadaanMaterial(
            $kode,
            $pantau->deskripsi,
            $pantau->stok_min,
            $stokTersedia,
            $adaStok ? $g : null,
            $prBaris,
            $poBaris,
            $mrp
        );

        if ($sebelum !== null) {
            $payload['deskripsi'] = $payload['deskripsi'] ?? $sebelum->deskripsi;
        }

        if (! $adaStok && $sebelum !== null) {
            foreach (self::BLOK_STOK as $kolom) {
                $payload[$kolom] = $sebelum->getAttribute($kolom);
            }
        }

        if (! $adaPr && $sebelum !== null) {
            foreach (self::BLOK_PR as $kolom) {
                $payload[$kolom] = $sebelum->getAttribute($kolom);
            }
        }

        if (! $adaPo && $sebelum !== null) {
            foreach (self::BLOK_PO as $kolom) {
                $payload[$kolom] = $sebelum->getAttribute($kolom);
            }
        }

        if (! $adaMrp && $sebelum !== null) {
            foreach (self::BLOK_MRP as $kolom) {
                $payload[$kolom] = $sebelum->getAttribute($kolom);
            }
        }

        $prTerbuka  = (int) ($payload['pr_terbuka'] ?? 0) > 0;
        $adaPrBaris = (int) ($payload['pr_line'] ?? 0) > 0;
        $adaPoBaris = (int) ($payload['po_line'] ?? 0) > 0;
        $poTerbuka  = (int) ($payload['po_open'] ?? 0) > 0;

        $payload['aksi'] = $poTerbuka
            ? SpMaterial::AKSI_KEJAR
            : ($prTerbuka
                ? SpMaterial::AKSI_BUAT_PO
                : ($adaPrBaris ? SpMaterial::AKSI_CEK : SpMaterial::AKSI_BUAT_PR));

        $payload['reorder'] = ($payload['stok_min'] !== null
            && (float) ($payload['stok_tersedia'] ?? 0.0) <= (float) $payload['stok_min']) ? 1 : 0;

        $penanda = [];

        if ($payload['stok_tersedia'] === null) {
            $penanda[] = 'tanpa catatan stok gudang';
        }

        if ($payload['stok_min'] === null) {
            $penanda[] = 'stok minimum belum diisi';
        }

        $payload['penanda'] = $this->potong(implode('; ', $penanda), self::BATAS_PENANDA);

        $payload['ket'] = ($adaPrBaris || $adaPoBaris)
            ? 'Sudah ada PR/PO'
            : ($payload['reorder'] ? 'REORDER - belum ada PR/PO' : 'AMAN');

        $payload['stok_diupdate_at'] = $adaStok ? $kini : $sebelum?->stok_diupdate_at;
        $payload['pr_diupdate_at']   = $adaPr ? $kini : $sebelum?->pr_diupdate_at;
        $payload['po_diupdate_at']   = $adaPo ? $kini : $sebelum?->po_diupdate_at;
        $payload['mrp_diupdate_at']  = $adaMrp ? $kini : $sebelum?->mrp_diupdate_at;
        $payload['created_at']       = $sebelum?->created_at ?? $kini;
        $payload['updated_at']       = $kini;

        return $payload;
    }

    /**
     * Mencatat perubahan satu material ke dalam analisa import.
     *
     * @param  array<string, mixed> $analisa Analisa yang sedang disusun (referensi)
     * @param  array<string, mixed> $payload Nilai baru material
     * @param  SpMaterial|null      $sebelum Keadaan lama material
     * @return void
     */
    private function catatPerubahan(array &$analisa, array $payload, ?SpMaterial $sebelum): void
    {
        $kode = (string) $payload['kode_material'];

        if ($payload['stok_min'] === null) {
            $analisa['status']['belum_dinilai']++;
        } elseif ($payload['reorder']) {
            $analisa['status']['reorder']++;
        } else {
            $analisa['status']['aman']++;
        }

        $analisa['aksi'][$payload['aksi']] = (int) ($analisa['aksi'][$payload['aksi']] ?? 0) + 1;

        if ($sebelum === null) {
            $analisa['keadaan']['baru']++;

            return;
        }

        $lewati  = ['created_at', 'updated_at', 'stok_diupdate_at', 'pr_diupdate_at', 'po_diupdate_at', 'po_tanggal'];
        $berubah = false;

        foreach (array_keys($payload) as $kolom) {
            if (in_array($kolom, $lewati, true)) {
                continue;
            }

            // Dibandingkan longgar supaya nilai angka/teks yang sama tidak
            // dianggap berubah hanya karena tipe data PHP-nya berbeda.
            if ($payload[$kolom] != $sebelum->getAttribute($kolom)) {
                $berubah = true;
                break;
            }
        }

        if ($berubah) {
            $analisa['keadaan']['diperbarui']++;
        } else {
            $analisa['keadaan']['tetap']++;
        }

        if (! $sebelum->reorder && $payload['reorder'] && count($analisa['status']['naik_reorder']) < 20) {
            $analisa['status']['naik_reorder'][] = $kode;
        }

        if ($sebelum->reorder && ! $payload['reorder'] && count($analisa['status']['turun_aman']) < 20) {
            $analisa['status']['turun_aman'][] = $kode;
        }
    }

    /**
     * Menyusun rincian baca tiap berkas sumber untuk ditampilkan sebagai analisa.
     *
     * @param  array<string, array<string, mixed>> $baca Hasil baca berkas per kunci sumber
     * @return array<string, array<string, mixed>>       Rincian per kunci sumber
     */
    private function analisaSumber(array $baca): array
    {
        $hasil = [];

        foreach ([self::SUMBER_STOK, self::SUMBER_PR, self::SUMBER_PO, self::SUMBER_MRP] as $kunci) {
            $ada = array_key_exists($kunci, $baca);

            $hasil[$kunci] = [
                'dipakai'   => $ada,
                'nama'      => $ada ? (string) ($baca[$kunci]['nama'] ?? '') : null,
                'lembar'    => $ada ? (array) ($baca[$kunci]['lembar'] ?? []) : [],
                'baris'     => $ada ? (int) ($baca[$kunci]['baris'] ?? 0) : 0,
                'diabaikan' => $ada ? (int) ($baca[$kunci]['diabaikan'] ?? 0) : 0,
                'dokumen'   => $ada ? (int) ($baca[$kunci]['dokumen'] ?? 0) : null,
                'material'  => $ada ? count((array) ($baca[$kunci]['data'] ?? [])) : 0,
                'judul'     => $ada ? (array) ($baca[$kunci]['judul'] ?? []) : [],
                'catatan'   => $ada ? (array) ($baca[$kunci]['catatan'] ?? []) : [],
            ];
        }

        return $hasil;
    }

    /**
     * Menghitung cakupan satu berkas sumber terhadap daftar pantau.
     *
     * @param  bool                 $ada    Apakah berkas sumber ikut diunggah pada import ini
     * @param  array<string, mixed> $data   Data berkas sumber (berkunci kode material)
     * @param  array<int, string>   $pantau Seluruh kode material daftar pantau
     * @return array<string, mixed>|null    Cakupan berkas, null bila berkas tidak diunggah
     */
    private function cakupanSumber(bool $ada, array $data, array $pantau): ?array
    {
        if (! $ada) {
            return null;
        }

        $total = count($pantau);
        $punya = count(array_intersect(array_keys($data), $pantau));

        return [
            'punya'  => $punya,
            'tanpa'  => $total - $punya,
            'persen' => $total === 0 ? 0 : (int) round($punya / $total * 100),
        ];
    }

    /**
     * Menyusun kalimat catatan analisa tentang cakupan satu berkas sumber.
     *
     * Berkas cuplikan (mis. ekspor PR/PO bulan berjalan) membuat kolom Aksi
     * banyak berbunyi permintaan baru; kejadian itu nyata pada 09/10/2026 dan
     * karena itu diberi peringatan tersendiri.
     *
     * @param  string               $sumber  Kunci berkas sumber
     * @param  array<string, mixed> $cakupan Cakupan berkas (punya, tanpa, persen)
     * @param  int                  $total   Jumlah kode pada daftar pantau
     * @return string                        Kalimat catatan untuk analisa
     */
    private function catatanCakupan(string $sumber, array $cakupan, int $total): string
    {
        $dasar = 'Cakupan berkas ' . (self::LABEL_BERKAS[$sumber] ?? $sumber) . ': '
            . $cakupan['punya'] . ' dari ' . $total . ' material pantau (' . $cakupan['persen'] . '%)';

        if ((int) $cakupan['persen'] < self::AMBANG_CAKUPAN_SEMPIT) {
            return 'PERINGATAN: ' . $dasar . '. Berkas ini kemungkinan cuplikan sebagian bulan, bukan '
                . 'ekspor riwayat penuh, sehingga kolom Aksi banyak berbunyi permintaan baru; unggah '
                . 'ekspor rentang penuh bila angka aksi ingin setara dashboard lama.';
        }

        return $dasar . '; material yang tidak ada di berkas itu dinilai dari sumber lain '
            . 'sehingga kolom Aksi-nya bisa berbunyi permintaan baru.';
    }
}
