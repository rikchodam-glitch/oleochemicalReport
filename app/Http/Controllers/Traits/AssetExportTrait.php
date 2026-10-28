<?php

namespace App\Http\Controllers\Traits;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\SubArea;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Trait AssetExportTrait
 *
 * Berisi logika ekspor data asset ke format file yang dapat diunduh.
 * Dikelompokkan bersama karena keduanya berbagi helper buildExportQuery()
 * dan tidak bergantung pada state controller selain parameter request.
 *
 * Method yang ada:
 *   - exportExcel()       : Ekspor data asset ke file .xlsx (PhpSpreadsheet)
 *   - exportCsv()         : Ekspor data asset ke file .csv dengan BOM UTF-8
 *   - buildExportQuery()  : Bangun query Asset dengan filter dari request
 */
trait AssetExportTrait
{
    /**
     * Ekspor data asset ke file Excel (.xlsx).
     * Filter yang berlaku sama dengan filter di halaman index asset.
     *
     * @param  Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportExcel(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $assets = $this->buildExportQuery($request)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assets');

        // Kolom laporan. Equipment No IKUT diekspor (diletakkan di kolom B)
        // karena nomor itu dipakai sebagai kunci pencocokan utama saat file
        // diimport kembali: dengan begitu Kode Alat (Tech Ident No) boleh
        // diubah tanpa membuat aset baru (lihat ImportService::analyzeFile).
        $headers = [
            'No', 'Equipment No', 'Description', 'Tech Ident No', 'Equipment Type',
            'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
            'Manufacturer', 'Model Number', 'Construct Year', 'Status',
            'Data Source',
        ];

        $kolomTerakhir = chr(64 + count($headers)); // A..O
        $barisHeader   = 7;

        // ---------- Blok judul & keterangan (baris 1-5) ----------
        $sheet->mergeCells('A1:' . $kolomTerakhir . '1');
        $sheet->setCellValue('A1', 'DAFTAR ASSET — ASSET MANAGEMENT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $barisInfo = [
            2 => 'Sistem Manajemen Pemeliharaan — Oleochemical Maintenance',
            3 => 'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            4 => 'Filter: ' . $this->ringkasanFilterExport($request),
            5 => 'Jumlah: ' . number_format($assets->count()) . ' asset (perangkat IT & ruang panel / it_sync & panel_sync tidak disertakan)',
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
            $col  = chr(65 + $i);
            $cell = $sheet->getCell($col . $barisHeader);
            $cell->setValue($header);

            $style = $sheet->getStyle($col . $barisHeader);
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0E9E8E');
            $style->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF0B7A6E');
        }

        $sheet->getRowDimension($barisHeader)->setRowHeight(26);

        // ---------- Baris data ----------
        $rowNum = $barisHeader + 1;
        $no     = 1;

        foreach ($assets as $asset) {
            // Semua kolom data ditulis sebagai TEKS (setCellValueExplicit).
            // Alasannya: nilai seperti Tech Ident No "6153E1" dianggap angka oleh
            // Excel/PhpSpreadsheet (6153 x 10^1) sehingga akan tersimpan sebagai
            // 61530 dan merusak data saat file diimport ulang. Equipment No juga
            // ditulis sebagai teks supaya nomor yang berawalan nol tetap utuh.
            $nilaiKolom = [
                'A' => $no,
                'B' => $asset->equipment_no,
                'C' => $asset->description,
                'D' => $asset->tech_ident_no,
                'E' => $asset->equipment_type,
                'F' => $asset->functional_loc,
                'G' => $asset->company?->code ?? '',
                'H' => $asset->department?->code ?? '',
                'I' => $asset->area?->code ?? '',
                'J' => $asset->subArea?->code ?? '',
                'K' => $asset->manufacturer,
                'L' => $asset->model_number,
                'M' => $asset->construct_year,
                'N' => $asset->status,
                'O' => $asset->data_source,
            ];

            foreach ($nilaiKolom as $kolom => $nilai) {
                $sheet->setCellValueExplicit(
                    $kolom . $rowNum,
                    (string) ($nilai ?? ''),
                    DataType::TYPE_STRING
                );
            }

            $sheet->getStyle('A' . $rowNum . ':' . $kolomTerakhir . $rowNum)
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');

            $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Baris zebra supaya laporan lebih mudah dibaca.
            if ($no % 2 === 0) {
                $sheet->getStyle('A' . $rowNum . ':' . $kolomTerakhir . $rowNum)
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFC');
            }

            $rowNum++;
            $no++;
        }

        // ---------- Penyempurnaan tampilan & siap cetak ----------
        foreach (range('A', $kolomTerakhir) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if ($assets->isNotEmpty()) {
            $sheet->freezePane('A' . ($barisHeader + 1));
            $sheet->setAutoFilter('A' . $barisHeader . ':' . $kolomTerakhir . $barisHeader);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $barisHeader);

        $writer   = new Xlsx($spreadsheet);
        $filename = 'assets-export-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Ekspor data asset ke file CSV dengan BOM UTF-8 agar kompatibel dengan Excel.
     * Filter yang berlaku sama dengan filter di halaman index asset.
     *
     * @param  Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $assets   = $this->buildExportQuery($request)->get();
        $filename = 'assets-export-' . now()->format('Y-m-d-His') . '.csv';

        // Keterangan header (kolom sama dengan export Excel, termasuk Equipment No).
        $info = [
            'DAFTAR ASSET — ASSET MANAGEMENT',
            'Sistem Manajemen Pemeliharaan — Oleochemical Maintenance',
            'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB'
                . (auth()->check() ? ' oleh ' . auth()->user()->name : ''),
            'Filter: ' . $this->ringkasanFilterExport($request),
            'Jumlah: ' . number_format($assets->count()) . ' asset (perangkat IT & ruang panel / it_sync & panel_sync tidak disertakan)',
        ];

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($assets, $info) {
            $file = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel tidak salah baca karakter multibyte
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Blok keterangan: baris header tabel di bawahnya (ImportService
            // mencari baris header secara otomatis, jadi blok ini aman).
            foreach ($info as $barisInfo) {
                fputcsv($file, [$barisInfo]);
            }

            fputcsv($file, []);

            // Baris header kolom
            fputcsv($file, [
                'No', 'Equipment No', 'Description', 'Tech Ident No', 'Equipment Type',
                'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
                'Manufacturer', 'Model Number', 'Construct Year', 'Status',
                'Data Source',
            ]);

            // Baris data
            $no = 1;

            foreach ($assets as $asset) {
                fputcsv($file, [
                    $no,
                    $asset->equipment_no,
                    $asset->description,
                    $asset->tech_ident_no,
                    $asset->equipment_type,
                    $asset->functional_loc,
                    $asset->company?->code ?? '',
                    $asset->department?->code ?? '',
                    $asset->area?->code ?? '',
                    $asset->subArea?->code ?? '',
                    $asset->manufacturer,
                    $asset->model_number,
                    $asset->construct_year,
                    $asset->status,
                    $asset->data_source,
                ]);

                $no++;
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Susun keterangan filter yang sedang aktif untuk blok judul file export.
     *
     * @param  Request  $request
     * @return string  Contoh: "PT EPE | Status: active | Pencarian: AC" atau "(semua data)"
     */
    protected function ringkasanFilterExport(Request $request): string
    {
        $bagian = [];

        if ($request->filled('search')) {
            $bagian[] = 'Pencarian: "' . $request->search . '"';
        }

        if ($request->filled('status')) {
            $bagian[] = 'Status: ' . $request->status;
        }

        if ($request->filled('company_id')) {
            $bagian[] = 'PT: ' . (Company::where('id', $request->company_id)->value('code') ?? $request->company_id);
        }

        if ($request->filled('department_id')) {
            $bagian[] = 'Departemen: ' . (Department::where('id', $request->department_id)->value('name') ?? $request->department_id);
        }

        if ($request->filled('area_id')) {
            $area = Area::where('id', $request->area_id)->first();
            $bagian[] = 'Area: ' . ($area !== null ? $area->code . ' - ' . $area->name : $request->area_id);
        }

        if ($request->filled('sub_area_id')) {
            $bagian[] = 'Sub Area: ' . (SubArea::where('id', $request->sub_area_id)->value('code') ?? $request->sub_area_id);
        }

        if ($request->filled('equipment_type')) {
            $bagian[] = 'Tipe: ' . $request->equipment_type;
        }

        return $bagian === [] ? 'semua data' : implode(' | ', $bagian);
    }

    /**
     * Bangun query Asset dengan filter dari request.
     * Dipakai bersama oleh exportExcel(), exportCsv(), dan export lengkap.
     *
     * @param  Request $request
     * @param  bool    $sertakanCermin true = ikutkan baris cermin IT Assets
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildExportQuery(Request $request, bool $sertakanCermin = false)
    {
        $query = Asset::with(['company', 'department', 'area', 'subArea']);

        if (! $sertakanCermin) {
            // Export format lama (untuk import ulang) tetap tidak menyertakan
            // baris cermin IT: datanya dikelola di halaman IT Assets.
            $query->where('assets.data_source', '!=', 'it_sync');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('assets.equipment_no', 'like', "%{$search}%")
                  ->orWhere('assets.description', 'like', "%{$search}%")
                  ->orWhere('assets.tech_ident_no', 'like', "%{$search}%")
                  ->orWhere('assets.functional_loc', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('assets.status', $request->status);
        }
        if ($request->filled('company_id')) {
            $query->where('assets.company_id', $request->company_id);
        }
        if ($request->filled('department_id')) {
            $query->where('assets.department_id', $request->department_id);
        }
        if ($request->filled('area_id')) {
            $query->where('assets.area_id', $request->area_id);
        }
        if ($request->filled('sub_area_id')) {
            $query->where('assets.sub_area_id', $request->sub_area_id);
        }
        if ($request->filled('equipment_type')) {
            $query->where('assets.equipment_type', $request->equipment_type);
        }

        // Urutan laporan: mengelompok rapi menurut lokasi
        // PT -> Departemen -> Area -> Sub Area -> Functional Loc -> Tech Ident No.
        // Join memakai alias agar kolom kode lokasi bisa dipakai untuk mengurutkan
        // tanpa bentrok dengan kolom assets. Baris tanpa lokasi diletakkan di akhir.
        $query->leftJoin('companies as exp_company', 'exp_company.id', '=', 'assets.company_id')
            ->leftJoin('departments as exp_department', 'exp_department.id', '=', 'assets.department_id')
            ->leftJoin('areas as exp_area', 'exp_area.id', '=', 'assets.area_id')
            ->leftJoin('sub_areas as exp_sub_area', 'exp_sub_area.id', '=', 'assets.sub_area_id')
            ->select('assets.*')
            ->orderByRaw('CASE WHEN exp_company.code IS NULL THEN 1 ELSE 0 END')
            ->orderBy('exp_company.code')
            ->orderBy('exp_department.code')
            ->orderBy('exp_area.code')
            ->orderBy('exp_sub_area.code')
            ->orderBy('assets.functional_loc')
            ->orderBy('assets.tech_ident_no');

        return $query;
    }
}
