<?php

namespace App\Exports;

use App\Models\CmMonthlyTracking;
use Rap2hpoutre\FastExcel\FastExcel;

class CmMonitoringExport
{
    /**
     * Export data monthly tracking ke file Excel dengan filter opsional.
     *
     * @param  string|null  $filterTahun
     * @param  string|null  $filterPt
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(?string $filterTahun = null, ?string $filterPt = null)
    {
        $query = CmMonthlyTracking::with('equipment')
            ->orderBy('tahun', 'desc')
            ->orderBy('bulan', 'desc');

        if ($filterTahun) {
            $query->where('tahun', $filterTahun);
        }
        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }

        $trackings = $query->get();

        $data = $trackings->map(function ($t) {
            $bulanLabels = [
                1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
                5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
                9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
            ];

            return [
                'Equipment Tag'     => $t->equipment->equipment_tag ?? '-',
                'PT Location'       => $t->equipment->pt_location ?? '-',
                'Tahun'             => $t->tahun,
                'Bulan'             => $bulanLabels[$t->bulan] ?? $t->bulan,
                'Bulan (Angka)'     => $t->bulan,
                'Status'            => ucfirst($t->status ?? '-'),
            ];
        });

        $filename = 'cm-monitoring-' . now()->format('Ymd-His') . '.xlsx';

        return (new FastExcel(collect($data)))->download($filename);
    }
}
