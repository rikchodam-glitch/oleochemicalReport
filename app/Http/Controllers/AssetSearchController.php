<?php

namespace App\Http\Controllers;

use App\Services\PencarianAsetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint saran pencarian halaman Asset Management.
 *
 * Dipanggil oleh dropdown kotak pencarian utama (debounce di browser) dan
 * mengembalikan potongan asset, tipe equipment, serta lokasi yang cocok.
 * Aturan kata kunci sepenuhnya didelegasikan ke PencarianAsetService supaya
 * hasil saran sama dengan daftar hasil.
 */
class AssetSearchController extends Controller
{
    /**
     * Kembalikan rekomendasi pencarian untuk kata kunci yang sedang diketik.
     *
     * @param  Request  $request  Berisi q (kata kunci)
     * @param  PencarianAsetService  $pencarian  Service pencarian asset
     */
    public function __invoke(Request $request, PencarianAsetService $pencarian): JsonResponse
    {
        $kataKunci = trim((string) $request->query('q', ''));

        if (mb_strlen($kataKunci) < $pencarian->minHuruf()) {
            return response()->json([
                'kata_kunci' => $kataKunci,
                'total' => 0,
                'kelompok' => [],
            ]);
        }

        return response()->json($pencarian->saran($kataKunci));
    }
}
