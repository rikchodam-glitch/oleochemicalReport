<?php

namespace App\Observers;

use App\Models\Area;
use App\Observers\Concerns\ResolvesFuncLocHierarchy;

class AreaObserver
{
    use ResolvesFuncLocHierarchy;

    /**
     * Saat Area dibuat, kaitkan ke node FuncLoc L2 yang sesuai. Jika
     * funcloc_id sudah terisi berarti Area ini dibuat oleh
     * FunctionalLocationObserver (arah sebaliknya), sehingga tidak perlu
     * diproses lagi di sini.
     *
     * @param  Area  $area
     * @return void
     */
    public function created(Area $area): void
    {
        if ($area->funcloc_id !== null) {
            return;
        }

        $area->loadMissing('department.company');
        $this->findOrCreateAreaNode($area);
    }

    /**
     * Sinkronkan perubahan name ke FuncLoc L2 yang terhubung.
     *
     * @param  Area  $area
     * @return void
     */
    public function updated(Area $area): void
    {
        if (! $area->wasChanged('name') || $area->funcloc_id === null) {
            return;
        }

        $area->functionalLocation?->updateQuietly(['name' => $area->name]);
    }
}
