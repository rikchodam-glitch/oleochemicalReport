<?php

namespace App\Http\Controllers\Traits;

use App\Models\PanelRuangan;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Trait PanelExportTrait
 *
 * Ekspor rekap pembacaan HARIAN satu ruang panel ke berkas Excel (.xlsx)
 * memakai PhpSpreadsheet, dengan gaya yang sama seperti ekspor daftar asset
 * (AssetExportTrait) supaya laporan seragam.
 *
 * Data rekap disiapkan PanelRiwayatService::harian(); trait ini hanya menyusun
 * berkasnya sehingga controller tetap ringkas.
 */
trait PanelExportTrait
{
    /**
     * Susun berkas Excel rekap harian satu ruangan panel lalu unduh.
     *
     * @param  PanelRuangan  $ruangan        Ruangan yang diekspor
     * @param  string        $labelPeriode   Keterangan periode, mis. "01/10/2026 s/d 31/10/2026"
     * @param  array<int, array<string, mixed>>  $harian    Baris rekap dari PanelRiwayatService
     * @param  array<string, mixed>              $ringkasan Ringkasan dari PanelRiwayatService
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportHarianExcel(PanelRuangan $ruangan, string $labelPeriode, array $harian, array $ringkasan)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Riwayat Harian');

        $headers = [
            'No', 'Tanggal', 'Jumlah Pembacaan',
            'Suhu Min (C)', 'Suhu Max (C)', 'Suhu Rata (C)',
            'Kelembapan Min (%)', 'Kelembapan Max (%)', 'Kelembapan Rata (%)',
            'Terlalu Panas (jml)', 'Terlalu Dingin (jml)', 'Keterangan',
        ];

        $kolomTerakhir = chr(64 + count($headers)); // A..L
        $barisHeader   = 7;

        // ---------- Blok judul & keterangan (baris 1-6) ----------
        $sheet->mergeCells('A1:' . $kolomTerakhir . '1');
        $sheet->setCellValue('A1', 'RIWAYAT HARIAN RUANG PANEL — ' . strtoupper((string) $ruangan->nama_ruangan));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $barisInfo = [
            2 => 'Sistem Manajemen Pemeliharaan — Oleochemical Maintenance',
            3 => 'Lokasi: ' . $ruangan->labelLokasi()
                . ' | Kode alat: ' . ($ruangan->kode_alat ?: 'belum diisi')
                . ' | Perangkat: ' . ($ruangan->ip_address ?: '-') . ':' . ($ruangan->port ?: '-'),
            4 => 'Ambang suhu: ' . number_format((float) $ruangan->ambang_suhu_bawah, 1) . ' s/d '
                . number_format((float) $ruangan->ambang_suhu_atas, 1) . ' C',
            5 => 'Periode: ' . $labelPeriode
                . ' | Hari berisi data: ' . number_format((int) ($ringkasan['jumlah_hari'] ?? 0))
                . ' | Pembacaan: ' . number_format((int) ($ringkasan['jumlah_pembacaan'] ?? 0))
                . ' (di luar ambang: ' . number_format((int) ($ringkasan['jumlah_panas'] ?? 0)) . ' terlalu panas, '
                . number_format((int) ($ringkasan['jumlah_dingin'] ?? 0)) . ' terlalu dingin)',
            6 => 'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
        ];

        foreach ($barisInfo as $nomor => $teks) {
            $sheet->mergeCells('A' . $nomor . ':' . $kolomTerakhir . $nomor);
            $sheet->setCellValue('A' . $nomor, $teks);
            $sheet->getStyle('A' . $nomor)->getFont()->setSize(10)->getColor()->setARGB('FF475569');
            $sheet->getStyle('A' . $nomor)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($nomor)->setRowHeight(18);
        }

        // ---------- Baris header kolom ----------
        foreach (array_values($headers) as $i => $header) {
            $kolom = chr(65 + $i);
            $sheet->setCellValue($kolom . $barisHeader, $header);

            $gaya = $sheet->getStyle($kolom . $barisHeader);
            $gaya->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $gaya->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
            $gaya->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $gaya->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF0B7A6E');
        }

        $sheet->getRowDimension($barisHeader)->setRowHeight(30);

        // ---------- Baris data ----------
        $baris = $barisHeader + 1;
        $no    = 1;

        foreach ($harian as $rekap) {
            $sheet->setCellValueExplicit('A' . $baris, (string) $no, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $baris, $this->tanggalTampilan((string) $rekap['tanggal']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $baris, (string) $rekap['jumlah'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $baris, $this->angkaTampilan($rekap['suhu_min']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $baris, $this->angkaTampilan($rekap['suhu_max']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('F' . $baris, $this->angkaTampilan($rekap['suhu_rata']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('G' . $baris, $this->angkaTampilan($rekap['kelembapan_min']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H' . $baris, $this->angkaTampilan($rekap['kelembapan_max']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('I' . $baris, $this->angkaTampilan($rekap['kelembapan_rata']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('J' . $baris, (string) $rekap['jumlah_panas'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('K' . $baris, (string) $rekap['jumlah_dingin'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('L' . $baris, (string) $rekap['keterangan'], DataType::TYPE_STRING);

            $gayaBaris = $sheet->getStyle('A' . $baris . ':' . $kolomTerakhir . $baris);
            $gayaBaris->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
            $gayaBaris->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Hari yang pernah keluar ambang diberi latar merah muda supaya
            // mudah ditemukan saat dibaca manusia.
            if ((int) $rekap['jumlah_panas'] > 0 || (int) $rekap['jumlah_dingin'] > 0) {
                $gayaBaris->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDF2F2');
            }

            $baris++;
            $no++;
        }

        if ($harian === []) {
            $sheet->mergeCells('A' . $baris . ':' . $kolomTerakhir . $baris);
            $sheet->setCellValue('A' . $baris, 'Belum ada pembacaan tersimpan pada periode ini.');
            $sheet->getStyle('A' . $baris)->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
        }

        foreach (range('A', $kolomTerakhir) as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }

        if ($harian !== []) {
            $sheet->freezePane('A' . ($barisHeader + 1));
            $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomTerakhir . $barisHeader);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);

        $writer   = new Xlsx($spreadsheet);
        $namaFile = 'riwayat-harian-' . $this->namaFileAman((string) $ruangan->nama_ruangan)
            . '-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $namaFile, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Ubah tanggal ISO (Y-m-d) menjadi bentuk yang enak dibaca (d/m/Y + hari).
     *
     * @param  string  $tanggal  Tanggal format Y-m-d
     * @return string
     */
    private function tanggalTampilan(string $tanggal): string
    {
        $waktu = strtotime($tanggal);

        if ($waktu === false) {
            return $tanggal;
        }

        return date('d/m/Y', $waktu) . ' (' . $this->namaHari((int) date('w', $waktu)) . ')';
    }

    /**
     * Nama hari dalam Bahasa Indonesia.
     *
     * @param  int  $nomor  Nomor hari (0 = Minggu)
     * @return string
     */
    private function namaHari(int $nomor): string
    {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        return $hari[$nomor] ?? '-';
    }

    /**
     * Tampilkan angka rekap: dua desimal, atau tanda "-" bila belum ada nilai.
     *
     * @param  mixed  $nilai
     * @return string
     */
    private function angkaTampilan(mixed $nilai): string
    {
        return $nilai === null ? '-' : number_format((float) $nilai, 2, ',', '.');
    }

    /**
     * Nama berkas aman dari nama ruangan (huruf/angka saja, dipisah tanda hubung).
     *
     * @param  string  $teks
     * @return string
     */
    private function namaFileAman(string $teks): string
    {
        $aman = preg_replace('/[^A-Za-z0-9]+/', '-', trim($teks));

        return strtolower(trim((string) $aman, '-')) ?: 'ruang-panel';
    }
}

