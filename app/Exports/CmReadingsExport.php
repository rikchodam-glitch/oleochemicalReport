<?php

namespace App\Exports;

use App\Models\CmReading;
use Rap2hpoutre\FastExcel\FastExcel;

class CmReadingsExport
{
    /**
     * Export data readings ke file Excel dengan filter opsional.
     *
     * @param  string|null  $filterPt
     * @param  string|null  $filterTahun
     * @param  string|null  $filterBulan
     * @param  string|null  $filterStatus
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(?string $filterPt = null, ?string $filterTahun = null, ?string $filterBulan = null, ?string $filterStatus = null)
    {
        $query = CmReading::with('equipment')->orderBy('tanggal', 'desc');

        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterTahun) {
            $query->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $query->whereMonth('tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $query->where('kondisi', $filterStatus);
        }

        $readings = $query->get();

        $data = $readings->map(function ($r) {
            return [
                'Equipment Tag'   => $r->equipment->equipment_tag ?? '-',
                'PT Location'     => $r->equipment->pt_location ?? '-',
                'Tanggal'         => $r->tanggal ? $r->tanggal->format('Y-m-d') : '-',
                'Status'          => $r->status ?? '-',
                'Kondisi'         => $r->kondisi ?? '-',
                'NDEV Motor'      => $r->ndev_motor,
                'NDEH Motor'      => $r->ndeh_motor,
                'NDEA Motor'      => $r->ndea_motor,
                'Temp DE Motor'   => $r->temp_de_motor,
                'DEV Motor'       => $r->dev_motor,
                'DEH Motor'       => $r->deh_motor,
                'DEA Motor'       => $r->dea_motor,
                'NDEV Pompa'      => $r->ndev_pompa,
                'NDEH Pompa'      => $r->ndeh_pompa,
                'NDEA Pompa'      => $r->ndea_pompa,
                'Temp DE Pompa'   => $r->temp_de_pompa,
                'DEV Pompa'       => $r->dev_pompa,
                'DEH Pompa'       => $r->deh_pompa,
                'DEA Pompa'       => $r->dea_pompa,
            ];
        });

        $filename = 'cm-readings-' . now()->format('Ymd-His') . '.xlsx';

        return (new FastExcel(collect($data)))->download($filename);
    }
}
