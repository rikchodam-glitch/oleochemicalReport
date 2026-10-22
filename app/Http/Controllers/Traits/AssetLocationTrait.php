<?php

namespace App\Http\Controllers\Traits;

use App\Services\LocationHierarchyService;
use Illuminate\Http\Request;

/**
 * Trait AssetLocationTrait
 *
 * Berisi endpoint AJAX untuk lookup hierarki lokasi (Company -> Department
 * -> Area -> SubArea) yang dipakai untuk mengisi dropdown filter secara dinamis.
 * Dikelompokkan bersama karena semuanya hanya membaca tabel lokasi tanpa
 * menyentuh data asset itu sendiri.
 *
 * Method yang ada:
 *   - getDepartments() : Daftar department berdasarkan company_id
 *   - getAreas()       : Daftar area berdasarkan department_id
 *   - getSubAreas()    : Daftar sub area berdasarkan area_id
 */
trait AssetLocationTrait
{
    /**
     * Kembalikan daftar department yang termasuk dalam company tertentu.
     * Dipakai untuk mengisi dropdown department secara dinamis di form filter.
     *
     * @param  Request  $request  Harus mengandung company_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDepartments(Request $request, LocationHierarchyService $locations)
    {
        return response()->json(
            $locations->departemenUntukCompany((int) $request->company_id)
        );
    }

    /**
     * Kembalikan daftar area yang termasuk dalam department tertentu.
     * Dipakai untuk mengisi dropdown area secara dinamis di form filter.
     *
     * @param  Request  $request  Harus mengandung department_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAreas(Request $request, LocationHierarchyService $locations)
    {
        return response()->json(
            $locations->areaUntukDepartemen((int) $request->department_id)
        );
    }

    /**
     * Kembalikan daftar sub area yang termasuk dalam area tertentu.
     * Dipakai untuk mengisi dropdown sub area secara dinamis di form filter.
     *
     * @param  Request  $request  Harus mengandung area_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubAreas(Request $request, LocationHierarchyService $locations)
    {
        return response()->json(
            $locations->subAreaUntukArea((int) $request->area_id)
        );
    }
}
