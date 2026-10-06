<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    protected ImportService $importService;

    public function __construct(ImportService $importService)
    {
        $this->importService = $importService;
    }

    public function showImport()
    {
        return view('assets.import');
    }

    public function previewImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        try {
            ini_set('memory_limit', '512M');

            $analysis = $this->importService->analyzeFile($request->file('file'));

            return response()->json([
                'success' => true,
                'analysis' => $analysis,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membaca file: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function executeImport(Request $request)
    {
        $request->validate([
            'analysis' => 'required|array',
            'duplicate_action' => 'required|in:replace,skip,keep_flag',
            'no_equip_action' => 'required|in:flag,skip,cancel',
            'filename' => 'nullable|string',
        ]);

        try {
            ini_set('memory_limit', '512M');

            $result = $this->importService->executeImport(
                $request->analysis,
                $request->only(['duplicate_action', 'no_equip_action', 'filename'])
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unduh template Excel import asset. Kolom pada template ini persis
     * dengan kolom yang dibaca ImportService (buildColMap), sehingga file
     * yang diisi user akan terbaca tanpa ada kolom yang terlewat.
     *
     * Baris data pertama (row 2 dst) diisi oleh user. Sheet "Petunjuk"
     * berisi aturan pengisian agar tidak ada baris yang ditolak saat import.
     *
     * @return StreamedResponse
     */
    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        // ---------- Sheet 1: Asset Data ----------
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asset Data');

        $columns = [
            'Equipment No'    => 'OPSIONAL. Nomor aset 8 digit yang dipakai sebagai kunci pencocokan utama saat import. Isi apa adanya dari file hasil Export; kosongkan untuk aset baru (nomornya dibuat sistem).',
            'TechIdentNo.'    => 'WAJIB bila kolom Equipment No kosong. Kode Alat (identitas unik satu aset), contoh: AC-TF-1-1',
            'Description'     => 'Nama/deskripsi asset, contoh: Motor Pompa Transfer',
            'Object Type'     => 'Kode tipe SAP, contoh: ZPM-MOT / ZPM-PU1',
            'Equipment Type'  => 'Tipe equipment, contoh: MOTOR / PUMP / AC',
            'Functional Loc.' => 'WAJIB. Format COMPANY-DEPT-AREA-SUBAREA, contoh: EPE-PROD-BD02-6153',
            'Manufacturer'    => 'Pabrikan (opsional)',
            'Model number'    => 'Nomor model (opsional)',
            'ConstructYear'   => 'Tahun pembuatan, contoh: 2021 (opsional)',
        ];

        $col = 1;
        foreach ($columns as $header => $note) {
            $cell = $sheet->getCell([$col, 1]);
            $cell->setValue($header);

            $style = $sheet->getStyle([$col, 1]);
            $style->getFont()->setBold(true);
            $style->getFont()->getColor()->setARGB('FFFFFFFF');
            $style->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB('FF0E9E8E');

            // Catatan isian ditaruh sebagai komentar sel agar tidak
            // mengganggu baris data.
            $sheet->getComment([$col, 1])->getText()->createTextRun($note);

            $sheet->getColumnDimensionByColumn($col)->setWidth(22);
            $col++;
        }

        $sheet->getStyle('A1:I1')->getAlignment()->setVertical('center');
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        // ---------- Sheet 2: Petunjuk ----------
        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->getColumnDimension('A')->setWidth(110);

        $lines = [
            'PETUNJUK PENGISIAN TEMPLATE IMPORT ASSET',
            '',
            '1. Isi data mulai baris ke-2 pada sheet "Asset Data". JANGAN mengubah nama header di baris 1.',
            '2. Kolom WAJIB: TechIdentNo. dan Functional Loc. Baris yang salah satunya kosong TIDAK akan diimport.',
            '3. Kolom Equipment No bersifat OPSIONAL: nomor itu dipakai sebagai KUNCI Pencocokan utama.',
            '   - Diisi dan nomornya ada di database -> aset itu DIPERBARUI; Kode Alat (TechIdentNo.) boleh',
            '     ikut diubah tanpa membuat aset baru.',
            '   - Diisi tetapi nomornya belum ada di database -> baris dicocokkan lewat TechIdentNo.',
            '   - Dikosongkan dan TechIdentNo.-nya belum ada -> baris dibuat sebagai aset BARU, nomornya',
            '     dibuat sistem mengikuti seri milik PT dari lokasi yang dipilih.',
            '4. TechIdentNo. (Kode Alat) adalah identitas cadangan: dipakai bila kolom Equipment No kosong',
            '   atau nomornya belum ada di database.',
            '   - TechIdentNo. baru yang sudah dipakai aset lain membuat baris ditandai "Perlu Diperiksa".',
            '   - Equipment No atau TechIdentNo. yang muncul dua kali dalam satu file dilewati (perlu diperiksa).',
            '5. Functional Loc. memakai format COMPANY-DEPARTEMEN-AREA-SUBAREA, contoh:',
            '   - EPE-PROD-BD02-6153  (company EPE, dept PROD, area BD02, subarea 6153)',
            '   - EPE-LOGE-ST01       (3 bagian: company, dept, area - tanpa subarea)',
            '6. Kolom lain bersifat opsional tapi disarankan diisi: Description, Object Type,',
            '   Equipment Type, Manufacturer, Model number, ConstructYear.',
            '7. Cukup isi satu sheet. Sheet kosong lain di dalam file yang sama tidak akan mengganggu import.',
            '8. Sebelum dijalankan, halaman import menampilkan dua daftar: DATA BARU dan DATA YANG AKAN',
            '   DIPERBARUI (beserta field yang berubah), supaya bisa diperiksa lebih dulu.',
            '',
            'CONTOH BARIS (isi di baris ke-2, ganti dengan data asli):',
            'TechIdentNo.=MP-101 | Description=Motor Pompa Transfer | Object Type=ZPM-MOT',
            'Equipment Type=MOTOR | Functional Loc.=EPE-PROD-BD02-6153 | Manufacturer=SIEMENS | Model number=1LA4 | ConstructYear=2021',
        ];

        foreach ($lines as $i => $line) {
            $guide->setCellValue([1, $i + 1], $line);
        }

        $guide->getStyle('A1')->getFont()->setBold(true);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'Template_Import_Asset.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
