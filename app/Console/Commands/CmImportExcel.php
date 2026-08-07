<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CmImportExcel extends Command
{
    protected $signature = 'cm:import-excel
                            {data-cm : Path ke file Data_CM.xlsx}
                            {finding-cm : Path ke file Finding_CM.xlsx}';

    protected $description = 'Import semua data CM dari Data_CM.xlsx dan Finding_CM.xlsx';

    public function handle(): int
    {
        $dataCmPath = $this->argument('data-cm');
        $findingCmPath = $this->argument('finding-cm');

        // Validasi file
        if (!file_exists($dataCmPath)) {
            $this->error("File Data_CM.xlsx tidak ditemukan: {$dataCmPath}");
            return self::FAILURE;
        }
        if (!file_exists($findingCmPath)) {
            $this->error("File Finding_CM.xlsx tidak ditemukan: {$findingCmPath}");
            return self::FAILURE;
        }

        $this->info('========================================');
        $this->info('  IMPORT DATA CONDITION MONITORING');
        $this->info('========================================');
        $this->newLine();

        // Step 1: Import Readings
        $this->info('>>> [1/2] Import Data_CM.xlsx (Readings, Equipment, Tracking)...');
        $exitCode1 = $this->call('cm:import-readings', [
            'file' => $dataCmPath,
        ]);

        if ($exitCode1 !== 0) {
            $this->error('Gagal mengimport Data_CM.xlsx!');
            return self::FAILURE;
        }

        $this->newLine();

        // Step 2: Import Findings
        $this->info('>>> [2/2] Import Finding_CM.xlsx (Findings)...');
        $exitCode2 = $this->call('cm:import-findings', [
            'file' => $findingCmPath,
        ]);

        if ($exitCode2 !== 0) {
            $this->error('Gagal mengimport Finding_CM.xlsx!');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('========================================');
        $this->info('  IMPORT SELESAI');
        $this->info('========================================');

        // Tampilkan summary count
        $this->newLine();
        $this->table(
            ['Tabel', 'Jumlah Record'],
            [
                ['cm_equipment', \App\Models\CmEquipment::count()],
                ['cm_readings', \App\Models\CmReading::count()],
                ['cm_findings', \App\Models\CmFinding::count()],
                ['cm_monthly_tracking', \App\Models\CmMonthlyTracking::count()],
            ]
        );

        return self::SUCCESS;
    }
}
