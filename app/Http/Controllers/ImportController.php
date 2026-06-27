<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use Illuminate\Http\Request;

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
}
