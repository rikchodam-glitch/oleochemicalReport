<?php

namespace App\Http\Controllers\Traits;

use App\Models\SpMaterial;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor tabel keadaan Spare Part (Integrasi PR/PO) ke berkas Excel.
 *
 * Gaya berkas mengikuti ekspor asset dan panel supaya laporan seragam: blok
 * judul di baris 1-6, kepala tabel di baris 7, border, autofilter, dan
 * pengaturan cetak landscape. Ditambah dua lembar pendukung: Ringkasan angka
 * dan Petunjuk arti kolom.
 *
 * Data baris disiapkan SparepartController; trait ini hanya menyusun berkasnya.
 */
trait SparepartExportTrait
{
    /** Baris kepala tabel; baris 1-6 dipakai judul dan keterangan. */
    private const BARIS_HEADER_SP = 7;

    /**
     * Judul kolom tabel keadaan. Urutannya sama persis dengan nilaiBaris(),
     * jadi menggeser kolom WAJIB mengubah kedua tempat sekaligus.
     *
     * @var array<int, string>
     */
    private const HEADER_SP = [
        'No', 'Kode Material', 'Deskripsi', 'Plant', 'Model', 'Tag No.', 'Satuan',
        'Stok Min', 'Stok Tersedia', 'Status', 'Penanda', 'Aksi',
        'No PR', 'Status PR', 'Status PR (aksi)', 'Qty PR', 'Jml Baris PR', 'PR Terbuka', 'Semua No PR', 'Requisitioner',
        'No PO', 'Sisa Kirim PO', 'Jml Baris PO', 'Jml PO', 'PO Terbuka', 'PO Tertutup', 'Sisa Semua PO', 'Tgl PO', 'Semua No PO',
        'Vendor PO', 'Purchaser', 'Purchaser PO Terakhir', 'Vendor PO Terakhir', 'PO Sebelumnya', 'Vendor Sebelumnya',
        'Stok Awal G001', 'Masuk G001', 'Keluar G001', 'Sisa G001', 'Lokasi G001', 'Keterangan',
        'Stok Diperbarui', 'PR Diperbarui', 'PO Diperbarui',
        'Stock by SAP (MRP)', 'Safety Stk (MRP)', 'Stok Min (MRP)', 'Unit (MRP)', 'Tipe MRP',
        'Min Monitoring vs MRP', 'MRP Diperbarui',
    ];

    /**
     * Indeks kolom (0 = kolom A) yang WAJIB ditulis sebagai teks supaya angka
     * nol di depan kode material dan nomor dokumen tidak hilang: Kode Material
     * (1), No PR (12), Semua No PR (18), Requisitioner (19), No PO (20),
     * Semua No PO (28), PO Sebelumnya (33).
     */
    private const KOLOM_TEKS_SP = [1, 12, 18, 19, 20, 28, 33];

    /**
     * Susun berkas Excel tabel keadaan lalu unduh.
     *
     * @param  iterable<int, object> $daftar        Baris hasil query (objek stdClass berawalan m_)
     * @param  string                $labelSaringan Keterangan saringan untuk baris judul
     * @param  array<string, int>    $ringkasan     Angka ringkasan halaman
     * @return StreamedResponse                     Berkas Excel siap diunduh
     */
    public function exportKeadaanExcel(iterable $daftar, string $labelSaringan, array $ringkasan): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Keadaan Spare Part');

        $jumlahKolom = count(self::HEADER_SP);
        $kolomAkhir  = Coordinate::stringFromColumnIndex($jumlahKolom);
        $barisHeader = self::BARIS_HEADER_SP;
        $jumlahBaris = is_countable($daftar) ? count($daftar) : 0;

        // ---------- Blok judul dan keterangan (baris 1-6) ----------
        $sheet->mergeCells('A1:' . $kolomAkhir . '1');
        $sheet->setCellValue('A1', 'DAFTAR SPARE PART — INTEGRASI PR/PO');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $keterangan = [
            'Sistem: Oleochemical Maintenance — modul Spare Part (satu baris per kode material)',
            'Diekspor: ' . now()->format('d/m/Y H:i'),
            'Saringan: ' . $labelSaringan,
            'Jumlah baris: ' . number_format($jumlahBaris, 0, ',', '.'),
            'Stok tersedia dari laporan stok gudang G001 (material yang tidak ada di laporan stok ditulis 0 mengikuti dashboard lama); stok minimum dari daftar pantau.',
        ];

        foreach ($keterangan as $i => $teks) {
            $barisKet = 2 + $i;
            $sheet->mergeCells('A' . $barisKet . ':' . $kolomAkhir . $barisKet);
            $sheet->setCellValue('A' . $barisKet, $teks);
        }

        // ---------- Kepala tabel (baris 7) ----------
        foreach (self::HEADER_SP as $i => $judul) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $barisHeader, $judul);
        }

        $gaya = 'A' . $barisHeader . ':' . $kolomAkhir . $barisHeader;
        $sheet->getStyle($gaya)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($gaya)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle($gaya)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($barisHeader)->setRowHeight(30);

        // ---------- Baris data ----------
        $no    = 0;
        $baris = $barisHeader + 1;

        foreach ($daftar as $satu) {
            $no++;

            foreach ($this->nilaiBaris($no, $satu) as $i => $isi) {
                $sel = Coordinate::stringFromColumnIndex($i + 1) . $baris;

                if (in_array($i, self::KOLOM_TEKS_SP, true)) {
                    $sheet->setCellValueExplicit($sel, (string) $isi, DataType::TYPE_STRING);
                    continue;
                }

                $sheet->setCellValue($sel, $isi);
            }

            if ($no % 2 === 0) {
                $sheet->getStyle('A' . $baris . ':' . $kolomAkhir . $baris)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            }

            $baris++;
        }

        $barisTerakhir = max($baris - 1, $barisHeader);
        $gayaTabel     = 'A' . $barisHeader . ':' . $kolomAkhir . $barisTerakhir;

        $sheet->getStyle($gayaTabel)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        $sheet->getStyle($gayaTabel)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomAkhir . $barisHeader);
        $sheet->freezePane('C' . ($barisHeader + 1));

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);

        foreach (range(1, 6) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $this->tulisRingkasan($spreadsheet, $ringkasan);
        $this->tulisPetunjuk($spreadsheet, $labelSaringan);
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $nama   = 'sparepart-integrasi-pr-po-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Nilai satu baris tabel sesuai urutan HEADER_SP.
     *
     * @param  int    $no    Nomor urut baris pada berkas
     * @param  object $baris Baris hasil query tabel keadaan
     * @return array<int, mixed> Nilai baris
     */
    private function nilaiBaris(int $no, object $baris): array
    {
        $status = match (true) {
            $baris->m_stok_min === null => 'Tanpa catatan stok',
            (bool) ($baris->m_reorder ?? false) => 'REORDER',
            default => 'Aman',
        };

        // Satuan HANYA diambil dari kolom UN laporan stok gudang (G001) supaya
        // berkas ini sama persis dengan dashboard lama (Integrasi_PR_PO_SparePart).
        // Kolom UOM daftar pantau TIDAK lagi dipakai sebagai cadangan (keputusan
        // user 09/10/2026): material yang tidak ada di laporan stok dibiarkan
        // kosong, sama seperti sel legacy-nya. Bukti 09/10/2026: 449/449 material
        // cocok dengan kolom Satuan (G001) berkas legacy; satu-satunya selisih
        // lama hanya material 30004876 yang ber-UOM "Pcs" pada daftar pantau.
        $satuan = trim((string) ($baris->m_g_satuan ?? ''));

        // Material yang tidak punya baris G001 sama sekali: dashboard lama
        // menampilkan 0 (rumusnya mengubah sel kosong menjadi angka nol),
        // sedangkan tabel keadaan menyimpannya NULL supaya halaman web tetap
        // bisa menandai "datanya belum ada". Sesuai keputusan user 09/10/2026,
        // nilai pada BERKAS EXPORT disamakan dengan legacy tanpa mengubah isi
        // database: stok_tersedia NULL dan g_baris 0 ditulis sebagai angka 0.
        $stokTersedia = $this->angkaSel($baris->m_stok_tersedia);

        if ($stokTersedia === null && (int) ($baris->m_g_baris ?? 0) === 0) {
            $stokTersedia = 0.0;
        }

        return [
            $no,
            (string) $baris->kode_material,
            (string) ($baris->m_deskripsi ?? $baris->deskripsi_pantau ?? ''),
            (string) ($baris->plant ?? ''),
            (string) ($baris->model ?? ''),
            (string) ($baris->tag_no ?? ''),
            $satuan,
            $this->angkaSel($baris->m_stok_min),
            $stokTersedia,
            $status,
            (string) ($baris->m_penanda ?? ''),
            SpMaterial::labelAksi($baris->m_aksi ?? null),
            (string) ($baris->m_pr_no ?? ''),
            (string) ($baris->m_pr_status ?? ''),
            (string) ($baris->m_pr_status_aksi ?? ''),
            $this->angkaSel($baris->m_pr_qty),
            (int) ($baris->m_pr_line ?? 0),
            (int) ($baris->m_pr_terbuka ?? 0),
            (string) ($baris->m_pr_nums ?? ''),
            (string) ($baris->m_req_list ?? ''),
            (string) ($baris->m_po_no ?? ''),
            $this->angkaSel($baris->m_po_sisa),
            (int) ($baris->m_po_line ?? 0),
            (int) ($baris->m_po_uniq ?? 0),
            (int) ($baris->m_po_open ?? 0),
            (int) ($baris->m_po_closed ?? 0),
            $this->angkaSel($baris->m_po_sisa_total),
            (string) ($baris->m_po_tanggal_teks ?? ''),
            (string) ($baris->m_po_nums ?? ''),
            (string) ($baris->m_vendor ?? ''),
            (string) ($baris->m_purch ?? ''),
            (string) ($baris->m_purch_last ?? ''),
            (string) ($baris->m_vendor_last ?? ''),
            (string) ($baris->m_po_prev ?? ''),
            (string) ($baris->m_vendor_prev ?? ''),
            $this->angkaSel($baris->m_g_awal),
            $this->angkaSel($baris->m_g_masuk),
            $this->angkaSel($baris->m_g_keluar),
            $this->angkaSel($baris->m_g_sisa),
            (string) ($baris->m_g_lokasi ?? ''),
            (string) ($baris->m_ket ?? ''),
            $this->waktuSel($baris->m_stok_diupdate_at ?? null),
            $this->waktuSel($baris->m_pr_diupdate_at ?? null),
            $this->waktuSel($baris->m_po_diupdate_at ?? null),
            $this->angkaSel($baris->m_mrp_stock ?? null),
            $this->angkaSel($baris->m_mrp_safety ?? null),
            $this->angkaSel($baris->m_mrp_reorder ?? null),
            (string) ($baris->m_mrp_unit ?? ''),
            (string) ($baris->m_mrp_type ?? ''),
            (string) ($baris->m_mrp_cmp ?? ''),
            $this->waktuSel($baris->m_mrp_diupdate_at ?? null),
        ];
    }

    /**
     * Nilai angka untuk satu sel: null dibiarkan kosong, bukan diubah jadi nol.
     *
     * @param  float|int|string|null $nilai Nilai sumber
     * @return float|null                   Angka (null bila tidak ada)
     */
    private function angkaSel(float|int|string|null $nilai): ?float
    {
        return $nilai === null || $nilai === '' ? null : (float) $nilai;
    }

    /**
     * Waktu cap pembaruan untuk sel Excel.
     *
     * @param  mixed  $nilai Nilai waktu (Carbon, teks, atau null)
     * @return string        Teks d/m/Y H:i atau "-" bila kosong
     */
    private function waktuSel(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        $ts = strtotime((string) $nilai);

        return $ts === false ? '-' : date('d/m/Y H:i', $ts);
    }

    /**
     * Menulis lembar Ringkasan (angka pokok dan cara membaca kolom utama).
     *
     * @param  Spreadsheet       $spreadsheet Buku kerja tujuan
     * @param  array<string, int> $ringkasan  Angka ringkasan halaman
     * @return void
     */
    private function tulisRingkasan(Spreadsheet $spreadsheet, array $ringkasan): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Ringkasan');

        $sheet->setCellValue('A1', 'RINGKASAN DAFTAR SPARE PART');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->mergeCells('A1:B1');

        $baris = [
            'Jumlah material dipantau'   => (int) ($ringkasan['total'] ?? 0),
            'Perlu reorder (REORDER)'    => (int) ($ringkasan['reorder'] ?? 0),
            'Stok aman'                  => (int) ($ringkasan['aman'] ?? 0),
            'Tanpa catatan stok gudang'  => (int) ($ringkasan['tanpa_stok'] ?? 0),
            'Tanpa stok minimum'         => (int) ($ringkasan['belum'] ?? 0),
            'Punya data PR'              => (int) ($ringkasan['punya_pr'] ?? 0),
            'Punya data PO'              => (int) ($ringkasan['punya_po'] ?? 0),
            'Punya data MRP'             => (int) ($ringkasan['punya_mrp'] ?? 0),
        ];

        $r = 3;

        foreach ($baris as $label => $nilai) {
            $sheet->setCellValue('A' . $r, $label);
            $sheet->setCellValue('B' . $r, $nilai);
            $r++;
        }

        $sheet->getStyle('A3:A' . ($r - 1))->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(16);
    }

    /**
     * Menulis lembar Petunjuk (aturan baris dan arti kolom utama).
     *
     * @param  Spreadsheet $spreadsheet Buku kerja tujuan
     * @param  string      $labelSaringan Keterangan saringan berkas ini
     * @return void
     */
    private function tulisPetunjuk(Spreadsheet $spreadsheet, string $labelSaringan): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $sheet->setCellValue('A1', 'PETUNJUK MEMBACA BERKAS INI');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->mergeCells('A1:B1');

        $isi = [
            'Saringan berkas ini' => $labelSaringan,
            'Aturan baris'        => 'Satu baris = satu kode material (keadaan terkini hasil import terakhir).',
            'Satuan'              => 'Kolom UN laporan stok gudang G001 (bukan lokasi penyimpanan, lokasi ada di kolom "Lokasi G001"); material yang tidak ada di laporan stok dibiarkan kosong, sama dengan dashboard lama (kolom UOM daftar pantau TIDAK dipakai di berkas ini).',
            'Stok Tersedia'       => 'Sisa stok gudang G001 pada laporan stok terakhir; material yang tidak ada di laporan stok ditulis 0 mengikuti dashboard lama (di halaman web material seperti itu tetap ditandai "datanya belum ada").',
            'Stok Min'            => 'Stok minimum dari daftar pantau (biasanya kolom Jumlah pada sheet MASTER DATA).',
            'Status REORDER'      => 'Stok tersedia <= stok minimum. Material tanpa catatan stok dihitung sebagai stok nol.',
            'Aksi'                => 'Kejar Delivery: ada PO terbuka; Kejar PO: ada PR terbuka tetapi PO belum dibuat; Cek/Buat PR: PR pernah/ada tetapi tidak terbuka; Buat PR: belum ada PR maupun PO.',
            'No PR / No PO'       => 'Dokumen paling relevan untuk material itu; kolom "Semua No PR" dan "Semua No PO" memuat seluruh nomor dokumen yang pernah dibaca.',
            'Requisitioner'       => 'Nama peminta dari berkas EXPORT PR yang dibaca (semua peminta digabung dengan titik koma bila lebih dari satu).',
            'Jml PO'              => 'Jumlah nomor PO unik material ini; satu nomor PO boleh memuat beberapa baris item (lihat "Jml Baris PO").',
            'Purchaser / Vendor PO Terakhir' => 'Diambil dari nomor PO TERBARU material ini; kolom "Purchaser" dan "Vendor PO" (tanpa akhiran) mengikuti nomor pada kolom "No PO".',
            'Diperbarui'          => 'Waktu sumber datanya terakhir dipasok import (boleh berbeda antar kolom karena import boleh sebagian berkas).',
            'Selaras dashboard lama' => 'Kolom "Requisitioner", "Jml PO", "Semua No PO", "Purchaser PO Terakhir", dan "Vendor PO Terakhir" ditambahkan supaya isi berkas ini setara sheet "Data Lengkap" dashboard lama.',
            'MRP (MD07)'          => 'Stock by SAP = kolom "Plnt stock" pada EXPORT MRP; Safety Stk (MRP) = "Safety Stk"; Stok Min (MRP) = "Reorder Pt". "Min Monitoring vs MRP" bernilai Sama/Beda, atau kosong bila material tidak ada di berkas MRP.',
            'Max Stock (MRP)'     => 'Tidak disediakan oleh MD07 dan belum diisi manual di modul ini, sama seperti catatan pada dashboard lama.',
        ];

        $r = 3;

        foreach ($isi as $label => $teks) {
            $sheet->setCellValue('A' . $r, $label);
            $sheet->setCellValue('B' . $r, $teks);
            $r++;
        }

        $sheet->getStyle('A3:A' . ($r - 1))->getFont()->setBold(true);
        $sheet->getStyle('A3:B' . ($r - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(90);
    }
}