<?php

namespace App\Http\Controllers;

use App\Services\PencarianItAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint saran pencarian halaman IT Assets.
 *
 * Dipanggil oleh dropdown kotak pencarian utama (debounce di browser) dan
 * mengembalikan potongan perangkat, tipe, plant, lokasi, serta VLAN yang
 * cocok. Aturan kata kunci memakai PencarianItAssetService yang sama dengan
 * daftar hasil.
 */
class ItAssetSearchController extends Controller
{
    /**
     * Kembalikan rekomendasi pencarian untuk kata kunci yang sedang diketik.
     *
     * @param  Request  $request  Berisi q (kata kunci)
     * @param  PencarianItAssetService  $pencarian  Service pencarian perangkat IT
     */
    public function __invoke(Request $request, PencarianItAssetService $pencarian): JsonResponse
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
