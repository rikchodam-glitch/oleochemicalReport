<?php

namespace App\Http\Controllers\Traits;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use Illuminate\Http\Request;

/**
 * Trait ReportLocationTrait
 *
 * Berisi endpoint AJAX untuk lookup Functional Location dan Asset
 * berdasarkan Area, dipakai untuk mengisi dropdown dinamis di form
 * edit laporan (reports/edit). Berbeda dari AssetLocationTrait yang
 * melayani hierarki lokasi milik halaman Asset Management — trait ini
 * khusus untuk kebutuhan pemilihan lokasi/alat pada satu laporan.
 *
 * Method yang ada:
 *   - getFuncLocsByArea() : Daftar Functional Location berdasarkan area_id
 *   - getAssetsByArea()   : Daftar Asset berdasarkan area_id, opsional difilter funcloc_id
 */
trait ReportLocationTrait
{
    /**
     * Kembalikan daftar Functional Location yang termasuk dalam area tertentu.
     * Dipakai untuk mengisi dropdown Functional Location secara dinamis
     * di form edit laporan setelah Area dipilih.
     *
     * @param  Request  $request  Harus mengandung area_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFuncLocsByArea(Request $request)
    {
        $funcLocs = FunctionalLocation::where('area_id', $request->area_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'level']);

        return response()->json($funcLocs);
    }

    /**
     * Kembalikan daftar Asset yang termasuk dalam area tertentu.
     * Jika funcloc_id disertakan, hasil difilter lebih lanjut berdasarkan
     * Functional Location tersebut. Dipakai untuk mengisi dropdown Asset
     * secara dinamis di form edit laporan.
     *
     * @param  Request  $request  Harus mengandung area_id, opsional funcloc_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssetsByArea(Request $request)
    {
        $assets = Asset::where('area_id', $request->area_id)
            ->when($request->filled('funcloc_id'), function ($query) use ($request) {
                $query->where('funcloc_id', $request->funcloc_id);
            })
            ->orderBy('tech_ident_no')
            ->get(['id', 'equipment_no', 'tech_ident_no', 'description']);

        return response()->json($assets);
    }
}
