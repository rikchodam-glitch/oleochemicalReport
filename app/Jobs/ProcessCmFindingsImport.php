<?php

namespace App\Jobs;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use App\Models\ImportLog;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ProcessCmFindingsImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected string $filePath;
    protected int $importLogId;
    protected int $uploadedBy;

    /**
     * Instance ImportLog untuk update progress live.
     */
    protected ?ImportLog $log = null;

    public function __construct(string $filePath, int $importLogId, int $uploadedBy)
    {
        $this->filePath    = $filePath;
        $this->importLogId = $importLogId;
        $this->uploadedBy  = $uploadedBy;
    }

    public function handle(): void
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $log = ImportLog::findOrFail($this->importLogId);
        $log->update(['status' => 'processing']);
        $this->log = $log;

        $result = [
            'total_dibaca'          => 0,
            'total_baris'           => 0,
            'insert_baru'           => 0,
            'update_existing'       => 0,
            'gagal'                 => 0,
            'unregistered'          => 0,
            'total_skipped_duplikat' => 0,
            'total_skipped_kosong'   => 0,
            'unregistered_tags'     => [],
            'errors'                => [],
            'skipped'               => [],
            'created_equipment_ids' => [],
            'created_finding_ids'   => [],
            'last_sync'             => 0,
        ];

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($this->filePath);

            $sheet = $spreadsheet->getSheetByName('Finding CM');
            if (!$sheet) {
                throw new \Exception('Sheet "Finding CM" tidak ditemukan.');
            }

            $result = $this->processFindingCm($sheet, $result);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles();

            $result['total_baris'] = $result['total_diproses'] ?? $result['insert_baru'] + $result['update_existing'] + $result['gagal'] + $result['total_skipped_duplikat'] + $result['total_skipped_kosong'] + $result['unregistered'];

            $log->update([
                'status'                 => 'completed',
                'total_baris'            => $result['total_baris'],
                'total_dibaca'           => $result['total_dibaca'],
                'insert_baru'            => $result['insert_baru'],
                'update_existing'        => $result['update_existing'],
                'gagal'                  => $result['gagal'],
                'unregistered'           => $result['unregistered'],
                'total_skipped_duplikat' => $result['total_skipped_duplikat'],
                'total_skipped_kosong'   => $result['total_skipped_kosong'],
                'detail_unregistered'    => array_values(array_unique($result['unregistered_tags'])),
                'detail_error'           => $result['errors'],
                'detail_skipped'         => $result['skipped'],
                'created_equipment_ids'  => array_values(array_unique($result['created_equipment_ids'])),
                'created_finding_ids'    => array_values(array_unique($result['created_finding_ids'])),
                'completed_at'           => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessCmFindingsImport gagal', [
                'import_log_id' => $this->importLogId,
                'error'         => $e->getMessage(),
                'file'          => $e->getFile() . ':' . $e->getLine(),
            ]);
            $log->update([
                'status'       => 'failed',
                'detail_error' => [['baris' => 0, 'pesan' => 'Error: ' . $e->getMessage()]],
                'completed_at' => now(),
            ]);
        }

        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    private function processFindingCm(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $result): array
    {
        $rows = $this->readSheetRows($sheet);
        $headers = $rows[0] ?? [];
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $headerMap[trim((string) $h)] = $i;
        }

        $required = ['No', 'Equipment Tag', 'Date Found'];
        foreach ($required as $r) {
            if (!isset($headerMap[$r])) {
                throw new \Exception("Kolom '{$r}' tidak ditemukan di Finding CM.");
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
        $now = now();

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $result['total_dibaca']++;

            $kodeFinding = $this->cellVal($row[$idxNo] ?? null);
            if (empty($kodeFinding)) {
                $result['total_skipped_kosong']++;
                $result['total_diproses'] = ($result['total_diproses'] ?? 0) + 1;
                $result['skipped'][] = [
                    'baris'    => $r + 1,
                    'sheet'    => 'Finding CM',
                    'kategori' => 'skip_kosong',
                    'pesan'    => 'No (kode finding) kosong',
                ];
                continue;
            }
            $kodeFinding = trim((string) $kodeFinding);

            $equipmentTag = trim((string) ($row[$idxTag] ?? ''));
            if (empty($equipmentTag)) {
                $result['total_skipped_kosong']++;
                $result['total_diproses'] = ($result['total_diproses'] ?? 0) + 1;
                $result['skipped'][] = [
                    'baris'    => $r + 1,
                    'sheet'    => 'Finding CM',
                    'kategori' => 'skip_kosong',
                    'pesan'    => 'Equipment Tag kosong, No=' . $kodeFinding,
                ];
                continue;
            }

            $tanggalRaw = $row[$idxDateFound] ?? null;
            $tanggalTemuan = $this->parseDate($tanggalRaw);
            if (!$tanggalTemuan) {
                $result['total_skipped_kosong']++;
                $result['total_diproses'] = ($result['total_diproses'] ?? 0) + 1;
                $result['skipped'][] = [
                    'baris'    => $r + 1,
                    'sheet'    => 'Finding CM',
                    'kategori' => 'skip_kosong',
                    'pesan'    => 'Date Found invalid, No=' . $kodeFinding,
                ];
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

            if ($idxRemark !== null) {
                $remark = $this->cellVal($row[$idxRemark] ?? null);
                if ($remark) $deskripsi .= ($deskripsi ? "\n\n" : '') . 'Remark: ' . $remark;
            }

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

            // Cari equipment. Equipment CM TIDAK dibuat otomatis dari data finding:
            // finding untuk tag yang belum terdaftar di equipment CM dicatat
            // sebagai unregistered (bukan membuat equipment baru yang bisa
            // mencemari daftar CM dengan equipment tanpa data reading).
            if (!isset($preloadEq[$equipmentTag])) {
                $result['unregistered_tags'][] = $equipmentTag;
                $result['unregistered'] = ($result['unregistered'] ?? 0) + 1;
                $result['total_diproses'] = ($result['total_diproses'] ?? 0) + 1;
                continue;
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

            $data = [
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
                'foto_urls'        => !empty($fotoUrls) ? $fotoUrls : null,
                'hari_open'        => $hariOpen,
            ];

            $existing = CmFinding::where('kode_finding', $kodeFinding)->first();
            if ($existing) {
                $existing->update($data);
                $result['update_existing']++;
            } else {
                $created = CmFinding::create($data);
                $result['created_finding_ids'][] = $created->id;
                $result['insert_baru']++;
            }

            $result['total_diproses'] = ($result['total_diproses'] ?? 0) + 1;
            $this->syncProgress($result);
        }

        return $result;
    }

    /**
     * Update progress ke database secara berkala (max ~2x per detik).
     */
    private function syncProgress(array &$result): void
    {
        if (!$this->log) {
            return;
        }

        $now = microtime(true);
        $lastSync = $result['last_sync'] ?? 0;

        if (($now - $lastSync) < 0.5) {
            return;
        }

        $this->log->update([
            'total_baris'            => $result['total_diproses'] ?? 0,
            'total_dibaca'           => $result['total_dibaca'] ?? 0,
            'insert_baru'            => $result['insert_baru'],
            'update_existing'        => $result['update_existing'],
            'gagal'                  => $result['gagal'],
            'unregistered'           => $result['unregistered'],
            'total_skipped_duplikat' => $result['total_skipped_duplikat'],
            'total_skipped_kosong'   => $result['total_skipped_kosong'],
            'status'                 => 'processing',
        ]);

        $result['last_sync'] = $now;
    }

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