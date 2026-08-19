<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetImportLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    /**
     * Analisa file Excel dan kelompokkan tiap baris ke salah satu bucket:
     * clean (siap import), duplicate (equipment_no sudah ada), no_equip
     * (tanpa Equipment No.), atau bad_funcloc (Equipment No. ada tapi
     * Functional Loc. kosong sehingga tidak bisa ditautkan ke FuncLoc).
     *
     * @param  UploadedFile  $file
     * @return array
     */
    public function analyzeFile(UploadedFile $file): array
    {
        ini_set('memory_limit', '512M');

        $sheets = $this->readExcel($file);
        if (empty($sheets)) {
            throw new \Exception('File Excel kosong atau tidak valid.');
        }

        $results = [
            'clean'       => [],
            'duplicate'   => [],
            'no_equip'    => [],
            'bad_funcloc' => [],
            'total_rows'  => 0,
        ];

        foreach ($sheets as $sheet) {
            $rows = $sheet['rows'];
            if (count($rows) < 2) {
                continue;
            }

            $headers = $rows[0];
            $colMap  = $this->buildColMap($headers);

            // Lewati sheet yang bukan data asset (tidak punya kolom wajib).
            if (!isset($colMap['equipment'], $colMap['tech_ident_no'], $colMap['functional_loc'])) {
                continue;
            }

            $results['total_rows'] += count($rows) - 1;

            foreach (array_slice($rows, 1) as $index => $row) {
                $equipNo = $this->colVal($row, $colMap, 'equipment');
                $funcLoc = $this->colVal($row, $colMap, 'functional_loc');

                if (empty($equipNo)) {
                    $results['no_equip'][] = [
                        'row'            => $index + 2,
                        'description'    => $this->colVal($row, $colMap, 'description'),
                        'functional_loc' => $funcLoc,
                    ];
                    continue;
                }

                // funcloc_id wajib diisi (kolom assets.funcloc_id NOT NULL),
                // jadi baris dengan Functional Loc. kosong tidak bisa langsung
                // diimport. Baris ini harus ditambahkan manual lewat form
                // Create Asset setelah file diimport, atau file Excel-nya
                // dilengkapi dulu lalu diimport ulang.
                if (empty($funcLoc)) {
                    $results['bad_funcloc'][] = [
                        'row'          => $index + 2,
                        'equipment_no' => $equipNo,
                        'description'  => $this->colVal($row, $colMap, 'description'),
                    ];
                    continue;
                }

                $isDuplicate = Asset::where('equipment_no', $equipNo)->exists();
                $location    = $this->parseFunctionalLoc($funcLoc);

                $rowData = [
                    'equipment_no'   => $equipNo,
                    'description'    => $this->colVal($row, $colMap, 'description'),
                    'tech_ident_no'  => $this->colVal($row, $colMap, 'tech_ident_no'),
                    'object_type'    => $this->colVal($row, $colMap, 'object_type'),
                    'equipment_type' => $this->colVal($row, $colMap, 'equipment_type'),
                    'functional_loc' => $funcLoc,
                    'location'       => $location,
                    'manufacturer'   => $this->colVal($row, $colMap, 'manufacturer'),
                    'model_number'   => $this->colVal($row, $colMap, 'model_number'),
                    'construct_year' => $this->colVal($row, $colMap, 'construct_year'),
                ];

                if ($isDuplicate) {
                    $results['duplicate'][] = $rowData;
                } else {
                    $results['clean'][] = $rowData;
                }
            }
        }

        $results['funcloc_preview'] = $this->previewFuncLocs(array_merge(
            array_column($results['clean'], 'functional_loc'),
            array_column($results['duplicate'], 'functional_loc'),
        ));

        return $results;
    }

    /**
     * Pecah string "Functional Loc." mentah dari SAP menjadi kode
     * Company/Department/Area/SubArea lama, dipakai untuk kolom
     * company_id/department_id/area_id/sub_area_id yang masih dipertahankan
     * untuk kompatibilitas filter index.
     *
     * @param  string  $funcLoc
     * @return array
     */
    public function parseFunctionalLoc(string $funcLoc): array
    {
        if (empty($funcLoc)) {
            return ['company_code' => null, 'dept_code' => null, 'area_code' => null, 'subarea_code' => null];
        }

        $parts = explode('-', $funcLoc);
        return [
            'company_code' => $parts[0] ?? null,
            'dept_code'    => $parts[1] ?? null,
            'area_code'    => $parts[2] ?? null,
            'subarea_code' => $parts[3] ?? null,
        ];
    }

    /**
     * Eksekusi import berdasarkan hasil analyzeFile() dan pilihan aksi
     * admin. Setiap baris yang akan disimpan wajib berhasil ditautkan ke
     * node FuncLoc (funcloc_id) — baris yang gagal ditautkan dilewati dan
     * dihitung sebagai error, tidak pernah disimpan dengan funcloc_id
     * kosong, karena kolom tersebut NOT NULL di level database.
     *
     * @param  array  $analysis
     * @param  array  $choices
     * @return array
     */
    public function executeImport(array $analysis, array $choices): array
    {
        $duplicateAction = $choices['duplicate_action'] ?? 'skip';
        $noEquipAction = $choices['no_equip_action'] ?? 'skip';
        $badFuncLocAction = $choices['bad_funcloc_action'] ?? 'skip';

        if ($noEquipAction === 'cancel' || $badFuncLocAction === 'cancel') {
            return ['status' => 'cancelled', 'message' => 'Import dibatalkan.', 'success_count' => 0];
        }

        $successCount = 0;
        $errorCount = 0;
        $importLogData = [
            'filename' => $choices['filename'] ?? 'unknown.xlsx',
            'imported_by' => auth()->id(),
            'total_rows' => $analysis['total_rows'],
            'success_count' => 0,
            'duplicate_count' => count($analysis['duplicate']),
            'no_equip_no_count' => count($analysis['no_equip']),
            'error_count' => 0,
            'action_taken' => $duplicateAction,
            'detail_json' => $analysis,
        ];

        DB::transaction(function () use ($analysis, $duplicateAction, $noEquipAction, &$successCount, &$errorCount, $importLogData) {
            // Proses baris bersih
            foreach ($analysis['clean'] as $row) {
                $funcLocNode = $this->syncFuncLocFromRow($row['functional_loc']);

                if (!$funcLocNode) {
                    $errorCount++;
                    continue;
                }

                $location = $this->findOrCreateLocation($row['location']);

                Asset::create(array_merge(
                    $this->mapToAsset($row),
                    $location,
                    [
                        'funcloc_id' => $funcLocNode->id,
                        'status' => 'active',
                        'has_equipment_no' => true,
                        'data_source' => 'import_excel',
                        'imported_at' => now(),
                    ]
                ));
                $successCount++;
            }

            // Proses duplikat
            if ($duplicateAction !== 'skip') {
                foreach ($analysis['duplicate'] as $row) {
                    $existing = Asset::where('equipment_no', $row['equipment_no'])->first();
                    if (!$existing) continue;

                    if ($duplicateAction === 'replace') {
                        $funcLocNode = $this->syncFuncLocFromRow($row['functional_loc']);

                        if (!$funcLocNode) {
                            $errorCount++;
                            continue;
                        }

                        $location = $this->findOrCreateLocation($row['location']);

                        $updateData = array_merge(
                            $this->mapToAsset($row),
                            $location,
                            ['funcloc_id' => $funcLocNode->id, 'imported_at' => now()]
                        );

                        // Sheet tanpa kolom "Equipment Type" (mis. Sheet1 (1))
                        // menghasilkan equipment_type kosong. Jangan biarkan nilai
                        // kosong itu menimpa tipe yang sudah diisi dari sheet lain
                        // (Sheet1) — pertahankan nilai lama.
                        if (empty($row['equipment_type'] ?? '')) {
                            unset($updateData['equipment_type']);
                        }

                        $existing->update($updateData);
                        $successCount++;
                    } elseif ($duplicateAction === 'keep_flag') {
                        $existing->update(['status' => 'needs_review']);
                        $successCount++;
                    }
                }
            }

            // Proses baris tanpa Equipment No.
            if ($noEquipAction === 'flag') {
                foreach ($analysis['no_equip'] as $row) {
                    $funcLocString = $row['functional_loc'] ?? '';
                    $funcLocNode = $funcLocString !== '' ? $this->syncFuncLocFromRow($funcLocString) : null;

                    // Baris tanpa Equipment No. sekaligus tanpa Functional Loc.
                    // tidak bisa dibuat karena funcloc_id wajib diisi
                    if (!$funcLocNode) {
                        $errorCount++;
                        continue;
                    }

                    $location = $this->findOrCreateLocation($this->parseFunctionalLoc($funcLocString));

                    Asset::create(array_merge(
                        [
                            'equipment_no' => null,
                            'description' => $row['description'] ?? '',
                            'functional_loc' => $funcLocString,
                        ],
                        $location,
                        [
                            'funcloc_id' => $funcLocNode->id,
                            'status' => 'needs_review',
                            'has_equipment_no' => false,
                            'data_source' => 'import_excel',
                            'imported_at' => now(),
                        ]
                    ));
                    $successCount++;
                }
            }

            // Baris di bucket bad_funcloc (Equipment No. ada, Functional Loc.
            // kosong) sengaja tidak pernah dibuat menjadi Asset lewat jalur
            // import. Dihitung sebagai error agar tercatat di log, admin
            // menambahkannya manual lewat form Create Asset setelahnya.
            $errorCount += count($analysis['bad_funcloc']);

            // Simpan log import
            $importLogData['success_count'] = $successCount;
            $importLogData['error_count'] = $errorCount;
            AssetImportLog::create($importLogData);
        });

        $message = "Import berhasil. {$successCount} asset diproses.";
        if ($errorCount > 0) {
            $message .= " {$errorCount} baris dilewati karena tidak memiliki Functional Location yang valid.";
        }

        return [
            'status' => 'success',
            'message' => $message,
            'success_count' => $successCount,
            'error_count' => $errorCount,
        ];
    }

    /**
     * Bangun peta kolom berdasarkan NAMA header (bukan indeks tetap), supaya
     * format file yang berbeda (dengan/tanpa kolom "Equipment Type") tetap
     * terbaca benar.
     *
     * @param  array<int, mixed> $headers Baris header Excel
     * @return array<string, int>         Peta nama field -> indeks kolom
     */
    private function buildColMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            // Lowercase dulu, baru buang karakter non-huruf — urutan penting
            // supaya huruf kapital tidak ikut terhapus oleh regex [^a-z].
            $name = preg_replace('/[^a-z]/', '', strtolower((string) $header));

            switch ($name) {
                case 'equipment':     $map['equipment'] = $index; break;
                case 'description':   $map['description'] = $index; break;
                case 'validto':       $map['valid_to'] = $index; break;
                case 'planningplant': $map['planning_plant'] = $index; break;
                case 'techidentno':   $map['tech_ident_no'] = $index; break;
                case 'equipmenttype': $map['equipment_type'] = $index; break;
                case 'objecttype':    $map['object_type'] = $index; break;
                case 'functionalloc': $map['functional_loc'] = $index; break;
                case 'constructyear': $map['construct_year'] = $index; break;
                case 'companycode':   $map['company_code'] = $index; break;
                case 'manufacturer':  $map['manufacturer'] = $index; break;
                case 'modelnumber':   $map['model_number'] = $index; break;
            }
        }

        return $map;
    }

    /**
     * Ambil nilai sel dari satu baris Excel berdasarkan nama field, aman
     * untuk kolom yang tidak ada di format tertentu.
     *
     * @param  array<int, mixed>  $row    Baris data Excel
     * @param  array<string, int> $colMap Peta field -> indeks kolom
     * @param  string             $key    Nama field yang diambil
     * @return string
     */
    private function colVal(array $row, array $colMap, string $key): string
    {
        if (!isset($colMap[$key])) {
            return '';
        }

        return trim((string) ($row[$colMap[$key]] ?? ''));
    }

    /**
     * Baca seluruh sheet data dari file Excel, satu sheet per waktu untuk
     * menghemat memori (lihat Riwayat Insiden #5).
     *
     * @param  UploadedFile $file
     * @return array<int, array{name: string, rows: array}>
     */
    private function readExcel(UploadedFile $file): array
    {
        ini_set('memory_limit', '512M');

        $reader = IOFactory::createReaderForFile($file->getPathname());
        $reader->setReadDataOnly(true);

        $sheetNames = $reader->listWorksheetNames($file->getPathname());
        $sheets     = [];

        foreach ($sheetNames as $name) {
            $reader->setLoadSheetsOnly([$name]);
            $spreadsheet = $reader->load($file->getPathname());
            $worksheet   = $spreadsheet->getSheetByName($name);
            $rows        = $worksheet->toArray();

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles();

            // Buang baris kosong di akhir
            while (!empty($rows) && empty(array_filter($rows[count($rows) - 1]))) {
                array_pop($rows);
            }

            if (empty($rows)) {
                continue;
            }

            $sheets[] = ['name' => $name, 'rows' => $rows];
        }

        return $sheets;
    }

    private function findOrCreateLocation(array $loc): array
    {
        $company = $loc['company_code']
            ? Company::firstOrCreate(
                ['code' => $loc['company_code']],
                ['name' => $loc['company_code']]
              )
            : null;

        $dept = ($company && $loc['dept_code'])
            ? Department::firstOrCreate(
                ['company_id' => $company->id, 'code' => $loc['dept_code']],
                ['name' => $loc['dept_code']]
              )
            : null;

        $area = ($dept && $loc['area_code'])
            ? Area::firstOrCreate(
                ['department_id' => $dept->id, 'code' => $loc['area_code']],
                ['name' => $loc['area_code']]
              )
            : null;

        $subarea = ($area && $loc['subarea_code'])
            ? SubArea::firstOrCreate(
                ['area_id' => $area->id, 'code' => $loc['subarea_code']],
                ['name' => $loc['subarea_code']]
              )
            : null;

        return [
            'company_id'    => $company?->id,
            'department_id' => $dept?->id,
            'area_id'       => $area?->id,
            'sub_area_id'   => $subarea?->id,
        ];
    }

    private function mapToAsset(array $row): array
    {
        $equipmentType = $row['equipment_type'] ?? '';

        return [
            'equipment_no'   => $row['equipment_no'] ?? null,
            'description'    => $row['description'] ?? '',
            'tech_ident_no'  => $row['tech_ident_no'] ?? '',
            'object_type'    => $row['object_type'] ?? '',
            'equipment_type' => $equipmentType !== '' ? $equipmentType : null,
            'functional_loc' => $row['functional_loc'] ?? '',
            'manufacturer'   => $row['manufacturer'] ?? '',
            'model_number'   => $row['model_number'] ?? '',
            'construct_year' => $row['construct_year'] ?? '',
        ];
    }

    /**
     * Susun preview FuncLoc dari kumpulan string "Functional Loc." mentah,
     * tanpa membuat record apapun. Dipakai di halaman preview import agar
     * admin bisa melihat node mana yang baru dan mana yang sudah ada
     * sebelum menekan tombol import.
     *
     * @param  array<int, string>  $funcLocPaths
     * @return array{nodes: array, new_count: int, existing_count: int}
     */
    public function previewFuncLocs(array $funcLocPaths): array
    {
        $codeLevels = collect();

        foreach ($funcLocPaths as $path) {
            $segments = $this->splitFuncLocSegments((string) $path);
            $code = null;

            foreach ($segments as $level => $segment) {
                $code = $code !== null ? $code . '-' . $segment : $segment;
                $codeLevels->put($code, $level);
            }
        }

        if ($codeLevels->isEmpty()) {
            return ['nodes' => [], 'new_count' => 0, 'existing_count' => 0];
        }

        $existingCodes = FunctionalLocation::whereIn('code', $codeLevels->keys())
            ->pluck('code')
            ->flip();

        $nodes = $codeLevels
            ->map(fn ($level, $code) => [
                'code'   => $code,
                'level'  => $level,
                'exists' => $existingCodes->has($code),
            ])
            ->values()
            ->sortBy('code')
            ->values()
            ->all();

        return [
            'nodes'          => $nodes,
            'new_count'      => collect($nodes)->where('exists', false)->count(),
            'existing_count' => collect($nodes)->where('exists', true)->count(),
        ];
    }

    /**
     * Buat semua node FuncLoc (termasuk parent) dari satu baris "Functional
     * Loc." Excel jika belum ada, lalu kembalikan node paling dalam (yang
     * cocok dengan path penuh) untuk di-assign sebagai funcloc_id asset.
     *
     * Nama node diisi sama dengan kode karena Excel ZPM tidak menyediakan
     * deskripsi per level hierarki. Admin bisa memperbaiki nama tersebut
     * belakangan lewat halaman admin Functional Location.
     *
     * @param  string  $funcLoc
     * @return FunctionalLocation|null
     */
    public function syncFuncLocFromRow(string $funcLoc): ?FunctionalLocation
    {
        $segments = $this->splitFuncLocSegments($funcLoc);

        if (empty($segments)) {
            return null;
        }

        $parent = null;
        $node = null;

        foreach ($segments as $level => $segment) {
            $code = $parent !== null ? $parent->code . '-' . $segment : $segment;

            $node = FunctionalLocation::firstOrCreate(
                ['code' => $code],
                [
                    'segment'   => $segment,
                    'name'      => $code,
                    'level'     => $level,
                    'parent_id' => $parent?->id,
                    'is_active' => true,
                ]
            );

            $parent = $node;
        }

        return $node;
    }

    /**
     * Pecah string "Functional Loc." mentah menjadi segment per level.
     * Sengaja tidak melakukan uppercase supaya kode yang dihasilkan persis
     * sama dengan kode Company/Department/Area/SubArea yang dibuat oleh
     * findOrCreateLocation() dari string yang sama — mencegah node duplikat
     * akibat perbedaan kapitalisasi.
     *
     * @param  string  $funcLoc
     * @return array<int, string>
     */
    private function splitFuncLocSegments(string $funcLoc): array
    {
        $funcLoc = trim($funcLoc);

        if ($funcLoc === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode('-', $funcLoc)),
            fn ($segment) => $segment !== ''
        ));
    }
}
