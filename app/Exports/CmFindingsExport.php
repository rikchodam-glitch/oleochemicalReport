<?php

namespace App\Exports;

use App\Models\CmFinding;
use Rap2hpoutre\FastExcel\FastExcel;

class CmFindingsExport
{
    /**
     * Export data findings ke file Excel dengan filter opsional.
     *
     * @param  string|null  $filterPt
     * @param  string|null  $filterStatus
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(?string $filterPt = null, ?string $filterStatus = null)
    {
        $query = CmFinding::with('equipment')->orderBy('tanggal_temuan', 'desc');

        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterStatus) {
            $query->where('status', $filterStatus);
        }

        $findings = $query->get();

        $data = $findings->map(function ($f) {
            return [
                'Equipment Tag'    => $f->equipment->equipment_tag ?? '-',
                'PT Location'      => $f->equipment->pt_location ?? '-',
                'Tanggal Temuan'   => $f->tanggal_temuan ? $f->tanggal_temuan->format('Y-m-d') : '-',
                'Severity'         => $f->severity ?? '-',
                'Kategori'         => $f->kategori ?? '-',
                'Deskripsi'        => $f->deskripsi ?? '-',
                'Status'           => $f->status ?? '-',
                'PIC'              => $f->pic ?? '-',
                'Hari Open'        => $f->hari_open ?? 0,
            ];
        });

        $filename = 'cm-findings-' . now()->format('Ymd-His') . '.xlsx';

        return (new FastExcel(collect($data)))->download($filename);
    }
}
