<?php

namespace App\Observers;

use App\Models\FunctionalLocation;
use App\Models\SubArea;
use App\Observers\Concerns\ResolvesFuncLocHierarchy;

class SubAreaObserver
{
    use ResolvesFuncLocHierarchy;

    /**
     * Saat SubArea dibuat, buat node FuncLoc L3 yang sesuai beserta
     * ancestor-nya (L2/L1/L0) jika belum ada. Jika funcloc_id sudah terisi
     * berarti SubArea ini dibuat oleh FunctionalLocationObserver (arah
     * sebaliknya), sehingga tidak perlu diproses lagi di sini.
     *
     * @param  SubArea  $subArea
     * @return void
     */
    public function created(SubArea $subArea): void
    {
        if ($subArea->funcloc_id !== null) {
            return;
        }

        $subArea->loadMissing('area.department.company');

        $areaNode = $this->findOrCreateAreaNode($subArea->area);
        $code     = FunctionalLocation::buildCode($areaNode, $subArea->code);

        $subAreaNode = FunctionalLocation::firstOrCreate(
            ['code' => $code],
            [
                'segment'       => strtoupper(trim($subArea->code)),
                'name'          => $subArea->name,
                'level'         => FunctionalLocation::LEVEL_SECTION,
                'parent_id'     => $areaNode->id,
                'company_id'    => $areaNode->company_id,
                'department_id' => $areaNode->department_id,
                'area_id'       => $areaNode->area_id,
                'sub_area_id'   => $subArea->id,
                'is_active'     => true,
            ]
        );

        if ($subAreaNode->sub_area_id === null) {
            $subAreaNode->sub_area_id = $subArea->id;
            $subAreaNode->saveQuietly();
        }

        $subArea->funcloc_id = $subAreaNode->id;
        $subArea->saveQuietly();
    }

    /**
     * Sinkronkan perubahan name ke FuncLoc L3 yang terhubung.
     *
     * @param  SubArea  $subArea
     * @return void
     */
    public function updated(SubArea $subArea): void
    {
        if (! $subArea->wasChanged('name') || $subArea->funcloc_id === null) {
            return;
        }

        $subArea->functionalLocation?->updateQuietly(['name' => $subArea->name]);
    }
}
