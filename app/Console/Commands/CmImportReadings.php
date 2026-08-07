<?php

namespace App\Console\Commands;

use App\Models\CmEquipment;
use App\Models\CmMonthlyTracking;
use App\Models\CmReading;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CmImportReadings extends Command
{
    protected $signature = 'cm:import-readings
                            {file : Path ke file Data_CM.xlsx}';

    protected $description = 'Import data CM dari Data_CM.xlsx ke tabel cm_equipment, cm_readings, dan cm_monthly_tracking';

    private int $successReadings = 0;
    private int $skippedReadings = 0;
    private int $successEquipment = 0;
    private int $successTracking = 0;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');
        $filePath = $this->argument('file');
        if (!file_exists($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");
            return self::FAILURE;
        }

        $this->info('>>> Memproses Data AppSheet...');
        $dataRows = $this->loadSheet($filePath, 'Data AppSheet');
        if (empty($dataRows)) return self::FAILURE;
        $this->processDataAppSheet($dataRows);
        unset($dataRows);

        $this->info('>>> Memproses Status CM...');
        $statusRows = $this->loadSheet($filePath, 'Status CM');
        if (empty($statusRows)) return self::FAILURE;
        $this->processStatusCm($statusRows);
        unset($statusRows);

        $this->info('>>> Memproses Master Equipment...');
        $masterRows = $this->loadSheet($filePath, 'Master Equipment');
        if (!empty($masterRows)) $this->processMasterEquipment($masterRows);
        unset($masterRows);

        $this->info('>>> Memproses Monitoring Bulanan...');
        $monRows = $this->loadSheet($filePath, 'Monitoring Bulanan');
        if (!empty($monRows)) $this->processMonitoringBulanan($monRows);
        unset($monRows);

        $this->newLine();
        $this->info('========================================');
        $this->info('  RINGKASAN IMPORT DATA_CM.xlsx');
        $this->info('========================================');
        $this->line("  Equipment:          {$this->successEquipment} records");
        $this->line("  Readings:           {$this->successReadings} berhasil, {$this->skippedReadings} dilewati");
        $this->line("  Monthly Tracking:   {$this->successTracking} records");

        return self::SUCCESS;
    }

    private function loadSheet(string $filePath, string $sheetName): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            $this->error("Sheet '{$sheetName}' tidak ditemukan!");
            return [];
        }

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                if ($cell->isFormula()) {
                    $val = $cell->getOldCalculatedValue();
                    $cells[] = ($val !== null && $val !== '') ? (string) $val : '';
                } else {
                    $cells[] = $cell->getValue();
                }
            }
            $rows[] = $cells;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $reader);

        while (!empty($rows) && empty(array_filter($rows[count($rows) - 1], fn($v) => $v !== null && $v !== ''))) {
            array_pop($rows);
        }

        $this->line("  Sheet '{$sheetName}': " . count($rows) . " baris.");
        return $rows;
    }

    /**
     * Proses Data AppSheet, simpan ke cache.
     * SEMUA baris dengan Equipment Tag + Date masuk termasuk yg No non-numeric.
     */
    private function processDataAppSheet(array $rows): void
    {
        $headers = $rows[0] ?? [];
        if (empty($headers)) { $this->error('Header kosong'); return; }

        $h = [];
        foreach ($headers as $i => $v) $h[trim((string) $v)] = $i;

        foreach (['No', 'Date', 'Equipment Tag'] as $r) {
            if (!isset($h[$r])) { $this->error("Kolom '{$r}' tdk ditemukan"); return; }
        }

        $idxNo = $h['No'];
        $idxDate = $h['Date'];
        $idxTag = $h['Equipment Tag'];
        $idxPt = $h['PT Location'] ?? null;
        $idxPlant = $h['Plant'] ?? null;
        $idxStatus = $h['Status'] ?? null;
        $idxTipeLub = $h['Tipe Lubrikasi'] ?? null;

        $motorKeys = ['NDEV Motor', 'NDEH Motor', 'NDEA Motor', 'Temp NDE Motor', 'DEV Motor', 'DEH Motor', 'DEA Motor'];
        $pompaKeys = ['DEV Pompa/Gearbox', 'DEH Pompa/Gearbox', 'DEA Pompa/Gearbox', 'Temp DE Pompa/Gearbox', 'NDEV Pompa/Gearbox', 'NDEH Pompa/Gearbox', 'NDEA Pompa/Gearbox', 'Temp NDE Pompa/Gearbox'];
        $screwKeys = ['DEV Screw', 'DEH Screw', 'DEA Screw', 'Temp DE Screw', 'NDEV Screw', 'NDEH Screw', 'NDEA Screw', 'Temp NDE Screw'];

        $idxMotor = []; foreach ($motorKeys as $k) $idxMotor[$k] = $h[$k] ?? null;
        $idxPompa = []; foreach ($pompaKeys as $k) $idxPompa[$k] = $h[$k] ?? null;
        $idxScrew = []; foreach ($screwKeys as $k) $idxScrew[$k] = $h[$k] ?? null;

        $idxAmpere = $h['Ampere'] ?? null;
        $idxDischarge = $h['Discharge Pressure'] ?? null;
        $idxOil = $h['Oil Level'] ?? null;
        $idxMech = $h['Mech. seal / Gasket Leak'] ?? null;
        $idxNoise = $h['Noise'] ?? null;
        $idxCoupling = $h['Coupling'] ?? null;
        $idxSafety = $h['Safety'] ?? null;
        $idxRemark = $h['Remark'] ?? null;

        $cachePath = storage_path('app/imports/_cache_readings.json');
        $existing = [];
        $rowCounter = 0;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];

            $tag = trim((string) ($row[$idxTag] ?? ''));
            if (empty($tag)) { $this->skippedReadings++; continue; }

            $tanggal = $this->parseDate($row[$idxDate] ?? null);
            if (!$tanggal) { $this->skippedReadings++; continue; }

            $noRaw = $row[$idxNo] ?? null;
            $no = null;
            if ($noRaw !== null && $noRaw !== '' && is_numeric($noRaw)) {
                $no = (int) $noRaw;
            }

            $statusVal = $this->c($idxStatus !== null ? ($row[$idxStatus] ?? null) : null) ?? 'start';

            // Key untuk cache: No jika numerik, atau tag|tanggal|status
            $key = $no !== null ? (string) $no : $tag . '|' . $tanggal . '|' . $statusVal;

            $existing[$key] = [
                'data_no'           => $no,
                'equipment_tag'     => $tag,
                'cache_key'         => $key,
                'pt_location'       => $this->c($idxPt !== null ? ($row[$idxPt] ?? null) : null),
                'plant'             => $this->c($idxPlant !== null ? ($row[$idxPlant] ?? null) : null),
                'tipe_lubrikasi'    => $this->c($idxTipeLub !== null ? ($row[$idxTipeLub] ?? null) : null),
                'tanggal'           => $tanggal,
                'status'            => $statusVal,
                'ndev_motor'        => $this->n($idxMotor['NDEV Motor'] !== null ? ($row[$idxMotor['NDEV Motor']] ?? null) : null),
                'ndeh_motor'        => $this->n($idxMotor['NDEH Motor'] !== null ? ($row[$idxMotor['NDEH Motor']] ?? null) : null),
                'ndea_motor'        => $this->n($idxMotor['NDEA Motor'] !== null ? ($row[$idxMotor['NDEA Motor']] ?? null) : null),
                'temp_de_motor'     => $this->n($idxMotor['Temp NDE Motor'] !== null ? ($row[$idxMotor['Temp NDE Motor']] ?? null) : null),
                'dev_motor'         => $this->n($idxMotor['DEV Motor'] !== null ? ($row[$idxMotor['DEV Motor']] ?? null) : null),
                'deh_motor'         => $this->n($idxMotor['DEH Motor'] !== null ? ($row[$idxMotor['DEH Motor']] ?? null) : null),
                'dea_motor'         => $this->n($idxMotor['DEA Motor'] !== null ? ($row[$idxMotor['DEA Motor']] ?? null) : null),
                'dev_pompa'         => $this->n($idxPompa['DEV Pompa/Gearbox'] !== null ? ($row[$idxPompa['DEV Pompa/Gearbox']] ?? null) : null),
                'deh_pompa'         => $this->n($idxPompa['DEH Pompa/Gearbox'] !== null ? ($row[$idxPompa['DEH Pompa/Gearbox']] ?? null) : null),
                'dea_pompa'         => $this->n($idxPompa['DEA Pompa/Gearbox'] !== null ? ($row[$idxPompa['DEA Pompa/Gearbox']] ?? null) : null),
                'temp_de_pompa'     => $this->n($idxPompa['Temp DE Pompa/Gearbox'] !== null ? ($row[$idxPompa['Temp DE Pompa/Gearbox']] ?? null) : null),
                'ndev_pompa'        => $this->n($idxPompa['NDEV Pompa/Gearbox'] !== null ? ($row[$idxPompa['NDEV Pompa/Gearbox']] ?? null) : null),
                'ndeh_pompa'        => $this->n($idxPompa['NDEH Pompa/Gearbox'] !== null ? ($row[$idxPompa['NDEH Pompa/Gearbox']] ?? null) : null),
                'ndea_pompa'        => $this->n($idxPompa['NDEA Pompa/Gearbox'] !== null ? ($row[$idxPompa['NDEA Pompa/Gearbox']] ?? null) : null),
                'temp_nde_pompa'    => $this->n($idxPompa['Temp NDE Pompa/Gearbox'] !== null ? ($row[$idxPompa['Temp NDE Pompa/Gearbox']] ?? null) : null),
                'dev_screw'         => $this->n($idxScrew['DEV Screw'] !== null ? ($row[$idxScrew['DEV Screw']] ?? null) : null),
                'deh_screw'         => $this->n($idxScrew['DEH Screw'] !== null ? ($row[$idxScrew['DEH Screw']] ?? null) : null),
                'dea_screw'         => $this->n($idxScrew['DEA Screw'] !== null ? ($row[$idxScrew['DEA Screw']] ?? null) : null),
                'temp_de_screw'     => $this->n($idxScrew['Temp DE Screw'] !== null ? ($row[$idxScrew['Temp DE Screw']] ?? null) : null),
                'ndev_screw'        => $this->n($idxScrew['NDEV Screw'] !== null ? ($row[$idxScrew['NDEV Screw']] ?? null) : null),
                'ndeh_screw'        => $this->n($idxScrew['NDEH Screw'] !== null ? ($row[$idxScrew['NDEH Screw']] ?? null) : null),
                'ndea_screw'        => $this->n($idxScrew['NDEA Screw'] !== null ? ($row[$idxScrew['NDEA Screw']] ?? null) : null),
                'temp_nde_screw'    => $this->n($idxScrew['Temp NDE Screw'] !== null ? ($row[$idxScrew['Temp NDE Screw']] ?? null) : null),
                'ampere'            => $this->n($idxAmpere !== null ? ($row[$idxAmpere] ?? null) : null),
                'discharge_pressure'=> $this->n($idxDischarge !== null ? ($row[$idxDischarge] ?? null) : null),
                'oil_level'         => $this->c($idxOil !== null ? ($row[$idxOil] ?? null) : null),
                'mech_seal'         => $this->normVis($idxMech !== null ? ($row[$idxMech] ?? null) : null),
                'noise'             => $this->normVis($idxNoise !== null ? ($row[$idxNoise] ?? null) : null),
                'coupling'          => $this->normVis($idxCoupling !== null ? ($row[$idxCoupling] ?? null) : null),
                'safety'            => $this->normVis($idxSafety !== null ? ($row[$idxSafety] ?? null) : null),
                'remark'            => $this->c($idxRemark !== null ? ($row[$idxRemark] ?? null) : null),
            ];
            $rowCounter++;
        }

        file_put_contents($cachePath, json_encode($existing));
        $this->line("  Data AppSheet: {$rowCounter} records ({$this->skippedReadings} dilewati).");
        unset($rows);
    }

    /**
     * Proses Status CM dan insert ke cm_readings.
     * Match lookup: data_no jika numerik, atau tag|tanggal|status.
     */
    private function processStatusCm(array $rows): void
    {
        $headers = $rows[0] ?? [];
        $h = [];
        foreach ($headers as $i => $v) $h[trim((string) $v)] = $i;

        $idxNo = $h['No'] ?? null;
        $idxDate = $h['Date'] ?? null;
        $idxTag = $h['Equipment Tag'] ?? null;
        $idxCondition = $h['Status Condition (ISO 10816-3)'] ?? null;
        $idxMaxVib = $h['Max. Vibration'] ?? null;
        $idxMaxTemp = $h['Max. Temp'] ?? null;
        $idxAnalysis = $h['Analysis'] ?? null;
        $idxStatus = $h['Status'] ?? null;

        if ($idxNo === null || $idxCondition === null) {
            $this->error('Kolom No / Status Condition tdk ditemukan');
            return;
        }

        $cachePath = storage_path('app/imports/_cache_readings.json');
        if (!file_exists($cachePath)) { $this->error('Cache tdk ditemukan'); return; }
        $cache = json_decode(file_get_contents($cachePath), true) ?? [];

        // Build lookup dari Status CM
        $lookup = [];
        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $tag = trim((string) ($row[$idxTag] ?? ''));
            if (empty($tag)) continue;

            $noRaw = $row[$idxNo] ?? null;
            $no = (is_numeric($noRaw)) ? (int) $noRaw : null;

            $tanggal = $this->parseDate($idxDate !== null ? ($row[$idxDate] ?? null) : null);
            $statusVal = $this->c($idxStatus !== null ? ($row[$idxStatus] ?? null) : null) ?? '';

            $lk = null;
            if ($no !== null) {
                $lk = (string) $no;
            } elseif ($tanggal && $statusVal) {
                $lk = $tag . '|' . $tanggal . '|' . $statusVal;
            }
            if (!$lk) continue;

            $lookup[$lk] = [
                'kondisi' => $this->normKondisi($this->c($row[$idxCondition] ?? null)),
                'max_vibration' => $this->n($idxMaxVib !== null ? ($row[$idxMaxVib] ?? null) : null),
                'max_temp' => $this->n($idxMaxTemp !== null ? ($row[$idxMaxTemp] ?? null) : null),
                'analysis' => $this->c($idxAnalysis !== null ? ($row[$idxAnalysis] ?? null) : null),
            ];
        }

        $this->line('  Status CM: ' . count($lookup) . ' records.');
        unset($rows);

        // Insert
        $batchInsert = [];
        $eqCache = [];
        $existingEq = CmEquipment::pluck('id', 'equipment_tag')->toArray();
        $now = now();

        foreach ($cache as $key => $rd) {
            $eqTag = $rd['equipment_tag'];
            if (!isset($eqCache[$eqTag])) {
                if (isset($existingEq[$eqTag])) {
                    $eqCache[$eqTag] = $existingEq[$eqTag];
                } else {
                    $eq = CmEquipment::updateOrCreate(['equipment_tag' => $eqTag], [
                        'pt_location'    => $rd['pt_location'] ?? 'Unknown',
                        'plant'          => $rd['plant'] ?? 'Unknown',
                        'tipe_lubrikasi' => $rd['tipe_lubrikasi'] ?? null,
                    ]);
                    $eqCache[$eqTag] = $eq->id;
                    $existingEq[$eqTag] = $eq->id;
                    $this->successEquipment++;
                }
            }

            $s = [];
            if ($rd['data_no'] !== null && isset($lookup[(string) $rd['data_no']])) {
                $s = $lookup[(string) $rd['data_no']];
            } elseif (isset($lookup[$rd['cache_key']])) {
                $s = $lookup[$rd['cache_key']];
            }

            $batchInsert[] = [
                'cm_equipment_id'    => $eqCache[$eqTag],
                'tanggal'            => $rd['tanggal'],
                'status'             => $rd['status'],
                'ndev_motor'         => $rd['ndev_motor'],
                'ndeh_motor'         => $rd['ndeh_motor'],
                'ndea_motor'         => $rd['ndea_motor'],
                'temp_de_motor'      => $rd['temp_de_motor'],
                'temp_nde_motor'     => null,
                'dev_motor'          => $rd['dev_motor'],
                'deh_motor'          => $rd['deh_motor'],
                'dea_motor'          => $rd['dea_motor'],
                'ndev_pompa'         => $rd['ndev_pompa'],
                'ndeh_pompa'         => $rd['ndeh_pompa'],
                'ndea_pompa'         => $rd['ndea_pompa'],
                'temp_de_pompa'      => $rd['temp_de_pompa'],
                'temp_nde_pompa'     => $rd['temp_nde_pompa'],
                'dev_pompa'          => $rd['dev_pompa'],
                'deh_pompa'          => $rd['deh_pompa'],
                'dea_pompa'          => $rd['dea_pompa'],
                'ndev_screw'         => $rd['ndev_screw'],
                'ndeh_screw'         => $rd['ndeh_screw'],
                'ndea_screw'         => $rd['ndea_screw'],
                'temp_de_screw'      => $rd['temp_de_screw'],
                'temp_nde_screw'     => $rd['temp_nde_screw'],
                'dev_screw'          => $rd['dev_screw'],
                'deh_screw'          => $rd['deh_screw'],
                'dea_screw'          => $rd['dea_screw'],
                'kondisi'            => $s['kondisi'] ?? 'good',
                'ampere'             => $rd['ampere'],
                'discharge_pressure' => $rd['discharge_pressure'],
                'oil_level'          => $rd['oil_level'],
                'mech_seal'          => $rd['mech_seal'],
                'noise'              => $rd['noise'],
                'coupling'           => $rd['coupling'],
                'safety'             => $rd['safety'],
                'remark'             => $rd['remark'],
                'max_vibration'      => $s['max_vibration'] ?? null,
                'max_temp'           => $s['max_temp'] ?? null,
                'analysis'           => $s['analysis'] ?? null,
                'data_no'            => $rd['data_no'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        foreach (array_chunk($batchInsert, 200) as $chunk) {
            CmReading::upsert($chunk, ['cm_equipment_id', 'tanggal', 'data_no'], [
                'status', 'kondisi',
                'ndev_motor', 'ndeh_motor', 'ndea_motor', 'temp_de_motor', 'temp_nde_motor',
                'dev_motor', 'deh_motor', 'dea_motor',
                'ndev_pompa', 'ndeh_pompa', 'ndea_pompa', 'temp_de_pompa', 'temp_nde_pompa',
                'dev_pompa', 'deh_pompa', 'dea_pompa',
                'ndev_screw', 'ndeh_screw', 'ndea_screw', 'temp_de_screw', 'temp_nde_screw',
                'dev_screw', 'deh_screw', 'dea_screw',
                'ampere', 'discharge_pressure', 'oil_level',
                'mech_seal', 'noise', 'coupling', 'safety', 'remark',
                'max_vibration', 'max_temp', 'analysis',
            ]);
            $this->successReadings += count($chunk);
        }

        @unlink($cachePath);
        $this->line('  Readings: ' . $this->successReadings . ' records di-insert.');
        unset($batchInsert, $cache, $lookup);
    }

    private function processMasterEquipment(array $rows): void
    {
        $h = [];
        foreach (($rows[0] ?? []) as $i => $v) $h[trim((string) $v)] = $i;
        if (!isset($h['Equipment Tag'])) { $this->error('Kolom Equipment Tag tdk ditemukan'); return; }
        $c = 0;
        for ($r = 1; $r < count($rows); $r++) {
            $tag = trim((string) ($rows[$r][$h['Equipment Tag']] ?? ''));
            if (empty($tag)) continue;
            CmEquipment::updateOrCreate(['equipment_tag' => $tag], [
                'pt_location' => $this->c(($h['PT Location'] ?? null) !== null ? ($rows[$r][$h['PT Location']] ?? null) : null) ?? 'Unknown',
                'plant' => $this->c(($h['Plant'] ?? null) !== null ? ($rows[$r][$h['Plant']] ?? null) : null) ?? 'Unknown',
            ]);
            $c++;
        }
        $this->successEquipment += $c;
        $this->line('  Master Equipment: ' . $c . ' records.');
    }

    private function processMonitoringBulanan(array $rows): void
    {
        $idxTag = 3; $idxPt = 1; $idxPlant = 2;
        $bulanCols = [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $now = now(); $tahun = (int) $now->format('Y');
        $batch = []; $c = 0;
        for ($r = 3; $r < count($rows); $r++) {
            $tag = trim((string) ($rows[$r][$idxTag] ?? ''));
            if (empty($tag)) continue;
            $eq = CmEquipment::firstOrCreate(['equipment_tag' => $tag], [
                'pt_location' => $rows[$r][$idxPt] ?? 'Unknown',
                'plant' => $rows[$r][$idxPlant] ?? 'Unknown',
            ]);
            foreach ($bulanCols as $bi => $ci) {
                $v = trim((string) ($rows[$r][$ci] ?? ''));
                if (empty($v)) continue;
                $batch[] = [
                    'cm_equipment_id' => $eq->id, 'tahun' => $tahun, 'bulan' => $bi + 1,
                    'status' => strtolower($v) === 'sudah' ? 'sudah' : 'belum',
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $c++;
            }
        }
        foreach (array_chunk($batch, 200) as $chunk) {
            CmMonthlyTracking::upsert($chunk, ['cm_equipment_id', 'tahun', 'bulan'], ['status']);
        }
        $this->successTracking = $c;
        $this->line('  Monitoring Bulanan: ' . $c . ' records.');
    }

    // ===== HELPERS =====
    private function c(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string) $v);
        return $s !== '' ? $s : null;
    }

    private function n(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        $cl = str_replace([' ', ','], ['', '.'], trim((string) $v));
        return is_numeric($cl) ? (float) $cl : null;
    }

    private function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v) || (is_string($v) && is_numeric($v))) {
            try { return date('Y-m-d', Date::excelToTimestamp((float) $v)); } catch (\Exception) { return null; }
        }
        $s = trim((string) $v);
        if (empty($s)) return null;
        try { return Carbon::parse($s)->format('Y-m-d'); } catch (\Exception) { return null; }
    }

    private function normVis(mixed $v): ?string
    {
        $s = $this->c($v);
        if (!$s) return null;
        $l = strtolower($s);
        return in_array($l, ['good', 'bad']) ? $l : $l;
    }

    private function normKondisi(?string $c): string
    {
        if (!$c) return 'good';
        $l = strtolower(trim($c));
        if (str_contains($l, 'danger')) return 'danger';
        if (str_contains($l, 'alarm')) return 'alarm';
        if (str_contains($l, 'visual bad') || str_contains($l, 'visual_bad')) return 'visual_bad';
        return 'good';
    }
}
