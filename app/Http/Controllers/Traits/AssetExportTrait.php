<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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

        // Baris header kolom
        $headers = [
            'Equipment No', 'Description', 'Tech Ident No', 'Object Type',
            'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
            'Manufacturer', 'Model Number', 'Construct Year', 'Status',
            'Data Source', 'Imported At', 'Created At', 'Updated At',
        ];

        foreach (array_values($headers) as $i => $header) {
            $col = chr(65 + $i); // A, B, C...
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        // Baris data
        $rowNum = 2;
        foreach ($assets as $asset) {
            $sheet->setCellValue('A' . $rowNum, $asset->equipment_no);
            $sheet->setCellValue('B' . $rowNum, $asset->description);
            $sheet->setCellValue('C' . $rowNum, $asset->tech_ident_no);
            $sheet->setCellValue('D' . $rowNum, $asset->object_type);
            $sheet->setCellValue('E' . $rowNum, $asset->functional_loc);
            $sheet->setCellValue('F' . $rowNum, $asset->company?->code ?? '');
            $sheet->setCellValue('G' . $rowNum, $asset->department?->code ?? '');
            $sheet->setCellValue('H' . $rowNum, $asset->area?->code ?? '');
            $sheet->setCellValue('I' . $rowNum, $asset->subArea?->code ?? '');
            $sheet->setCellValue('J' . $rowNum, $asset->manufacturer);
            $sheet->setCellValue('K' . $rowNum, $asset->model_number);
            $sheet->setCellValue('L' . $rowNum, $asset->construct_year);
            $sheet->setCellValue('M' . $rowNum, $asset->status);
            $sheet->setCellValue('N' . $rowNum, $asset->data_source);
            $sheet->setCellValue('O' . $rowNum, $asset->imported_at?->format('Y-m-d H:i:s') ?? '');
            $sheet->setCellValue('P' . $rowNum, $asset->created_at->format('Y-m-d H:i:s'));
            $sheet->setCellValue('Q' . $rowNum, $asset->updated_at->format('Y-m-d H:i:s'));
            $rowNum++;
        }

        // Auto-size semua kolom
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

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

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($assets) {
            $file = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel tidak salah baca karakter multibyte
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Baris header kolom
            fputcsv($file, [
                'Equipment No', 'Description', 'Tech Ident No', 'Object Type',
                'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
                'Manufacturer', 'Model Number', 'Construct Year', 'Status',
                'Data Source', 'Imported At', 'Created At', 'Updated At',
            ]);

            // Baris data
            foreach ($assets as $asset) {
                fputcsv($file, [
                    $asset->equipment_no,
                    $asset->description,
                    $asset->tech_ident_no,
                    $asset->object_type,
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
                    $asset->imported_at?->format('Y-m-d H:i:s') ?? '',
                    $asset->created_at->format('Y-m-d H:i:s'),
                    $asset->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bangun query Asset dengan filter dari request.
     * Dipakai bersama oleh exportExcel() dan exportCsv().
     *
     * @param  Request  $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function buildExportQuery(Request $request)
    {
        $query = Asset::with(['company', 'department', 'area', 'subArea']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('equipment_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('tech_ident_no', 'like', "%{$search}%")
                  ->orWhere('functional_loc', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }
        if ($request->filled('sub_area_id')) {
            $query->where('sub_area_id', $request->sub_area_id);
        }
        if ($request->filled('object_type')) {
            $query->where('object_type', $request->object_type);
        }

        $query->latest();

        return $query;
    }
}
