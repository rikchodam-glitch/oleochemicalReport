<?php

namespace App\Console\Commands;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CmImportFindings extends Command
{
    protected $signature = 'cm:import-findings
                            {file : Path ke file Finding_CM.xlsx}';

    protected $description = 'Import data Finding CM dari Finding_CM.xlsx ke tabel cm_findings';

    private int $successFindings = 0;
    private int $skippedFindings = 0;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("File tidak ditemukan: {$filePath}");
            return self::FAILURE;
        }

        $this->info('Memuat file Finding_CM.xlsx (read data only)...');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        // Sheet: Finding CM
        $this->info('Memproses Sheet "Finding CM"...');
        $sheet = $spreadsheet->getSheetByName('Finding CM');
        if (!$sheet) {
            $this->error('Sheet "Finding CM" tidak ditemukan!');
            return self::FAILURE;
        }

        $this->processFindingCm($sheet);

        $spreadsheet->disconnectWorksheets();

        // Ringkasan
        $this->newLine();
        $this->info('========================================');
        $this->info('  RINGKASAN IMPORT FINDING_CM.xlsx');
        $this->info('========================================');
        $this->line("  Findings: {$this->successFindings} berhasil, {$this->skippedFindings} dilewati");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Baca sheet baris per baris pakai iterator untuk hemat memory.
     */
    private function readSheetRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $cells[] = $cell->getFormattedValue();
            }
            $rows[] = $cells;
        }
        while (!empty($rows) && empty(array_filter($rows[count($rows) - 1], fn($v) => $v !== null && $v !== ''))) {
            array_pop($rows);
        }
        return $rows;
    }

    private function processFindingCm(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $rows = $this->readSheetRows($sheet);
        $headers = $rows[0] ?? [];
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $headerMap[trim((string) $h)] = $i;
        }

        $this->line('  Header ditemukan: ' . implode(', ', array_keys($headerMap)));

        $required = ['No', 'Equipment Tag', 'Date Found'];
        foreach ($required as $r) {
            if (!isset($headerMap[$r])) {
                $this->error("Kolom '{$r}' tidak ditemukan di Finding CM.");
                return;
            }
        }

        $idxNo = $headerMap['No'];
        $idxIdParent = $headerMap['ID_CM_Parent'] ?? null;
        $idxDateFound = $headerMap['Date Found'];
        $idxPt = $headerMap['PT Location'] ?? null;
        $idxPlant = $headerMap['Plant'] ?? null;
        $idxTag = $headerMap['Equipment Tag'];
        $idxFinding1 = $headerMap['Finding Foto 1'] ?? null;
        $idxFinding2 = $headerMap['Finding Foto 2'] ?? null;
        $idxFinding3 = $headerMap['Finding Foto 3'] ?? null;
        $idxLink1 = $headerMap['Link Foto 1'] ?? null;
        $idxLink2 = $headerMap['Link Foto 2'] ?? null;
        $idxLink3 = $headerMap['Link Foto 3'] ?? null;
        $idxVibLink1 = $headerMap['Link Foto Vibxpert 1'] ?? null;
        $idxVibLink2 = $headerMap['Link Foto Vibxpert 2'] ?? null;
        $idxAnalysis = $headerMap['Analysis'] ?? null;
        $idxAction = $headerMap['Action'] ?? null;
        $idxPic = $headerMap['PIC'] ?? null;
        $idxDateAction = $headerMap['Date Action'] ?? null;
        $idxSeverity = $headerMap['Severity Level'] ?? null;
        $idxStatus = $headerMap['Status'] ?? null;
        $idxRemark = $headerMap['Remark'] ?? null;

        $preloadEq = CmEquipment::pluck('id', 'equipment_tag')->toArray();
        $batchInsert = [];
        $now = now();

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];

            $kodeFinding = $this->cellVal($row[$idxNo] ?? null);
            if (empty($kodeFinding)) {
                $this->skippedFindings++;
                Log::warning("CmImportFindings: Row {$r} skipped — No (kode finding) kosong");
                continue;
            }
            $kodeFinding = trim((string) $kodeFinding);

            $equipmentTag = trim((string) ($row[$idxTag] ?? ''));
            if (empty($equipmentTag)) {
                $this->skippedFindings++;
                Log::warning("CmImportFindings: Row {$r} skipped — Equipment Tag kosong, No={$kodeFinding}");
                continue;
            }

            $tanggalRaw = $row[$idxDateFound] ?? null;
            $tanggalTemuan = $this->parseDate($tanggalRaw);
            if (!$tanggalTemuan) {
                $this->skippedFindings++;
                Log::warning("CmImportFindings: Row {$r} skipped — Date Found invalid, No={$kodeFinding}");
                continue;
            }

            $dateAction = null;
            if ($idxDateAction !== null) {
                $dateAction = $this->parseDate($row[$idxDateAction] ?? null);
            }

            // Kategori dari Finding Foto 1/2/3
            $kategoriParts = [];
            if ($idxFinding1 !== null) {
                $f1 = $this->cellVal($row[$idxFinding1] ?? null);
                if ($f1) $kategoriParts[] = $f1;
            }
            if ($idxFinding2 !== null) {
                $f2 = $this->cellVal($row[$idxFinding2] ?? null);
                if ($f2) $kategoriParts[] = $f2;
            }
            if ($idxFinding3 !== null) {
                $f3 = $this->cellVal($row[$idxFinding3] ?? null);
                if ($f3) $kategoriParts[] = $f3;
            }
            $kategori = !empty($kategoriParts) ? implode(', ', $kategoriParts) : 'Unknown';

            // Foto URLs
            $fotoUrls = [];
            if ($idxLink1 !== null) {
                $l1 = $this->cellVal($row[$idxLink1] ?? null);
                if ($l1) $fotoUrls[] = $l1;
            }
            if ($idxLink2 !== null) {
                $l2 = $this->cellVal($row[$idxLink2] ?? null);
                if ($l2) $fotoUrls[] = $l2;
            }
            if ($idxLink3 !== null) {
                $l3 = $this->cellVal($row[$idxLink3] ?? null);
                if ($l3) $fotoUrls[] = $l3;
            }
            if ($idxVibLink1 !== null) {
                $v1 = $this->cellVal($row[$idxVibLink1] ?? null);
                if ($v1) $fotoUrls[] = $v1;
            }
            if ($idxVibLink2 !== null) {
                $v2 = $this->cellVal($row[$idxVibLink2] ?? null);
                if ($v2) $fotoUrls[] = $v2;
            }

            $fotoUrlFirst = !empty($fotoUrls) ? $fotoUrls[0] : null;

            $analysis = $this->cellVal($idxAnalysis !== null ? ($row[$idxAnalysis] ?? null) : null);
            $action = $this->cellVal($idxAction !== null ? ($row[$idxAction] ?? null) : null);

            $deskripsi = '';
            if ($analysis) $deskripsi .= $analysis;
            if ($action) $deskripsi .= ($deskripsi ? "\n\n" : '') . $action;

            $pic = $this->cellVal($idxPic !== null ? ($row[$idxPic] ?? null) : null);

            $severityRaw = $this->cellVal($idxSeverity !== null ? ($row[$idxSeverity] ?? null) : null);
            $severity = $severityRaw ? strtolower($severityRaw) : null;
            if ($severity && !in_array($severity, ['low', 'medium', 'high'])) {
                $severity = null;
            }

            $statusRaw = $this->cellVal($idxStatus !== null ? ($row[$idxStatus] ?? null) : null);
            $status = $statusRaw ? strtolower($statusRaw) : 'open';
            if (!in_array($status, ['open', 'closed'])) $status = 'open';

            // Hitung hari_open
            $tanggalAwal = Carbon::parse($tanggalTemuan);
            $tanggalAkhir = $status === 'closed' && $dateAction ? Carbon::parse($dateAction) : $now;
            $hariOpen = (int) $tanggalAwal->diffInDays($tanggalAkhir);

            // Cari atau buat equipment
            if (!isset($preloadEq[$equipmentTag])) {
                $eq = CmEquipment::updateOrCreate(
                    ['equipment_tag' => $equipmentTag],
                    [
                        'pt_location' => $this->cellVal($idxPt !== null ? ($row[$idxPt] ?? null) : null) ?? 'Unknown',
                        'plant'       => $this->cellVal($idxPlant !== null ? ($row[$idxPlant] ?? null) : null) ?? 'Unknown',
                    ]
                );
                $preloadEq[$equipmentTag] = $eq->id;
            }
            $eqId = $preloadEq[$equipmentTag];

            // Cari cm_reading_id dari ID_CM_Parent
            $cmReadingId = null;
            if ($idxIdParent !== null) {
                $idParent = $this->cellVal($row[$idxIdParent] ?? null);
                if ($idParent && is_numeric($idParent)) {
                    $reading = CmReading::where('cm_equipment_id', $eqId)
                        ->where('data_no', (int) $idParent)
                        ->first();
                    if ($reading) {
                        $cmReadingId = $reading->id;
                    }
                }
            }

            $batchInsert[] = [
                'cm_equipment_id'  => $eqId,
                'cm_reading_id'    => $cmReadingId,
                'kode_finding'     => $kodeFinding,
                'severity'         => $severity ?? 'low',
                'kategori'         => $kategori,
                'deskripsi'        => $deskripsi ?: '-',
                'analysis'         => $analysis,
                'action'           => $action,
                'status'           => $status,
                'pic'              => $pic,
                'tanggal_temuan'   => $tanggalTemuan,
                'date_action'      => $dateAction,
                'foto_url'         => $fotoUrlFirst,
                'foto_urls'        => !empty($fotoUrls) ? json_encode($fotoUrls) : null,
                'hari_open'        => $hariOpen,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if (count($batchInsert) >= 500) {
                $this->flushFindings($batchInsert);
                $batchInsert = [];
            }
        }

        if (!empty($batchInsert)) {
            $this->flushFindings($batchInsert);
        }

        $this->line('  Findings: ' . $this->successFindings . ' berhasil, ' . $this->skippedFindings . ' dilewati.');
        unset($rows, $batchInsert);
    }

    private function flushFindings(array &$batch): void
    {
        foreach (array_chunk($batch, 200) as $chunk) {
            CmFinding::upsert(
                $chunk,
                ['kode_finding'],
                [
                    'cm_equipment_id', 'cm_reading_id',
                    'severity', 'kategori', 'deskripsi', 'analysis', 'action',
                    'status', 'pic', 'tanggal_temuan', 'date_action',
                    'foto_url', 'foto_urls', 'hari_open',
                ]
            );
            $this->successFindings += count($chunk);
        }
        $batch = [];
    }

    private function cellVal(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string) $v);
        return $s !== '' ? $s : null;
    }

    private function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v) || (is_string($v) && is_numeric($v))) {
            try {
                return date('Y-m-d', Date::excelToTimestamp((float) $v));
            } catch (\Exception) { return null; }
        }
        $s = trim((string) $v);
        if (empty($s)) return null;
        try { return Carbon::parse($s)->format('Y-m-d'); }
        catch (\Exception) { return null; }
    }
}
