<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCmExcelImport;
use App\Jobs\ProcessCmFindingsImport;
use App\Models\ImportLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CmImportController extends Controller
{
    public function showImport()
    {
        return redirect()->route('cm.overview');
    }

    public function uploadImport(Request $request)
    {
        set_time_limit(600); // Maks 10 menit untuk upload + proses file besar

        $request->validate([
            'file' => 'required|file|mimes:xlsx|max:10240',
        ]);

        $file = $request->file('file');
        $filePath = $file->storeAs(
            'cm-imports',
            'cm-import-' . now()->format('Ymd-His') . '-' . uniqid() . '.xlsx'
        );

        $fullPath = Storage::path($filePath);

        $requiredSheetsReadings = ['Data AppSheet', 'Status CM'];
        $requiredSheetFindings  = ['Finding CM'];

        $zip = new \ZipArchive();
        if ($zip->open($fullPath) === true) {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $zip->close();

            if ($workbookXml) {
                $xml = simplexml_load_string($workbookXml);
                $sheets = $xml->sheets->sheet ?? [];

                $foundSheets = [];
                foreach ($sheets as $sheet) {
                    $attrs = $sheet->attributes();
                    $foundSheets[] = (string) $attrs['name'];
                }

                if (count(array_intersect($requiredSheetFindings, $foundSheets)) === count($requiredSheetFindings)) {
                    // File Finding_CM.xlsx
                    $importLog = ImportLog::create([
                        'nama_file'   => $file->getClientOriginalName(),
                        'tipe_import' => 'cm_findings',
                        'total_baris' => 0,
                        'insert_baru' => 0,
                        'update_existing' => 0,
                        'gagal'       => 0,
                        'unregistered' => 0,
                        'status'      => 'processing',
                        'uploaded_by' => auth()->id(),
                    ]);

                    ProcessCmFindingsImport::dispatch($fullPath, $importLog->id, auth()->id());

                    return response()->json([
                        'success' => true,
                        'message' => 'File Finding CM berhasil diupload dan sedang diproses di latar belakang.',
                        'import_log_id' => $importLog->id,
                    ]);
                }

                // Cek sheet untuk file Data_CM.xlsx
                $missingSheets = [];
                foreach ($requiredSheetsReadings as $required) {
                    if (!in_array($required, $foundSheets)) {
                        $missingSheets[] = $required;
                    }
                }

                if (!empty($missingSheets)) {
                    unlink($fullPath);
                    return response()->json([
                        'success' => false,
                        'message' => 'File tidak dikenali. Untuk Data CM harus ada sheet: ' . implode(', ', $missingSheets)
                            . '. Untuk Finding CM harus ada sheet: Finding CM.',
                    ], 422);
                }
            }
        } else {
            unlink($fullPath);
            return response()->json([
                'success' => false,
                'message' => 'File tidak dapat dibaca, pastikan format .xlsx valid.',
            ], 422);
        }

        $importLog = ImportLog::create([
            'nama_file'   => $file->getClientOriginalName(),
            'tipe_import' => 'cm_excel',
            'total_baris' => 0,
            'insert_baru' => 0,
            'update_existing' => 0,
            'gagal'       => 0,
            'unregistered' => 0,
            'status'      => 'processing',
            'uploaded_by' => auth()->id(),
        ]);

        ProcessCmExcelImport::dispatch($fullPath, $importLog->id, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'File berhasil diupload dan sedang diproses di latar belakang.',
            'import_log_id' => $importLog->id,
        ]);
    }

    public function checkStatus(int $id)
    {
        $log = ImportLog::findOrFail($id);

        return response()->json([
            'status'  => $log->status,
            'total_baris'    => $log->total_baris,
            'total_dibaca'   => $log->total_dibaca ?? 0,
            'insert_baru'    => $log->insert_baru,
            'update_existing' => $log->update_existing,
            'gagal'          => $log->gagal,
            'unregistered'   => $log->unregistered,
            'total_skipped_duplikat' => $log->total_skipped_duplikat ?? 0,
            'total_skipped_kosong'   => $log->total_skipped_kosong ?? 0,
            'detail_unregistered' => $log->detail_unregistered ?? [],
            'detail_error'   => $log->detail_error ?? [],
            'completed_at'   => $log->completed_at ? $log->completed_at->format('d M Y H:i:s') : null,
        ]);
    }
}
