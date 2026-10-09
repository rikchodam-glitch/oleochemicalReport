<?php

namespace App\Http\Controllers\Traits;

use App\Models\SpBatch;
use App\Models\SpIntegrasi;
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
 * Trait SparepartExportTrait
 *
 * Ekspor rincian hasil integrasi PR/PO Spare Part ke berkas Excel (.xlsx)
 * memakai PhpSpreadsheet, dengan gaya yang sama seperti ekspor asset dan panel
 * supaya laporan seragam: blok judul di baris 1-6, kepala tabel di baris 7,
 * border, autofilter, dan pengaturan cetak landscape.
 *
 * Data baris disiapkan SparepartController; trait ini hanya menyusun berkasnya.
 */
trait SparepartExportTrait
{
    /** Baris tempat kepala tabel ditulis; baris 1-6 dipakai judul dan keterangan. */
    private const BARIS_HEADER_SP = 7;

    /**
     * Indeks kolom (0 = kolom A) yang WAJIB ditulis sebagai teks supaya angka
     * nol di depan kode material dan nomor dokumen tidak hilang saat dibuka
     * Excel. Indeks 1 = Kode Material, 9 = No PR, 14 = No PO.
     */
    private const KOLOM_TEKS_SP = [1, 9, 14];

    /**
     * Judul kolom rincian. Urutannya sama persis dengan nilaiBarisRincian(),
     * karena itu menambah/menggeser kolom WAJIB mengubah kedua tempat sekaligus.
     *
     * @var array<int, string>
     */
    private const HEADER_RINCIAN_SP = [
        'No', 'Kode Material', 'Deskripsi', 'Reorder', 'Penanda',
        'Stok Min', 'Stok Tersedia', 'Min vs MRP', 'Aksi',
        'No PR', 'Status PR (aksi)', 'Status PR', 'Qty PR', 'Jml Dok PR',
        'No PO', 'Sisa PO', 'PO Terbuka', 'PO Tertutup', 'Jml Dok PO', 'Tgl PO',
        'Vendor', 'Purchasing Group', 'Vendor Terakhir', 'Purchasing Terakhir',
        'G001 Baris', 'G001 Awal', 'G001 Masuk', 'G001 Keluar', 'G001 Sisa',
        'G001 Satuan', 'G001 Lokasi', 'MRP Plant', 'MRP Safety', 'MRP Reorder',
        'Keterangan',
    ];

    /**
     * Susun berkas Excel rincian integrasi satu periode lalu unduh.
     *
     * @param  SpBatch                    $batch         Batch yang diekspor
     * @param  iterable<int, SpIntegrasi> $baris         Baris rincian (sudah disaring)
     * @param  string                     $labelSaringan Keterangan saringan untuk baris judul
     * @return StreamedResponse
     */
    public function exportRincianExcel(SpBatch $batch, iterable $baris, string $labelSaringan): StreamedResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rincian Integrasi');

        $jumlahKolom = count(self::HEADER_RINCIAN_SP);
        $kolomAkhir  = Coordinate::stringFromColumnIndex($jumlahKolom);
        $barisHeader = self::BARIS_HEADER_SP;

        // ---------- Blok judul dan keterangan (baris 1-6) ----------
        $sheet->mergeCells('A1:' . $kolomAkhir . '1');
        $sheet->setCellValue('A1', 'INTEGRASI PR/PO SPARE PART — ' . strtoupper((string) $batch->periode));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $keterangan = [
            2 => 'Sistem Manajemen Pemeliharaan — Oleochemical Maintenance',
            3 => 'Periode: ' . (string) $batch->periode
                . ' | Status: ' . $batch->label_status
                . ' | Folder: ' . (string) $batch->folder,
            4 => 'Baris sumber: monitoring ' . number_format((int) $batch->jumlah_monitoring)
                . ' | G001 ' . number_format((int) $batch->jumlah_g001)
                . ' | PR ' . number_format((int) $batch->jumlah_pr)
                . ' | PO ' . number_format((int) $batch->jumlah_po)
                . ' | MRP ' . number_format((int) $batch->jumlah_mrp)
                . ' | baris hasil ' . number_format((int) $batch->jumlah_hasil),
            5 => 'Saringan: ' . $labelSaringan,
            6 => 'Diekspor: ' . now()->format('d/m/Y H:i')
                . ' oleh ' . (auth()->user()?->name ?? 'sistem'),
        ];

        foreach ($keterangan as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':' . $kolomAkhir . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->setItalic(true)->getColor()->setARGB('FF475569');
        }

        // ---------- Kepala tabel (baris 7) ----------
        foreach (self::HEADER_RINCIAN_SP as $indeks => $judul) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($indeks + 1) . $barisHeader, $judul);
        }

        $gayaHeader = $sheet->getStyle('A' . $barisHeader . ':' . $kolomAkhir . $barisHeader);
        $gayaHeader->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $gayaHeader->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $gayaHeader->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        // ---------- Baris data ----------
        $barisTulis = $barisHeader + 1;
        $no         = 1;

        foreach ($baris as $rincian) {
            foreach ($this->nilaiBarisRincian($no, $rincian) as $indeks => $isi) {
                $sel = Coordinate::stringFromColumnIndex($indeks + 1) . $barisTulis;

                if (in_array($indeks, self::KOLOM_TEKS_SP, true)) {
                    $sheet->setCellValueExplicit($sel, (string) $isi, DataType::TYPE_STRING);

                    continue;
                }

                $sheet->setCellValue($sel, $isi);
            }

            $barisTulis++;
            $no++;
        }

        if ($no === 1) {
            $sheet->mergeCells('A' . $barisTulis . ':' . $kolomAkhir . $barisTulis);
            $sheet->setCellValue('A' . $barisTulis, 'Tidak ada baris rincian yang cocok dengan saringan ini.');
            $sheet->getStyle('A' . $barisTulis)->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
        }

        foreach (range(1, $jumlahKolom) as $indeks) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($indeks))->setAutoSize(true);
        }

        $sheet->getStyle('A' . $barisHeader . ':' . $kolomAkhir . max($barisTulis - 1, $barisHeader))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');

        $sheet->freezePane('A' . ($barisHeader + 1));
        $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomAkhir . $barisHeader);

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);

        $writer   = new Xlsx($spreadsheet);
        $namaFile = 'integrasi-pr-po-' . $this->namaFileAman((string) $batch->periode)
            . '-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer, $spreadsheet): void {
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $namaFile, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Susun nilai satu baris rincian sesuai urutan HEADER_RINCIAN_SP.
     *
     * Nilai angka dikirim apa adanya (float atau int) supaya Excel mengenalinya
     * sebagai angka, sedangkan kode material dan nomor dokumen dikirim sebagai
     * teks oleh pemanggil (lihat KOLOM_TEKS_SP).
     *
     * @param  int          $no       Nomor urut baris pada berkas
     * @param  SpIntegrasi  $rincian  Baris hasil integrasi
     * @return array<int, mixed>      Nilai baris, urut sesuai HEADER_RINCIAN_SP
     */
    private function nilaiBarisRincian(int $no, SpIntegrasi $rincian): array
    {
        return [
            $no,
            (string) $rincian->kode_material,
            (string) ($rincian->deskripsi ?? ''),
            $rincian->reorder ? 'REORDER' : 'Aman',
            (string) ($rincian->penanda ?? ''),
            $this->angkaSel($rincian->stok_min),
            $this->angkaSel($rincian->stok_tersedia),
            (string) ($rincian->min_cmp ?? '-'),
            $rincian->label_aksi,
            (string) ($rincian->pr_no ?? ''),
            (string) ($rincian->pr_status_aksi ?? ''),
            (string) ($rincian->pr_status ?? ''),
            $this->angkaSel($rincian->pr_qty),
            (int) $rincian->pr_line,
            (string) ($rincian->po_no ?? ''),
            $this->angkaSel($rincian->po_sisa),
            (int) $rincian->po_open,
            (int) $rincian->po_closed,
            (int) $rincian->po_line,
            (string) ($rincian->po_tanggal_teks ?? ''),
            (string) ($rincian->vendor ?? ''),
            (string) ($rincian->purch ?? ''),
            (string) ($rincian->vendor_last ?? ''),
            (string) ($rincian->purch_last ?? ''),
            (int) $rincian->g_baris,
            $this->angkaSel($rincian->g_awal),
            $this->angkaSel($rincian->g_masuk),
            $this->angkaSel($rincian->g_keluar),
            $this->angkaSel($rincian->g_sisa),
            (string) ($rincian->g_satuan ?? ''),
            (string) ($rincian->g_lokasi ?? ''),
            $this->angkaSel($rincian->mrp_plant),
            $this->angkaSel($rincian->mrp_safety),
            $this->angkaSel($rincian->mrp_reorder),
            (string) ($rincian->ket ?? ''),
        ];
    }

    /**
     * Nilai angka untuk satu sel: null dibiarkan kosong, bukan diubah jadi nol.
     *
     * @param  float|int|string|null $nilai
     * @return float|null
     */
    private function angkaSel(float|int|string|null $nilai): ?float
    {
        return $nilai === null ? null : (float) $nilai;
    }

    /**
     * Nama berkas aman dari label periode (huruf/angka saja, dipisah tanda hubung).
     *
     * @param  string $teks Label periode
     * @return string       Bagian nama berkas yang aman dipakai di semua sistem
     */
    private function namaFileAman(string $teks): string
    {
        $aman = preg_replace('/[^A-Za-z0-9]+/', '-', trim($teks));

        return strtolower(trim((string) $aman, '-')) ?: 'periode';
    }
}
