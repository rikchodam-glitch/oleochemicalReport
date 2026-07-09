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
    const COLUMNS_SLIM = [
        'equipment'    => 0,
        'description'  => 1,
        'valid_to'     => 2,
        'planning_plant' => 3,
        'tech_ident_no'  => 4,
        'object_type'    => 5,
        'functional_loc' => 6,
    ];

    const COLUMNS_FULL = [
        'equipment'    => 0,
        'description'  => 1,
        'valid_to'     => 2,
        'planning_plant' => 3,
        'tech_ident_no'  => 4,
        'object_type'    => 5,
        'functional_loc' => 6,
        'construct_year' => 21,
        'company_code'   => 26,
        'manufacturer'   => 57,
        'model_number'   => 114,
    ];

    public function analyzeFile(UploadedFile $file): array
    {
        ini_set('memory_limit', '512M');

        $rows = $this->readExcel($file);
        if (empty($rows) || count($rows) < 2) {
            throw new \Exception('File Excel kosong atau tidak valid.');
        }

        $headers = $rows[0];
        $isFullFormat = count($headers) > 10;
        $colMap = $isFullFormat ? self::COLUMNS_FULL : self::COLUMNS_SLIM;

        $results = [
            'clean'       => [],
            'duplicate'   => [],
            'no_equip'    => [],
            'bad_funcloc' => [],
            'total_rows'  => count($rows) - 1,
            'is_full_format' => $isFullFormat,
        ];

        foreach (array_slice($rows, 1) as $index => $row) {
            $equipNo = trim($row[$colMap['equipment']] ?? '');

            if (empty($equipNo)) {
                $results['no_equip'][] = [
                    'row' => $index + 2,
                    'description' => $row[$colMap['description']] ?? '',
                    'functional_loc' => $row[$colMap['functional_loc']] ?? '',
                ];
                continue;
            }

            $isDuplicate = Asset::where('equipment_no', $equipNo)->exists();

            $funcLoc = trim($row[$colMap['functional_loc']] ?? '');
            $location = $this->parseFunctionalLoc($funcLoc);

            $rowData = [
                'equipment_no'   => $equipNo,
                'description'    => $row[$colMap['description']] ?? '',
                'tech_ident_no'  => $row[$colMap['tech_ident_no']] ?? '',
                'object_type'    => $row[$colMap['object_type']] ?? '',
                'functional_loc' => $funcLoc,
                'location'       => $location,
                'manufacturer'   => $isFullFormat ? ($row[$colMap['manufacturer']] ?? '') : '',
                'model_number'   => $isFullFormat ? ($row[$colMap['model_number']] ?? '') : '',
                'construct_year' => $isFullFormat ? ($row[$colMap['construct_year']] ?? '') : '',
            ];

            if ($isDuplicate) {
                $results['duplicate'][] = $rowData;
            } else {
                $results['clean'][] = $rowData;
            }
        }

        $results['funcloc_preview'] = $this->previewFuncLocs(array_merge(
            array_column($results['clean'], 'functional_loc'),
            array_column($results['duplicate'], 'functional_loc'),
            array_column($results['no_equip'], 'functional_loc'),
        ));

        return $results;
    }

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

    public function executeImport(array $analysis, array $choices): array
    {
        $duplicateAction = $choices['duplicate_action'] ?? 'skip';
        $noEquipAction = $choices['no_equip_action'] ?? 'skip';

        if ($noEquipAction === 'cancel') {
            return ['status' => 'cancelled', 'message' => 'Import dibatalkan.', 'success_count' => 0];
        }

        $successCount = 0;
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

        DB::transaction(function () use ($analysis, $duplicateAction, $noEquipAction, &$successCount, $importLogData) {
            // Process clean rows
            foreach ($analysis['clean'] as $row) {
                $location = $this->findOrCreateLocation($row['location']);
                $funcLocNode = $row['functional_loc'] !== '' ? $this->syncFuncLocFromRow($row['functional_loc']) : null;

                Asset::create(array_merge(
                    $this->mapToAsset($row),
                    $location,
                    [
                        'funcloc_id' => $funcLocNode?->id,
                        'status' => 'active',
                        'has_equipment_no' => true,
                        'data_source' => 'import_excel',
                        'imported_at' => now(),
                    ]
                ));
                $successCount++;
            }

            // Process duplicates
            if ($duplicateAction !== 'skip') {
                foreach ($analysis['duplicate'] as $row) {
                    $existing = Asset::where('equipment_no', $row['equipment_no'])->first();
                    if (!$existing) continue;

                    if ($duplicateAction === 'replace') {
                        $location = $this->findOrCreateLocation($row['location']);
                        $funcLocNode = $row['functional_loc'] !== '' ? $this->syncFuncLocFromRow($row['functional_loc']) : null;

                        $existing->update(array_merge(
                            $this->mapToAsset($row),
                            $location,
                            ['funcloc_id' => $funcLocNode?->id, 'imported_at' => now()]
                        ));
                        $successCount++;
                    } elseif ($duplicateAction === 'keep_flag') {
                        $existing->update(['status' => 'needs_review']);
                        $successCount++;
                    }
                }
            }

            // Process no equipment rows
            if ($noEquipAction === 'flag') {
                foreach ($analysis['no_equip'] as $row) {
                    $funcLocString = $row['functional_loc'] ?? '';
                    $location = $this->findOrCreateLocation($this->parseFunctionalLoc($funcLocString));
                    $funcLocNode = $funcLocString !== '' ? $this->syncFuncLocFromRow($funcLocString) : null;

                    Asset::create(array_merge(
                        [
                            'equipment_no' => null,
                            'description' => $row['description'] ?? '',
                            'functional_loc' => $funcLocString,
                        ],
                        $location,
                        [
                            'funcloc_id' => $funcLocNode?->id,
                            'status' => 'needs_review',
                            'has_equipment_no' => false,
                            'data_source' => 'import_excel',
                            'imported_at' => now(),
                        ]
                    ));
                    $successCount++;
                }
            }

            // Save import log
            $importLogData['success_count'] = $successCount;
            AssetImportLog::create($importLogData);
        });

        return [
            'status' => 'success',
            'message' => "Import berhasil. {$successCount} asset diproses.",
            'success_count' => $successCount,
        ];
    }

    private function readExcel(UploadedFile $file): array
    {
        ini_set('memory_limit', '512M');

        $reader = IOFactory::createReaderForFile($file->getPathname());
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Free memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Remove completely empty trailing rows
        while (!empty($rows) && empty(array_filter($rows[count($rows) - 1]))) {
            array_pop($rows);
        }

        return $rows;
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
        return [
            'equipment_no'   => $row['equipment_no'] ?? null,
            'description'    => $row['description'] ?? '',
            'tech_ident_no'  => $row['tech_ident_no'] ?? '',
            'object_type'    => $row['object_type'] ?? '',
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
