<?php

namespace App\Http\Controllers;

use App\Services\PencarianLaporanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint saran pencarian halaman Laporan.
 *
 * Dipanggil oleh dropdown kotak pencarian utama (debounce di browser) dan
 * mengembalikan potongan laporan, teknisi, area, alat, serta lokasi yang
 * cocok. Aturan kata kunci sepenuhnya didelegasikan ke
 * PencarianLaporanService supaya hasil saran sama dengan tabel hasil.
 */
class ReportSearchController extends Controller
{
    /**
     * Kembalikan rekomendasi pencarian untuk kata kunci yang sedang diketik.
     *
     * @param  Request                $request    Berisi q (kata kunci)
     * @param  PencarianLaporanService $pencarian  Service pencarian laporan
     * @return JsonResponse
     */
    public function __invoke(Request $request, PencarianLaporanService $pencarian): JsonResponse
    {
        $kataKunci = trim((string) $request->query('q', ''));

        if (mb_strlen($kataKunci) < $pencarian->minHuruf()) {
            return response()->json([
                'kata_kunci' => $kataKunci,
                'total'      => 0,
                'kelompok'   => [],
            ]);
        }

        return response()->json($pencarian->saran($kataKunci));
    }
}
