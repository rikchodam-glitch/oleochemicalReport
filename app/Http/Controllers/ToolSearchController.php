<?php

namespace App\Http\Controllers;

use App\Services\PencarianToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint saran pencarian halaman Management Tools dan /alat.
 */
class ToolSearchController extends Controller
{
    /**
     * Kembalikan saran pencarian untuk kata kunci yang sedang diketik.
     */
    public function __invoke(Request $request, PencarianToolService $pencarian): JsonResponse
    {
        $kataKunci = trim((string) $request->query('q', ''));

        if (mb_strlen($kataKunci) < $pencarian->minHuruf()) {
            return response()->json(['kata_kunci' => $kataKunci, 'total' => 0, 'kelompok' => []]);
        }

        $basis = $request->routeIs('alat.*') ? 'alat' : 'tools';

        return response()->json($pencarian->saran($kataKunci, $basis));
    }
}
