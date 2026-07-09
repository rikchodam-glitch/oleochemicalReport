<?php

namespace App\Observers;

use App\Models\Area;
use App\Models\FunctionalLocation;
use App\Models\SubArea;

class FunctionalLocationObserver
{
    /**
     * Saat node FuncLoc L2 atau L3 baru dibuat dari halaman admin FuncLoc,
     * buat otomatis record Area/SubArea yang berpadanan jika belum ada,
     * supaya keduanya tetap tersinkronisasi dua arah.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    public function created(FunctionalLocation $funcLoc): void
    {
        if ($funcLoc->level === FunctionalLocation::LEVEL_AREA && $funcLoc->area_id === null) {
            $this->createAreaFor($funcLoc);
            return;
        }

        if ($funcLoc->level === FunctionalLocation::LEVEL_SECTION && $funcLoc->sub_area_id === null) {
            $this->createSubAreaFor($funcLoc);
        }
    }

    /**
     * Sinkronkan perubahan name ke Area/SubArea yang terhubung. Kolom
     * is_active tidak disinkronkan karena tabel areas/sub_areas tidak
     * memiliki kolom tersebut.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    public function updated(FunctionalLocation $funcLoc): void
    {
        if (! $funcLoc->wasChanged('name')) {
            return;
        }

        if ($funcLoc->area_id !== null) {
            $funcLoc->area?->updateQuietly(['name' => $funcLoc->name]);
        }

        if ($funcLoc->sub_area_id !== null) {
            $funcLoc->subArea?->updateQuietly(['name' => $funcLoc->name]);
        }
    }

    /**
     * Buat record Area baru dari FuncLoc L2, hanya jika parent (L1) sudah
     * terhubung ke Department yang valid.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createAreaFor(FunctionalLocation $funcLoc): void
    {
        $departmentId = $funcLoc->parent?->department_id;

        if ($departmentId === null) {
            // Parent (L1) belum terhubung ke Department manapun sehingga
            // Area tidak bisa dibuat (department_id wajib diisi). Node
            // tetap sah sebagai FuncLoc murni tanpa representasi Area.
            return;
        }

        $area = Area::create([
            'department_id' => $departmentId,
            'code'          => $funcLoc->segment,
            'name'          => $funcLoc->name,
            'funcloc_id'    => $funcLoc->id,
        ]);

        $funcLoc->area_id = $area->id;
        $funcLoc->saveQuietly();
    }

    /**
     * Buat record SubArea baru dari FuncLoc L3, hanya jika parent (L2) sudah
     * terhubung ke Area yang valid.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return void
     */
    private function createSubAreaFor(FunctionalLocation $funcLoc): void
    {
        $areaId = $funcLoc->parent?->area_id;

        if ($areaId === null) {
            // Parent (L2) belum terhubung ke Area manapun sehingga SubArea
            // tidak bisa dibuat (area_id wajib diisi).
            return;
        }

        $subArea = SubArea::create([
            'area_id'    => $areaId,
            'code'       => $funcLoc->segment,
            'name'       => $funcLoc->name,
            'funcloc_id' => $funcLoc->id,
        ]);

        $funcLoc->sub_area_id = $subArea->id;
        $funcLoc->saveQuietly();
    }
}
