<?php

namespace App\Services\Sparepart;

use App\Models\SpBatch;
use App\Models\SpG001;
use App\Models\SpIntegrasi;
use App\Models\SpMonitoring;
use App\Models\SpMrp;
use App\Models\SpPo;
use App\Models\SpPr;
use App\Services\Sparepart\Concerns\SparepartPenyimpananTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Mengisi tabel integrasi PR/PO Spare Part dari lima berkas sumber.
 *
 * Berkas sumber memakai nama tetap, mengikuti proses lama pada folder periode:
 * 1. SparePart_Monitoring.csv - monitoring stok minimum per material,
 * 2. G001_clean.csv           - stok gudang per material/SLoc,
 * 3. EXPORT PR.XLSX           - permintaan pembelian (SAP ME5A),
 * 4. EXPORT PO.XLSX           - pesanan pembelian (SAP ME2M),
 * 5. EXPORT MRP.XLSX          - nilai MRP (SAP MD07).
 *
 * Urutan kerja satu batch: baca berkas, simpan baris mentah ke tabel sp_*,
 * hitung baris hasil per material (sp_integrasi), lalu tutup batch. Batch
 * dengan periode yang sama selalu diganti lebih dulu, sehingga import ulang
 * pada periode yang sama bersifat idempoten (tidak pernah berbaris ganda).
 */
class SparepartImportService
{
    use SparepartPenyimpananTrait;

    public const BERKAS_MONITORING = 'SparePart_Monitoring.csv';

    public const BERKAS_G001 = 'G001_clean.csv';

    public const BERKAS_PR = 'EXPORT PR.XLSX';

    public const BERKAS_PO = 'EXPORT PO.XLSX';

    public const BERKAS_MRP = 'EXPORT MRP.XLSX';

    /** Jumlah baris untuk setiap satu perintah insert. */
    private const UKURAN_SISIP = 500;

    /** Toleransi pembanding stok minimum monitoring dengan Reorder Pt MRP. */
    private const TOLERANSI_MIN = 0.001;

    /** Panjang maksimal nilai teks pada tabel hasil (mengikuti kolom database). */
    private const BATAS_DESKRIPSI = 255;

    private const BATAS_PENANDA = 100;

    private const BATAS_PR_STATUS = 120;

    private const BATAS_PURCH = 60;

    private const BATAS_LOKASI = 255;

    private const BATAS_SATUAN = 40;

    private const BATAS_NO_DOKUMEN = 40;

    private const BATAS_KET = 120;

    public function __construct(private SparepartReader $pembaca)
    {
    }

    /**
     * Menjalankan satu kali import untuk satu periode.
     *
     * @param  string   $folder  Folder periode berisi berkas sumber
     * @param  string   $periode Label periode (mis. "07 Okt 26")
     * @param  int|null $oleh    Id pengguna yang menjalankan import
     * @return SpBatch Batch hasil import yang sudah berstatus selesai
     *
     * @throws RuntimeException Bila folder atau salah satu berkas sumber tidak ada
     */
    public function import(string $folder, string $periode, ?int $oleh = null): SpBatch
    {
        $berkas = $this->berkasWajib($folder);

        $this->bersihkanBatchLama($periode);

        $batch = SpBatch::create([
            'periode' => $periode,
            'folder'  => $folder,
            'status'  => 'proses',
            'oleh'    => $oleh,
        ]);

        try {
            $ringkasan = DB::transaction(fn (): array => $this->jalankan($batch, $berkas));
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'gagal',
                'pesan'  => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        $batch->update([
            'status'            => 'selesai',
            'ringkasan'         => $ringkasan,
            'jumlah_monitoring' => $ringkasan['jumlah']['monitoring'],
            'jumlah_g001'       => $ringkasan['jumlah']['g001'],
            'jumlah_pr'         => $ringkasan['jumlah']['pr'],
            'jumlah_po'         => $ringkasan['jumlah']['po'],
            'jumlah_mrp'        => $ringkasan['jumlah']['mrp'],
            'jumlah_hasil'      => $ringkasan['jumlah']['hasil'],
            'selesai_at'        => Carbon::now(),
        ]);

        return $batch->refresh();
    }

    /**
     * Mencari kelima berkas sumber pada folder periode.
     *
     * Berkas PR/PO/MRP boleh berada di folder lain (folder "sumber") selama
     * masih bersebelahan dengan folder periode, sesuai kebiasaan folder lama.
     *
     * @param  string $folder Folder periode
     * @return array<string, string> Path berkas per kunci (monitoring, g001, pr, po, mrp)
     *
     * @throws RuntimeException Bila folder atau salah satu berkas tidak ada
     */
    public function berkasWajib(string $folder): array
    {
        if (! is_dir($folder)) {
            throw new RuntimeException('Folder periode tidak ditemukan: ' . $folder);
        }

        $folder = rtrim(str_replace('\\', '/', $folder), '/');
        $sumber = $this->folderSumber($folder);

        $kandidat = [
            'monitoring' => [$folder . '/_monitoring/' . self::BERKAS_MONITORING, $folder . '/' . self::BERKAS_MONITORING],
            'g001'       => [$folder . '/' . self::BERKAS_G001],
            'pr'         => [$folder . '/' . self::BERKAS_PR, $sumber . '/' . self::BERKAS_PR],
            'po'         => [$folder . '/' . self::BERKAS_PO, $sumber . '/' . self::BERKAS_PO],
            'mrp'        => [$folder . '/' . self::BERKAS_MRP, $sumber . '/' . self::BERKAS_MRP],
        ];

        $hasil = [];
        $hilang = [];

        foreach ($kandidat as $kunci => $pilihan) {
            $ketemu = '';

            foreach ($pilihan as $path) {
                if (is_file($path)) {
                    $ketemu = $path;
                    break;
                }
            }

            if ($ketemu === '') {
                $hilang[] = basename($pilihan[0]);
                continue;
            }

            $hasil[$kunci] = $ketemu;
        }

        if ($hilang !== []) {
            throw new RuntimeException('Berkas sumber belum lengkap di folder ' . $folder
                . ': ' . implode(', ', array_unique($hilang)));
        }

        return $hasil;
    }

    /**
     * Menentukan folder sumber berkas PR/PO/MRP.
     *
     * Bila folder periode sendiri sudah memuat EXPORT PR.XLSX, folder itulah
     * yang dipakai. Bila belum, folder bersebelahan yang memuat berkas itu
     * dipakai (yang paling baru diubah), supaya berkas tidak perlu dipindahkan.
     *
     * @param  string $folder Folder periode (sudah memakai pemisah "/")
     * @return string Folder sumber
     */
    private function folderSumber(string $folder): string
    {
        if (is_file($folder . '/' . self::BERKAS_PR)) {
            return $folder;
        }

        $kandidat = [];

        foreach ((array) glob(dirname($folder) . '/*', GLOB_ONLYDIR) as $tetangga) {
            $tetangga = str_replace('\\', '/', (string) $tetangga);

            if ($tetangga === $folder || ! is_file($tetangga . '/' . self::BERKAS_PR)) {
                continue;
            }

            $kandidat[$tetangga] = (int) filemtime($tetangga . '/' . self::BERKAS_PR);
        }

        if ($kandidat === []) {
            return $folder;
        }

        arsort($kandidat);

        return (string) array_key_first($kandidat);
    }

    /**
     * Menghapus batch lama dengan periode yang sama beserta seluruh isinya.
     *
     * Baris mentah dan baris hasil ikut terhapus lewat aturan cascade pada
     * foreign key, jadi cukup menghapus baris batch-nya.
     *
     * @param  string $periode Label periode
     * @return void
     */
    private function bersihkanBatchLama(string $periode): void
    {
        SpBatch::where('periode', $periode)->get()->each(
            fn (SpBatch $lama) => $lama->delete()
        );
    }

    /**
     * Membaca kelima berkas, menyimpan baris mentah, lalu menghitung hasil.
     *
     * @param  SpBatch               $batch  Batch yang sedang diisi
     * @param  array<string, string> $berkas Path berkas sumber
     * @return array<string, mixed> Ringkasan hasil import
     */
    private function jalankan(SpBatch $batch, array $berkas): array
    {
        $kini = Carbon::now();

        $monitoring = $this->pembaca->bacaMonitoring($berkas['monitoring']);
        $g001 = $this->pembaca->bacaG001($berkas['g001']);
        $pr = $this->pembaca->bacaPr($berkas['pr']);
        $po = $this->pembaca->bacaPo($berkas['po']);
        $mrp = $this->pembaca->bacaMrp($berkas['mrp']);

        $this->simpanMonitoring($batch->id, $monitoring['data'], $kini);
        $this->simpanG001($batch->id, $g001['data'], $kini);
        $this->simpanPr($batch->id, $pr['data'], $kini);
        $this->simpanPo($batch->id, $po['data'], $kini);
        $this->simpanMrp($batch->id, $mrp['data'], $kini);

        $hitung = $this->hitungIntegrasi(
            $monitoring['data'],
            $g001['data'],
            $pr['data'],
            $po['data'],
            $mrp['data']
        );

        $this->simpanIntegrasi($batch->id, $hitung['baris'], $kini);

        return [
            'jumlah'   => [
                'monitoring' => $monitoring['baris'],
                'g001'       => $g001['baris'],
                'pr'         => $pr['baris'],
                'po'         => $po['baris'],
                'mrp'        => $mrp['baris'],
                'hasil'      => count($hitung['baris']),
            ],
            'aksi'     => $hitung['aksi'],
            'stok'     => $hitung['stok'],
            'min'      => $hitung['min'],
            'material' => [
                'pr'        => count($pr['data']),
                'po'        => count($po['data']),
                'mrp'       => count($mrp['data']),
                'pr_irisan' => $hitung['pr_irisan'],
                'po_irisan' => $hitung['po_irisan'],
            ],
        ];
    }

    /**
     * Menyimpan baris mentah monitoring stok ke tabel sp_monitoring.
     *
     * @param  int                                  $batchId Id batch
     * @param  array<string, array<string, mixed>>  $data    Data hasil baca
     * @param  Carbon                               $kini    Cap waktu baris
     * @return void
     */
    private function simpanMonitoring(int $batchId, array $data, Carbon $kini): void
    {
        if ($data === []) {
            return;
        }

        $baris = [];

        foreach ($data as $item) {
            $baris[] = [
                'batch_id'      => $batchId,
                'kode_material' => $item['kode_material'],
                'deskripsi'     => $this->potong($item['deskripsi'], self::BATAS_DESKRIPSI),
                'stok_min'      => $this->angkaAtau($item['stok_min']),
                'stok_tersedia' => $this->angkaAtau($item['stok_tersedia']),
                'reorder'       => $item['reorder'] ? 1 : 0,
                'penanda'       => $this->potong($item['penanda'], self::BATAS_PENANDA),
                'created_at'    => $kini,
                'updated_at'    => $kini,
            ];
        }

        $this->sisip('sp_monitoring', $baris);
    }
}
