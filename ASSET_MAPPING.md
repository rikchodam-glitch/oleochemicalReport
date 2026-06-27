# ASSET_MAPPING.md — Pemetaan Data ZPM Excel ke Database

Dokumen ini adalah **referensi wajib** untuk ImportService.
Dibuat dari analisa langsung file `ZPM_line_1__1_.xlsx` (2639 rows) dan `ZPM_line_2__2_.xlsx` (728 rows).
**Total: 3.367 equipment** dari 2 file.

---

## Perbedaan Format Dua File

| Aspek | ZPM Line 1 (`ZPM_line_1__1_.xlsx`) | ZPM Line 2 (`ZPM_line_2__2_.xlsx`) |
|---|---|---|
| Jumlah kolom | 120 kolom (full SAP export) | 7 kolom (slim export) |
| Jumlah rows | 2.639 | 728 |
| Kolom unik | Manufacturer, Model number, ConstructYear, Company Code, dll | Tidak ada |
| Functional Loc pattern | 3-part & 4-part | 4-part semua |
| Baris tanpa Equipment No | 0 | 0 |
| Sheet aktif | Sheet1 (Sheet2 & Sheet3 kosong) | Sheet1 |

### Mapping Indeks Kolom ZPM Line 1 (120 kolom)
```
[0]  Equipment
[1]  Description
[2]  Valid To
[3]  Planning Plant
[4]  TechIdentNo.
[5]  Object Type
[6]  Functional Loc.
[21] ConstructYear
[26] Company Code        → 'F007' untuk semua (= EPE internal code)
[57] Manufacturer
[114] Model number
```

### Mapping Indeks Kolom ZPM Line 2 (7 kolom)
```
[0]  Equipment
[1]  Description
[2]  Valid To
[3]  Planning Plant
[4]  TechIdentNo.
[5]  Object Type
[6]  Functional Loc.
```

---

## Parsing Functional Location

Format: `COMPANY-DEPT-AREA-SUBAREA`

```php
// Contoh parsing
$parts = explode('-', 'EPE-PROD-BD02-6153');
// $parts[0] = 'EPE'   → companies.code
// $parts[1] = 'PROD'  → departments.code
// $parts[2] = 'BD02'  → areas.code
// $parts[3] = '6153'  → sub_areas.code

// Kasus 3-part (tanpa SubArea): 'EPE-LOGE-ST01'
// $parts[0] = 'EPE', $parts[1] = 'LOGE', $parts[2] = 'ST01'
// sub_area_id = NULL
```

**PENTING:** Beberapa equipment punya Functional Loc. yang tidak mengandung kode area standar
(contoh: `EPE-HRGA-UNKNOWN`). Ini terjadi karena kolom Functional Loc. berisi string non-standar
atau diisi dengan dept saja. Tandai sebagai `sub_area_id = null`.

---

## Hierarki Lengkap Perusahaan & Area

### Company: EPE (Ecogreen Oleochemicals)

```
EPE
├── PROD (Production)
│   ├── TF01 — Tank Farm Line 1
│   │   └── 6010 (426 equipment) — Tangki penyimpanan, AC panel, hydrant
│   │
│   ├── BD01 — Biodiesel Line 1
│   │   ├── [tanpa subarea] (135 equipment) — Panel, fire extinguisher, dll
│   │   ├── 6153 (42 equipment)  — Oil pre-treatment & decanter
│   │   ├── 6160 (89 equipment)  — Methanol section (pump, AC, level switch)
│   │   ├── 6163 (315 equipment) — Reaktor & methylester section (terbesar)
│   │   ├── 6166 (136 equipment) — Glycerine separation & soap splitting
│   │   └── 6600 (31 equipment)  — Water dosing & citric acid system
│   │
│   ├── BD02 — Biodiesel Line 2
│   │   ├── 6153 (14 equipment)  — Oil pre-neutralization & pump
│   │   ├── 6160 (56 equipment)  — Methanol feeding & reboiler
│   │   ├── 6163 (245 equipment) — Reaktor & recirculation (terbesar BD2)
│   │   ├── 6166 (90 equipment)  — Glycerine separation BD2
│   │   └── 6600 (25 equipment)  — Water & citric dosing BD2
│   │
│   ├── RG01 — Refinery & Glycerine Line 1
│   │   ├── [tanpa subarea] (105 equipment)
│   │   ├── 6050 (6 equipment)   — Filter bag filling RG
│   │   ├── 6400 (297 equipment) — Filling section (terbesar RG1)
│   │   └── 6920 (12 equipment)  — Thermostatized water system
│   │
│   ├── RG02 — Refinery & Glycerine Line 2
│   │   ├── 6400 (210 equipment) — Pitch pump, transfer pump motor
│   │   └── 6920 (9 equipment)   — Thermostatized water RG2
│   │
│   ├── MD01 — Methylester Distillation
│   │   ├── [tanpa subarea] (70 equipment)
│   │   ├── 6540 (223 equipment) — Distillation tower, kolom, kondenser
│   │   └── 9640 (19 equipment)  — Thermal oil heater & burner
│   │
│   ├── EN01 — Enzymatic Biodiesel
│   │   └── 6170 (43 equipment)  — Reaktor enzymatic, heat exchanger
│   │
│   └── UT01 — Utility
│       ├── [tanpa subarea] (3 equipment)
│       └── 6020 (254 equipment) — Cooling tower, kompresor, boiler
│
├── LOGE (Logistics)
│   ├── ST01 — Storage (3 equipment)
│   ├── UL01 — Unloading (59 equipment)
│   ├── UL02 — Loading Bay
│   │   ├── [tanpa subarea] (11 equipment)
│   │   └── 6040 (14 equipment)  — Flowmeter & filter loading bay
│   ├── WB01 — Weighbridge (19 equipment)
│   └── WH02 — Warehouse (2 equipment)
│
├── MTCE (Maintenance)
│   ├── MT01 — Workshop (6 equipment)
│   ├── MT02 — M&E Office (17 equipment)
│   └── MT03 — Utility Maintenance (32 equipment)
│
├── HRGA (HR & General Affairs)
│   ├── GA01 — Office (48 equipment) — AC, printer, PC
│   ├── GA02 — Canteen & Mosque (46 equipment)
│   └── GA03 — Security Post (31 equipment)
│
└── QCRD (Quality Control & R&D)
    └── QC01 — Lab (79 equipment)
```

---

## Daftar Lengkap Object Type & Artinya

| Kode | Jumlah | Jenis Equipment | Contoh |
|---|---|---|---|
| ZPM-LAM | 282 | Lampu / Lighting | Lampu area produksi |
| ZPM-PGG | 241 | Pressure Gauge / Manometer | PI-6153F1-1 |
| ZPM-MOT | 237 | Motor listrik | Motor Neutralized Oil Pump |
| ZPM-TTM | 189 | Temperature Transmitter | TT sensor reaktor |
| ZPM-LSW | 185 | Level Switch | LS tangki methanol |
| ZPM-PU1 | 174 | Pompa centrifugal | Neutralized Oil Pump |
| ZPM-CVL | 170 | Control Valve | FCV, TCV, LCV |
| ZPM-LCS | 154 | Local Control Station | Panel motor lokal |
| ZPM-ACE | 129 | AC / Air Conditioner | AC panel room |
| ZPM-FTM | 128 | Flow Transmitter/Meter | FT reactor |
| ZPM-LTM | 123 | Level Transmitter | LT tangki |
| ZPM-HDT | 97 | Hand Detector / Sensor | Sensor kebocoran |
| ZPM-TGG | 84 | Thermocouple / TG | TG temperatur |
| ZPM-PTM | 80 | Pressure Transmitter | PT pipa |
| ZPM-SDT | 78 | Speed Detector | SD agitator |
| ZPM-TNK | 73 | Tangki / Vessel kecil | Storage tank |
| ZPM-VES | 70 | Vessel / Bejana | Accumulator |
| ZPM-SVL | 66 | Solenoid Valve | Valve otomatis |
| ZPM-FEX | 57 | Fire Extinguisher | APAR |
| ZPM-PHE | 50 | Plate Heat Exchanger | Cooler |
| ZPM-COM | 44 | Komputer / PC | Personal Computer |
| ZPM-ELP | 41 | Electrical Panel | MCC, Panel LP |
| ZPM-PSV | 39 | Pressure Safety Valve | PRV pipa |
| ZPM-RAD | 35 | Radar Level | Level radar tangki |
| ZPM-BAV | 34 | Ball/Butterfly Actuator Valve | |
| ZPM-HEX | 31 | Heat Exchanger | Shell & tube |
| ZPM-GRD | 28 | Grounding | Pentanahan |
| ZPM-PU5 | 24 | Pompa dosing | Citric acid pump |
| ZPM-CTV | 23 | Check/Triple Valve | |
| ZPM-MXA | 23 | Mixer / Agitator | |
| ZPM-FHP | 21 | Fire Hydrant Pillar | Pilar hydrant |
| ZPM-MCP | 19 | Motor Control Panel | |
| ZPM-COL | 18 | Kolom distilasi | Distillation tower |
| ZPM-BDT | 16 | Beam Detector | Detektor kebakaran |
| ZPM-SPR | 16 | Sprinkler | Fire sprinkler |
| ZPM-AIT | 15 | Analyzer / pH Meter | pH meter |
| ZPM-FBH | 15 | Filter/Bag Housing | Filter bag |
| ZPM-DLG | 2 | Deluge System | Fire suppression |
| ZPM-BUR | 1 | Burner | Thermal oil burner |
| ... | ... | (total 80 object types) | |

---

## Logika ImportService — Pseudocode Lengkap

```php
class ImportService
{
    /**
     * Kolom yang diproses dari Excel (berlaku untuk KEDUA file)
     * Index berdasarkan posisi kolom (0-based), bukan nama header
     */
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
        // semua SLIM +
        'construct_year' => 21,
        'company_code'   => 26,   // 'F007' → akan diabaikan, gunakan parsing FuncLoc
        'manufacturer'   => 57,
        'model_number'   => 114,
    ];

    public function analyzeFile(UploadedFile $file): ImportAnalysisResult
    {
        $rows = $this->readExcel($file);
        $headers = $rows[0];
        $isFullFormat = count($headers) > 10; // ZPM1 = 120 col, ZPM2 = 7 col
        $colMap = $isFullFormat ? self::COLUMNS_FULL : self::COLUMNS_SLIM;

        $results = [
            'clean'      => [],  // siap import
            'duplicate'  => [],  // equipment_no sudah ada di DB
            'no_equip'   => [],  // kolom Equipment kosong
            'bad_funcloc'=> [],  // Functional Loc. tidak bisa diparsing
        ];

        foreach (array_slice($rows, 1) as $index => $row) {
            $equipNo = trim($row[$colMap['equipment']] ?? '');

            // 1. Cek equipment no kosong
            if (empty($equipNo)) {
                $results['no_equip'][] = [
                    'row' => $index + 2,
                    'description' => $row[$colMap['description']] ?? '',
                    'functional_loc' => $row[$colMap['functional_loc']] ?? '',
                ];
                continue;
            }

            // 2. Cek duplicate di database
            $isDuplicate = Asset::where('equipment_no', $equipNo)->exists();

            // 3. Parse Functional Location
            $funcLoc = trim($row[$colMap['functional_loc']] ?? '');
            $location = $this->parseFunctionalLoc($funcLoc);

            $rowData = [
                'equipment_no'   => $equipNo,
                'description'    => $row[$colMap['description']] ?? '',
                'tech_ident_no'  => $row[$colMap['tech_ident_no']] ?? '',
                'object_type'    => $row[$colMap['object_type']] ?? '',
                'functional_loc' => $funcLoc,
                'location'       => $location,
                // kolom ekstra hanya jika format full
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

        return new ImportAnalysisResult($results, $isFullFormat);
    }

    /**
     * Parse "EPE-PROD-BD02-6153" menjadi lokasi terstruktur
     */
    public function parseFunctionalLoc(string $funcLoc): array
    {
        if (empty($funcLoc)) {
            return ['company' => null, 'dept' => null, 'area' => null, 'subarea' => null];
        }

        $parts = explode('-', $funcLoc);
        return [
            'company_code' => $parts[0] ?? null,   // EPE
            'dept_code'    => $parts[1] ?? null,    // PROD
            'area_code'    => $parts[2] ?? null,    // BD02
            'subarea_code' => $parts[3] ?? null,    // 6153 (null jika hanya 3 part)
        ];
    }

    /**
     * Eksekusi import setelah user konfirmasi pilihan aksi
     * 
     * $choices = [
     *   'duplicate_action' => 'replace' | 'skip' | 'keep_flag',
     *   'no_equip_action'  => 'flag' | 'skip' | 'cancel',
     * ]
     */
    public function executeImport(ImportAnalysisResult $analysis, array $choices): ImportResult
    {
        if ($choices['no_equip_action'] === 'cancel') {
            return ImportResult::cancelled();
        }

        DB::transaction(function () use ($analysis, $choices) {
            // Proses baris bersih
            foreach ($analysis->clean as $row) {
                $location = $this->findOrCreateLocation($row['location']);
                Asset::create([
                    ...$this->mapToAsset($row),
                    ...$location,
                    'status'         => AssetStatus::Active,
                    'has_equipment_no' => true,
                    'data_source'    => 'import_excel',
                ]);
            }

            // Proses duplicate sesuai pilihan
            if ($choices['duplicate_action'] !== 'skip') {
                foreach ($analysis->duplicate as $row) {
                    $existing = Asset::where('equipment_no', $row['equipment_no'])->first();
                    if ($choices['duplicate_action'] === 'replace') {
                        $existing->update($this->mapToAsset($row));
                    } elseif ($choices['duplicate_action'] === 'keep_flag') {
                        $existing->update(['status' => AssetStatus::NeedsReview]);
                    }
                }
            }

            // Proses tanpa equipment no sesuai pilihan
            if ($choices['no_equip_action'] === 'flag') {
                foreach ($analysis->no_equip as $row) {
                    $location = $this->findOrCreateLocation($row['location'] ?? []);
                    Asset::create([
                        ...$this->mapToAsset($row),
                        ...$location,
                        'status'           => AssetStatus::NeedsReview,
                        'has_equipment_no' => false,
                        'data_source'      => 'import_excel',
                    ]);
                }
            }
        });
    }

    /**
     * Cari atau buat record company/dept/area/subarea
     * Gunakan firstOrCreate agar tidak duplicate
     */
    private function findOrCreateLocation(array $loc): array
    {
        $company = $loc['company_code']
            ? Company::firstOrCreate(['code' => $loc['company_code']], ['name' => $loc['company_code']])
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
            'company_id'  => $company?->id,
            'department_id' => $dept?->id,
            'area_id'     => $area?->id,
            'sub_area_id' => $subarea?->id,
        ];
    }
}
```

---

## Data Seeder Referensi (dari ZPM nyata)

Gunakan data ini untuk `DatabaseSeeder` agar hierarki lokasi sudah tersedia sebelum import:

```php
// Company
['code' => 'EPE', 'name' => 'Ecogreen Oleochemicals'],

// Departments EPE
['code' => 'PROD', 'name' => 'Production'],
['code' => 'LOGE', 'name' => 'Logistics'],
['code' => 'MTCE', 'name' => 'Maintenance'],
['code' => 'HRGA', 'name' => 'HR & General Affairs'],
['code' => 'QCRD', 'name' => 'Quality Control & R&D'],

// Areas PROD
['code' => 'TF01', 'name' => 'Tank Farm Line 1'],
['code' => 'BD01', 'name' => 'Biodiesel Line 1'],
['code' => 'BD02', 'name' => 'Biodiesel Line 2'],
['code' => 'RG01', 'name' => 'Refinery & Glycerine Line 1'],
['code' => 'RG02', 'name' => 'Refinery & Glycerine Line 2'],
['code' => 'MD01', 'name' => 'Methylester Distillation'],
['code' => 'EN01', 'name' => 'Enzymatic Biodiesel'],
['code' => 'UT01', 'name' => 'Utility'],

// SubAreas (kode 4-digit = nomor sistem SAP)
['code' => '6010', 'name' => 'Storage & Pump Area TF'],
['code' => '6153', 'name' => 'Oil Pre-treatment'],
['code' => '6160', 'name' => 'Methanol Section'],
['code' => '6163', 'name' => 'Reaction & Separation'],
['code' => '6166', 'name' => 'Glycerine Separation'],
['code' => '6600', 'name' => 'Water & Chemical Dosing'],
['code' => '6400', 'name' => 'Filling & Refinery Section'],
['code' => '6050', 'name' => 'Filter Bag Area'],
['code' => '6920', 'name' => 'Thermostatized Water System'],
['code' => '6540', 'name' => 'Distillation Section'],
['code' => '9640', 'name' => 'Thermal Oil System'],
['code' => '6170', 'name' => 'Enzymatic Reactor'],
['code' => '6020', 'name' => 'Cooling & Utility'],
['code' => '6040', 'name' => 'Loading Bay'],
```

---

## Object Type → Warna Badge & Label UI

```php
// app/Helpers/ObjectTypeHelper.php
const OBJECT_TYPE_MAP = [
    'ZPM-PU1' => ['label' => 'Pompa',           'color' => 'blue'],
    'ZPM-PU5' => ['label' => 'Pompa Dosing',    'color' => 'blue'],
    'ZPM-MOT' => ['label' => 'Motor',           'color' => 'indigo'],
    'ZPM-ACE' => ['label' => 'AC/Elektrikal',   'color' => 'violet'],
    'ZPM-PHE' => ['label' => 'Heat Exchanger',  'color' => 'orange'],
    'ZPM-HEX' => ['label' => 'Heat Exchanger',  'color' => 'orange'],
    'ZPM-VES' => ['label' => 'Vessel',          'color' => 'teal'],
    'ZPM-TNK' => ['label' => 'Tangki',          'color' => 'teal'],
    'ZPM-CVL' => ['label' => 'Control Valve',   'color' => 'cyan'],
    'ZPM-SVL' => ['label' => 'Solenoid Valve',  'color' => 'cyan'],
    'ZPM-MXA' => ['label' => 'Mixer/Agitator',  'color' => 'emerald'],
    'ZPM-COL' => ['label' => 'Kolom Distilasi', 'color' => 'rose'],
    'ZPM-FEX' => ['label' => 'APAR',            'color' => 'red'],
    'ZPM-FHP' => ['label' => 'Fire Hydrant',    'color' => 'red'],
    'ZPM-COM' => ['label' => 'Komputer',        'color' => 'slate'],
    'ZPM-ELP' => ['label' => 'Panel Listrik',   'color' => 'yellow'],
    'ZPM-LAM' => ['label' => 'Lampu',           'color' => 'yellow'],
    'ZPM-LCS' => ['label' => 'LCS',            'color' => 'gray'],
    // default
    '_default' => ['label' => 'Equipment',      'color' => 'slate'],
];
```

---

## Catatan Khusus Import

1. **`Company Code` kolom 26 ZPM1** berisi `F007` (bukan `EPE`). Abaikan kolom ini — gunakan parsing Functional Loc untuk menentukan company.

2. **`Valid To = 99991231`** artinya equipment masih aktif (tanggal 31 Desember 9999 = tidak ada tanggal berakhir). Set `status = active`.

3. **Functional Loc. 3-part** (contoh `EPE-LOGE-ST01`) → `sub_area_id = null`, `area_id` diisi dari part ke-3.

4. **Equipment dengan Functional Loc. tidak dikenali** (contoh `EPE-HRGA-UNKNOWN`, atau 2 equipment tanpa FuncLoc di UNKNOWN-UNKNOWN-UNKNOWN) → simpan dengan semua location = null, `status = needs_review`.

5. **`TechIdentNo.`** adalah ID teknis yang dipakai teknisi di lapangan (contoh: `2-6153P1`, `AC-TF-1-1`). Ini yang paling sering disebut teknisi dalam laporan — jadikan field pencarian utama di AI analisa.

6. **ZPM Line 2** prefix TechIdentNo. dengan `2-` (contoh `2-6153P1`) menandakan Line 2. ZPM Line 1 tidak ada prefix ini.
