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
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    /**
     * Kolom deskriptif yang TIDAK boleh dikosongkan oleh isi file import:
     * sel kosong di file (mis. file tanpa kolom Manufacturer/Model/Tahun)
     * tidak menimpa nilai yang sudah ada di database.
     *
     * @var array<int, string>
     */
    private const KOLOM_TIDAK_BOLEH_KOSONG = [
        'description',
        'manufacturer',
        'model_number',
        'construct_year',
    ];

    /**
     * @var AssetNumberService Pembuat nomor equipment otomatis untuk baris
     *                         import yang tidak menyertakan Equipment No
     */
    protected AssetNumberService $assetNumbers;

    /**
     * @param  AssetNumberService  $assetNumbers
     */
    public function __construct(AssetNumberService $assetNumbers)
    {
        $this->assetNumbers = $assetNumbers;
    }

    /**
     * Analisa file Excel dan kelompokkan tiap baris ke salah satu bucket.
     *
     * KUNCI Pencocokan: Equipment No lebih dulu (nomor aset yang ikut diexport
     * dan berindeks unik), baru Tech Ident No (Kode Alat) sebagai cadangan untuk
     * file yang tidak memuat kolom Equipment No atau memuat nomor yang belum ada
     * di database. Dengan urutan ini, Kode Alat pada file hasil export boleh
     * diubah tanpa membuat aset baru.
     *
     * Bucket:
     *   - clean       : tidak cocok nomor maupun Kode Alat -> aset BARU (nomor
     *                   equipment dibuat sistem lewat AssetNumberService)
     *   - duplicate   : cocok tepat satu aset (lewat nomor atau Kode Alat) ->
     *                   akan DIPERBARUI (plus daftar field yang berubah di key
     *                   'perubahan' dan penanda 'dicocokkan_lewat')
     *   - no_equip    : Kode Alat kosong -> tidak ada identitas yang bisa
     *                   dicocokkan (tetap seperti sebelumnya, supaya nilai Kode
     *                   Alat yang sudah ada tidak terhapus oleh sel kosong)
     *   - bad_funcloc : Functional Loc. kosong -> tidak bisa ditautkan ke FuncLoc
     *   - ambigu      : identitas dipakai lebih dari sekali (di dalam file
     *                   maupun di database), termasuk Kode Alat baru yang sudah
     *                   dipakai aset lain
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
            'ambigu'      => [],
            'it_sync'     => [],
            'total_rows'  => 0,
        ];

        // Tahap 1: kumpulkan seluruh baris data dari semua sheet.
        $semuaBaris = $this->kumpulkanBaris($sheets, $results);

        // Hitung kemunculan Tech Ident No DI DALAM FILE. Karena Tech Ident No
        // adalah identitas unik satu aset, nilai yang muncul lebih dari sekali
        // di satu file tidak bisa dipastikan baris mana yang benar.
        $hitungTech = [];

        // Sama untuk Equipment No: nomor ini hanya boleh menunjuk satu baris di
        // dalam satu file (nomornya juga unik di database).
        $hitungNomor = [];

        foreach ($semuaBaris as $baris) {
            if ($baris['tech_ident_no'] !== '') {
                $kunci = mb_strtoupper($baris['tech_ident_no']);
                $hitungTech[$kunci] = ($hitungTech[$kunci] ?? 0) + 1;
            }

            if (($baris['equipment_no'] ?? '') !== '') {
                $nomor = mb_strtoupper($baris['equipment_no']);
                $hitungNomor[$nomor] = ($hitungNomor[$nomor] ?? 0) + 1;
            }
        }

        // Tahap 2: kelompokkan tiap baris ke bucket.
        $results = $this->kelompokkanBaris($semuaBaris, $hitungTech, $hitungNomor, $results);

        $results['funcloc_preview'] = $this->previewFuncLocs(array_merge(
            array_column($results['clean'], 'functional_loc'),
            array_column($results['duplicate'], 'functional_loc'),
        ));

        return $results;
    }

    /**
     * Kumpulkan seluruh baris data dari semua sheet tanpa mencocokkan apa pun.
     *
     * Baris sisa blok judul/keterangan pada file hasil export dilewati dengan
     * syarat Equipment No, Tech Ident No, Functional Loc., dan Description
     * sama-sama kosong.
     *
     * @param  array<int, array{name: string, rows: array}>  $sheets
     * @param  array  $results  Diisi jumlah baris yang dibaca (by reference)
     * @return array<int, array{row: int, equipment_no: string, tech_ident_no: string, functional_loc: string, data: array}>
     */
    private function kumpulkanBaris(array $sheets, array &$results): array
    {
        $semuaBaris = [];

        foreach ($sheets as $sheet) {
            $rows = $sheet['rows'];

            if (count($rows) < 2) {
                continue;
            }

            // Baris header dicari otomatis (bukan selalu baris pertama) supaya
            // file hasil Export aplikasi yang punya blok judul + keterangan
            // filter di atas tabel tetap terbaca.
            $headerIndex = $this->findHeaderRowIndex($rows);

            if ($headerIndex === null) {
                continue;
            }

            $colMap = $this->buildColMap($rows[$headerIndex]);

            $results['total_rows'] += count($rows) - $headerIndex - 1;

            foreach (array_slice($rows, $headerIndex + 1) as $index => $row) {
                $equipNo   = trim((string) $this->colVal($row, $colMap, 'equipment'));
                $techNo    = trim((string) $this->colVal($row, $colMap, 'tech_ident_no'));
                $funcLoc   = trim((string) $this->colVal($row, $colMap, 'functional_loc'));
                $deskripsi = trim((string) $this->colVal($row, $colMap, 'description'));

                // Baris sisa blok keterangan / baris kosong dilewati. Baris yang
                // memuat Equipment No TIDAK dianggap kosong supaya baris yang
                // identitasnya jelas tetap terlaporkan, bukan hilang diam-diam.
                if ($equipNo === '' && $techNo === '' && $funcLoc === '' && $deskripsi === '') {
                    continue;
                }

                $semuaBaris[] = [
                    'row'            => $index + $headerIndex + 2,
                    'equipment_no'   => $equipNo,
                    'tech_ident_no'  => $techNo,
                    'functional_loc' => $funcLoc,
                    'data'           => [
                        'row'            => $index + $headerIndex + 2,
                        'equipment_no'   => $equipNo,
                        'description'    => $deskripsi,
                        'tech_ident_no'  => $techNo,
                        'object_type'    => $this->colVal($row, $colMap, 'object_type'),
                        'equipment_type' => $this->colVal($row, $colMap, 'equipment_type'),
                        'functional_loc' => $funcLoc,
                        'location'       => $this->parseFunctionalLoc($funcLoc),
                        'manufacturer'   => $this->colVal($row, $colMap, 'manufacturer'),
                        'model_number'   => $this->colVal($row, $colMap, 'model_number'),
                        'construct_year' => $this->colVal($row, $colMap, 'construct_year'),
                        // Kolom tambahan file export aplikasi sendiri.
                        'status'         => $this->colVal($row, $colMap, 'status'),
                        'kode_lokasi'    => [
                            'company_code' => $this->colVal($row, $colMap, 'company_col'),
                            'dept_code'    => $this->colVal($row, $colMap, 'department_col'),
                            'area_code'    => $this->colVal($row, $colMap, 'area_col'),
                            'subarea_code' => $this->colVal($row, $colMap, 'sub_area_col'),
                        ],
                    ],
                ];
            }
        }

        return $semuaBaris;
    }

    /**
     * Kelompokkan baris hasil kumpulkanBaris() ke bucket clean/duplicate/
     * no_equip/bad_funcloc/ambigu.
     *
     * @param  array<int, array>  $semuaBaris
     * @param  array<string, int>  $hitungTech   Jumlah kemunculan Tech Ident No di file
     * @param  array<string, int>  $hitungNomor  Jumlah kemunculan Equipment No di file
     * @param  array  $results  Bucket kosong yang disiapkan analyzeFile()
     * @return array  Bucket yang sudah terisi
     */
    private function kelompokkanBaris(array $semuaBaris, array $hitungTech, array $hitungNomor, array $results): array
    {
        // Peta nilai angka -> aset dengan Tech Ident No bentuk <angka>E<pangkat>,
        // dipakai mendeteksi nilai yang sudah dikonversi Excel/Google Sheets
        // (mis. "61530" yang aslinya "6153E1").
        $petaTerpotong = $this->petaTechTerpotong();

        foreach ($semuaBaris as $baris) {
            $rowData = $baris['data'];
            $techNo  = $baris['tech_ident_no'];

            // funcloc_id wajib diisi (kolom assets.funcloc_id NOT NULL), jadi
            // baris tanpa Functional Loc. tidak bisa diimport sama sekali.
            if ($baris['functional_loc'] === '') {
                $results['bad_funcloc'][] = [
                    'row'           => $baris['row'],
                    'tech_ident_no' => $techNo,
                    'description'   => $rowData['description'],
                ];

                continue;
            }

            // Tanpa Kode Alat: baris TIDAK diproses walau Equipment No-nya terisi,
            // supaya sel Kode Alat yang kosong tidak menghapus nilai yang sudah ada
            // di database (lihat bucket no_equip pada docblock analyzeFile()).
            if ($techNo === '') {
                $results['no_equip'][] = [
                    'row'            => $baris['row'],
                    'description'    => $rowData['description'],
                    'functional_loc' => $baris['functional_loc'],
                    'equipment_type' => $rowData['equipment_type'],
                ];

                continue;
            }

            // UTAMA: Equipment No. Nomor ini ikut diexport dan berindeks unik,
            // sehingga Kode Alat (Tech Ident No) boleh diubah pada file tanpa
            // membuat aset baru. Baris tanpa nomor (file lama, atau baris baru
            // yang belum punya nomor) jatuh ke pencocokan Kode Alat di bawah.
            $equipNo = trim((string) ($rowData['equipment_no'] ?? ''));

            if ($equipNo !== '') {
                if (($hitungNomor[mb_strtoupper($equipNo)] ?? 0) > 1) {
                    $rowData['alasan']   = 'Equipment No "' . $equipNo . '" muncul lebih dari sekali di file ini.';
                    $results['ambigu'][] = $rowData;

                    continue;
                }

                $lewatNomor = Asset::whereRaw('UPPER(TRIM(equipment_no)) = ?', [mb_strtoupper($equipNo)])->first();

                if ($lewatNomor !== null) {
                    $rowData['asset_id']          = $lewatNomor->id;
                    $rowData['equipment_no_lama'] = $lewatNomor->equipment_no;

                    // Baris cermin modul lain (perangkat IT, ruang panel)
                    // dikelola modulnya masing-masing, bukan lewat impor asset.
                    if (in_array($lewatNomor->data_source, ['it_sync', 'panel_sync'], true)) {
                        $results['it_sync'][] = $rowData;

                        continue;
                    }

                    $rowData['dicocokkan_lewat']  = 'equipment_no';

                    // Nilai Kode Alat yang sudah rusak karena konversi
                    // spreadsheet (mis. "61530" yang aslinya "6153E1") tidak
                    // boleh menimpa nilai asli di database.
                    if ($this->kodeAlatTerpotong($techNo, (string) $lewatNomor->tech_ident_no)) {
                        $rowData['tech_ident_terpotong'] = [
                            'nilai_file'     => $techNo,
                            'nilai_database' => $lewatNomor->tech_ident_no,
                        ];
                        $rowData['perubahan'] = $this->bandingkanPerubahan($lewatNomor, $rowData, true);

                        $results['duplicate'][] = $rowData;

                        continue;
                    }

                    // Kode Alat baru yang sudah dipakai aset LAIN membuat
                    // pencarian alat jadi ambigu, jadi baris tidak ditulis dan
                    // dilaporkan supaya diperiksa dulu (Riwayat Insiden #15).
                    $kodeDipakaiLain = Asset::whereRaw('UPPER(TRIM(tech_ident_no)) = ?', [mb_strtoupper($techNo)])
                        ->where('id', '!=', $lewatNomor->id)
                        ->get();

                    if ($kodeDipakaiLain->isNotEmpty()) {
                        $rowData['kandidat_ids'] = $kodeDipakaiLain->pluck('id')->all();
                        $rowData['kandidat']     = $this->ringkasKandidat($kodeDipakaiLain);
                        $rowData['alasan']       = 'Kode Alat "' . $techNo . '" sudah dipakai aset lain (Equipment No '
                            . implode(', ', $kodeDipakaiLain->pluck('equipment_no')->all())
                            . '), padahal baris ini milik Equipment No ' . $equipNo . '.';
                        $results['ambigu'][]     = $rowData;

                        continue;
                    }

                    $rowData['perubahan']   = $this->bandingkanPerubahan($lewatNomor, $rowData);
                    $results['duplicate'][] = $rowData;

                    continue;
                }
            }

            // Tech Ident No mustahil unik kalau muncul lebih dari sekali di file.
            if (($hitungTech[mb_strtoupper($techNo)] ?? 0) > 1) {
                $rowData['alasan']   = 'Tech Ident No "' . $techNo . '" muncul lebih dari sekali di file ini.';
                $results['ambigu'][] = $rowData;

                continue;
            }

            $kandidat = Asset::whereRaw('UPPER(TRIM(tech_ident_no)) = ?', [mb_strtoupper($techNo)])->get();

            if ($kandidat->count() > 1) {
                $rowData['kandidat_ids'] = $kandidat->pluck('id')->all();
                $rowData['kandidat']     = $this->ringkasKandidat($kandidat);
                $rowData['alasan']       = 'Tech Ident No "' . $techNo . '" dipakai oleh ' . $kandidat->count() . ' aset di database.';
                $results['ambigu'][]     = $rowData;

                continue;
            }

            if ($kandidat->count() === 1) {
                $existing = $kandidat->first();

                $rowData['asset_id']          = $existing->id;
                $rowData['equipment_no_lama'] = $existing->equipment_no;

                // Baris cermin perangkat IT (data_source 'it_sync') TIDAK
                // diproses import asset: datanya dikelola di halaman IT Assets
                // lewat import perangkat IT, sedangkan baris cermin ruang panel
                // (data_source 'panel_sync') dikelola di halaman Monitoring
                // Ruang Panel. Baris seperti ini dikumpulkan di bucket sendiri
                // supaya tidak dilaporkan sebagai "akan diperbarui" padahal
                // isinya tidak berubah.
                if (in_array($existing->data_source, ['it_sync', 'panel_sync'], true)) {
                    $results['it_sync'][] = $rowData;

                    continue;
                }

                $rowData['perubahan']        = $this->bandingkanPerubahan($existing, $rowData);
                $rowData['dicocokkan_lewat'] = 'tech_ident_no';

                $results['duplicate'][] = $rowData;

                continue;
            }

            // Tidak ada kecocokan persis. Sebelum dianggap data baru, periksa
            // apakah nilai ini adalah bentuk ANGKA dari Tech Ident No yang
            // sudah ada (mis. "61530" sebenarnya "6153E1" yang dikonversi
            // spreadsheet). Kolom Tech Ident No di database tidak pernah
            // ditimpa nilai yang sudah terpotong itu.
            $digit    = $this->digitKanonik($techNo);
            $kandidat = $digit !== null ? ($petaTerpotong[$digit] ?? []) : [];

            if (count($kandidat) === 1) {
                $existing = $kandidat[0];

                if (in_array($existing->data_source, ['it_sync', 'panel_sync'], true)) {
                    $results['it_sync'][] = $rowData;

                    continue;
                }

                $rowData['asset_id']          = $existing->id;
                $rowData['equipment_no_lama'] = $existing->equipment_no;
                $rowData['dicocokkan_lewat']  = 'tech_ident_no';
                $rowData['tech_ident_terpotong'] = [
                    'nilai_file'     => $techNo,
                    'nilai_database' => $existing->tech_ident_no,
                ];
                $rowData['perubahan'] = $this->bandingkanPerubahan($existing, $rowData, true);

                $results['duplicate'][] = $rowData;

                continue;
            }

            if (count($kandidat) > 1) {
                $rowData['kandidat_ids'] = array_map(static fn (Asset $a) => $a->id, $kandidat);
                $rowData['kandidat']     = $this->ringkasKandidat(collect($kandidat));
                $rowData['alasan']       = 'Nilai "' . $techNo . '" cocok dengan lebih dari satu Tech Ident No di database: '
                    . implode(', ', array_map(static fn (Asset $a) => (string) $a->tech_ident_no, $kandidat)) . '.';
                $results['ambigu'][]     = $rowData;

                continue;
            }

            $results['clean'][] = $rowData;
        }

        return $results;
    }

    /**
     * Ringkas daftar aset kandidat supaya bisa ditampilkan di halaman import
     * (baris "Perlu Diperiksa"): id + nomor equipment + Tech Ident No +
     * deskripsi + lokasi + sumber data aset tersebut.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $kandidat
     * @return array<int, array<string, string|int|null>>
     */
    private function ringkasKandidat($kandidat): array
    {
        return $kandidat
            ->map(static fn (Asset $aset) => [
                'id'             => $aset->id,
                'equipment_no'   => $aset->equipment_no,
                'tech_ident_no'  => $aset->tech_ident_no,
                'description'    => $aset->description,
                'functional_loc' => $aset->functional_loc,
                'data_source'    => $aset->data_source,
                'diperbarui'     => $aset->updated_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Apakah nilai Kode Alat di file adalah bentuk RUSAK dari nilai yang ada di
     * database karena konversi spreadsheet, mis. file berisi "61530" sedangkan
     * database menyimpan "6153E1"?
     *
     * Dipakai agar pencocokan lewat Equipment No tidak menimpa Kode Alat yang
     * masih benar dengan nilai yang sudah terpotong.
     *
     * @param  string       $nilaiFile      Nilai Kode Alat pada file
     * @param  string|null  $nilaiDatabase  Nilai Kode Alat di database
     * @return bool  true bila keduanya sama-sama bentuk angka yang setara
     */
    private function kodeAlatTerpotong(string $nilaiFile, ?string $nilaiDatabase): bool
    {
        $nilaiFile     = trim($nilaiFile);
        $nilaiDatabase = trim((string) $nilaiDatabase);

        if ($nilaiFile === '' || $nilaiFile === $nilaiDatabase) {
            return false;
        }

        $digitFile     = $this->digitKanonik($nilaiFile);
        $digitDatabase = $this->digitKanonik($nilaiDatabase);

        return $digitFile !== null && $digitFile === $digitDatabase;
    }

    /**
     * Peta nilai angka kanonik -> aset yang Tech Ident No-nya berbentuk
     * <angka>E<pangkat> (mis. 6153E1).
     *
     * Dipakai untuk mengenali baris file yang nilai Tech Ident No-nya sudah
     * dikonversi Excel/Google Sheets menjadi angka, sehingga baris itu tetap
     * dicocokkan ke aset yang benar alih-alih dibuat sebagai aset duplikat.
     *
     * @return array<string, array<int, Asset>>  Kunci = digit kanonik
     */
    private function petaTechTerpotong(): array
    {
        $peta = [];

        // Sengaja memakai LIKE (bukan REGEXP) supaya jalan di MySQL maupun
        // SQLite: penyaringan presisi bentuk <angka>E<pangkat> dilakukan di PHP
        // lewat digitKanonik(). Kolom yang diambil harus lengkap karena model ini
        // dipakai juga untuk membandingkan perubahan field.
        $asetPolaE = Asset::whereNotNull('tech_ident_no')
            ->where('tech_ident_no', 'like', '%E%')
            ->get([
                'id', 'equipment_no', 'tech_ident_no', 'description', 'equipment_type',
                'functional_loc', 'manufacturer', 'model_number', 'construct_year',
                'status', 'data_source', 'updated_at',
            ]);

        foreach ($asetPolaE as $aset) {
            $digit = $this->digitKanonik((string) $aset->tech_ident_no);

            if ($digit !== null) {
                $peta[$digit][] = $aset;
            }
        }

        return $peta;
    }

    /**
     * Ubah nilai Tech Ident No yang tampak angka menjadi bentuk digit kanonik
     * TANPA melibatkan float, sehingga "6153E1", "61530", dan "6.153E+4"
     * sama-sama menghasilkan "61530".
     *
     * @param  string|null  $nilai
     * @return string|null  Digit kanonik, atau null bila bukan bentuk angka
     */
    private function digitKanonik(?string $nilai): ?string
    {
        $nilai = strtoupper(trim((string) $nilai));

        if ($nilai === '') {
            return null;
        }

        // Bentuk <mantissa>E<pangkat> seperti di database (mis. 6153E1).
        if (preg_match('/^([0-9]+)E([0-9]+)$/', $nilai, $cocok) === 1) {
            return $cocok[1] . str_repeat('0', (int) $cocok[2]);
        }

        // Angka biasa (mis. 61530).
        if (preg_match('/^[0-9]+$/', $nilai) === 1) {
            return $nilai;
        }

        // Notasi ilmiah hasil konversi spreadsheet (mis. 6.163E+23).
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?E([+-]?[0-9]+)$/', $nilai, $cocok) === 1) {
            $desimal = $cocok[2] ?? '';
            $pangkat = (int) $cocok[3] - strlen($desimal);

            if ($pangkat < 0) {
                return null;
            }

            return $cocok[1] . $desimal . str_repeat('0', $pangkat);
        }

        return null;
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
        $insertedCount = 0;
        $updatedCount  = 0;
        $terpotongCount = 0;
        $tanpaPerubahan = count(array_filter(
            $analysis['duplicate'],
            static fn (array $baris) => empty($baris['perubahan'])
        ));

        // Baris perangkat IT sudah dipisahkan di analyzeFile() (bucket it_sync)
        // dan tidak pernah diproses; angka ini dilaporkan sebagai informasi.
        $itSyncSkipped = count($analysis['it_sync'] ?? []);
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

        DB::transaction(function () use ($analysis, $duplicateAction, $noEquipAction, &$successCount, &$errorCount, &$itSyncSkipped, &$insertedCount, &$updatedCount, &$terpotongCount, $importLogData) {
            // Proses baris bersih
            foreach ($analysis['clean'] as $row) {
                $funcLocNode = $this->syncFuncLocFromRow($row['functional_loc']);

                if (!$funcLocNode) {
                    $errorCount++;
                    continue;
                }

                $location = $this->findOrCreateLocation($row['location']);

                // Nomor equipment SELALU dibuat sistem (tidak diambil dari file):
                // nomor adalah id internal, bukan data yang diinput user.
                $equipmentNo = $this->assetNumbers->generateForCompanyId($location['company_id'] ?? null);

                Asset::create(array_merge(
                    $this->mapToAsset($row),
                    $location,
                    [
                        'equipment_no' => $equipmentNo,
                        'funcloc_id' => $funcLocNode->id,
                        'status' => $this->statusValid($row['status'] ?? null) ?? 'active',
                        'has_equipment_no' => true,
                        'data_source' => 'import_excel',
                        'imported_at' => now(),
                    ]
                ));
                $successCount++;
                $insertedCount++;
            }

            // Proses duplikat
            if ($duplicateAction !== 'skip') {
                foreach ($analysis['duplicate'] as $row) {
                    // Aset lama ditunjuk lewat asset_id hasil analisa (pencocokan
                    // Equipment No lebih dulu, baru Kode Alat); kolom Equipment
                    // No di file dipakai sebagai cadangan untuk hasil analisa
                    // versi lama yang belum membawa asset_id.
                    $existing = !empty($row['asset_id'])
                        ? Asset::find($row['asset_id'])
                        : Asset::where('equipment_no', $row['equipment_no'] ?? null)->first();

                    // Baris tanpa aset tujuan tidak boleh hilang diam-diam:
                    // setiap baris harus tercatat di salah satu kategori.
                    if (!$existing) {
                        $errorCount++;

                        continue;
                    }

                    // Baris cermin modul lain (perangkat IT data_source
                    // 'it_sync', ruang panel data_source 'panel_sync') dikelola
                    // modulnya masing-masing, jadi tidak diubah dari impor
                    // Excel aset supaya datanya tidak menyimpang.
                    if (in_array($existing->data_source, ['it_sync', 'panel_sync'], true)) {
                        $itSyncSkipped++;
                        continue;
                    }

                    if ($duplicateAction === 'replace') {
                        $funcLocNode = $this->syncFuncLocFromRow($row['functional_loc']);

                        if (!$funcLocNode) {
                            $errorCount++;
                            continue;
                        }

                        $location = $this->findOrCreateLocation($row['location']);

                        // Kolom Company/Department/Area/Sub Area pada file hasil
                        // export dipakai sebagai penentu lokasi bila diisi, supaya
                        // penyesuaian kolom tersebut benar-benar ikut terupdate.
                        $lokasiKolom = $this->resolveLokasiDariKode($row['kode_lokasi'] ?? []);

                        if (!$lokasiKolom['ok']) {
                            $errorCount++;
                            continue;
                        }

                        if ($lokasiKolom['ada']) {
                            $location = array_filter($lokasiKolom['ids'], fn ($id) => $id !== null) + $location;
                        }

                        $updateData = array_merge(
                            $this->mapToAsset($row),
                            $location,
                            ['funcloc_id' => $funcLocNode->id, 'imported_at' => now()]
                        );

                        // Nomor equipment adalah id internal yang dibuat sistem:
                        // tidak pernah diubah oleh file import (kolom Equipment
                        // No di file pun tidak dibaca).
                        unset($updateData['equipment_no']);

                        // Baris yang dicocokkan dari nilai Tech Ident No hasil
                        // konversi Excel/Google Sheets: nilai di database
                        // dipertahankan karena nilai di file sudah rusak.
                        if (!empty($row['tech_ident_terpotong'])) {
                            unset($updateData['tech_ident_no']);
                            $terpotongCount++;
                        }

                        // Sheet tanpa kolom "Equipment Type" (mis. Sheet1 (1))
                        // menghasilkan equipment_type kosong. Jangan biarkan nilai
                        // kosong itu menimpa tipe yang sudah diisi dari sheet lain
                        // (Sheet1) — pertahankan nilai lama.
                        if (empty($row['equipment_type'] ?? '')) {
                            unset($updateData['equipment_type']);
                        }

                        // Nilai yang hanya berbeda BENTUK PENULISAN ANGKA
                        // (mis. file berisi "4373.26019999999971" karena sel XLSX
                        // bertipe angka) jangan menimpa nilai bersih di database.
                        foreach (self::KOLOM_TIDAK_BOLEH_KOSONG as $kolomAngka) {
                            if (! isset($updateData[$kolomAngka])) {
                                continue;
                            }

                            $nilaiBaru = (string) $updateData[$kolomAngka];

                            // Sel kosong tidak mengosongkan nilai yang sudah ada.
                            if (trim($nilaiBaru) === '' && trim((string) $existing->{$kolomAngka}) !== '') {
                                unset($updateData[$kolomAngka]);

                                continue;
                            }

                            if ($this->hanyaBedaFormatAngka($nilaiBaru, (string) $existing->{$kolomAngka})) {
                                unset($updateData[$kolomAngka]);
                            }
                        }

                        if (isset($updateData['tech_ident_no'])
                            && $this->hanyaBedaFormatAngka((string) $updateData['tech_ident_no'], (string) $existing->tech_ident_no)) {
                            unset($updateData['tech_ident_no']);
                        }

                        // Status dari file hanya dipakai bila nilainya dikenal,
                        // sehingga kolom Status yang tidak diisi tidak menimpa.
                        $statusBaru = $this->statusValid($row['status'] ?? null);

                        if ($statusBaru !== null) {
                            $updateData['status'] = $statusBaru;
                        }

                        $existing->update($updateData);
                        $successCount++;
                        $updatedCount++;
                    } elseif ($duplicateAction === 'keep_flag') {
                        $existing->update(['status' => 'needs_review']);
                        $successCount++;
                        $updatedCount++;
                    }
                }
            }

            // Proses baris tanpa Tech Ident No. (tidak ada identitas yang bisa
            // dicocokkan). Nomor equipment tetap dibuat sistem, status ditandai
            // perlu review supaya admin melengkapi Tech Ident No-nya.
            if ($noEquipAction === 'flag') {
                foreach ($analysis['no_equip'] as $row) {
                    $funcLocString = $row['functional_loc'] ?? '';
                    $funcLocNode = $funcLocString !== '' ? $this->syncFuncLocFromRow($funcLocString) : null;

                    // Baris tanpa Tech Ident No. sekaligus tanpa Functional Loc.
                    // tidak bisa dibuat karena funcloc_id wajib diisi
                    if (!$funcLocNode) {
                        $errorCount++;
                        continue;
                    }

                    $location = $this->findOrCreateLocation($this->parseFunctionalLoc($funcLocString));
                    $tipe     = trim((string) ($row['equipment_type'] ?? ''));

                    Asset::create(array_merge(
                        [
                            'equipment_no' => $this->assetNumbers->generateForCompanyId($location['company_id'] ?? null),
                            'description' => $row['description'] ?? '',
                            'functional_loc' => $funcLocString,
                            'equipment_type' => $tipe !== '' ? $tipe : null,
                        ],
                        $location,
                        [
                            'funcloc_id' => $funcLocNode->id,
                            'status' => 'needs_review',
                            'has_equipment_no' => true,
                            'data_source' => 'import_excel',
                            'imported_at' => now(),
                        ]
                    ));
                    $successCount++;
                    $insertedCount++;
                }
            }

            // Baris dengan TechIdentNo yang dipakai lebih dari satu aset tidak
            // bisa dicocokkan dengan aman -> dilewati dan dilaporkan.
            $errorCount += count($analysis['ambigu'] ?? []);

            // Baris di bucket bad_funcloc (Functional Loc. kosong) sengaja tidak
            // pernah dibuat menjadi Asset lewat jalur import. Dihitung sebagai
            // error agar tercatat di log, admin menambahkannya manual lewat form
            // Create Asset setelahnya.
            $errorCount += count($analysis['bad_funcloc']);

            // Simpan log import
            $importLogData['success_count'] = $successCount;
            $importLogData['error_count'] = $errorCount;
            AssetImportLog::create($importLogData);
        });

        $message = "Import berhasil. {$successCount} baris diproses ({$insertedCount} data baru, {$updatedCount} diperbarui).";
        if ($tanpaPerubahan > 0) {
            $message .= " {$tanpaPerubahan} di antaranya cocok dengan data lama tanpa perubahan nilai (isi file sama dengan database).";
        }
        if ($terpotongCount > 0) {
            $message .= " {$terpotongCount} baris dicocokkan dari nilai Tech Ident No yang terpotong Excel/Google Sheets (nilai Tech Ident No di database dipertahankan).";
        }
        if ($errorCount > 0) {
            $message .= " {$errorCount} baris dilewati (Functional Location kosong, tanpa Kode Alat, atau identitas aset tidak unik).";
        }
        if ($itSyncSkipped > 0) {
            $message .= " {$itSyncSkipped} baris perangkat IT dilewati (data IT dikelola modul IT Assets).";
        }

        return [
            'status' => 'success',
            'message' => $message,
            'success_count' => $successCount,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'truncated_matched_count' => $terpotongCount,
            'unchanged_count' => $tanpaPerubahan,
            'error_count' => $errorCount,
            'it_sync_skipped' => $itSyncSkipped,
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
    /**
     * Cari indeks baris header pada sebuah sheet.
     *
     * Header dikenali bila baris tersebut memuat kolom Functional Loc DAN salah
     * satu dari Equipment No / Tech Ident No. Pencarian dibatasi 20 baris
     * pertama, cukup untuk melewati blok judul & keterangan filter pada file
     * hasil export.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return int|null  Indeks baris header, atau null bila tidak ditemukan
     */
    private function findHeaderRowIndex(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 20, true) as $indeks => $baris) {
            $colMap = $this->buildColMap($baris);

            $adaFuncLoc = isset($colMap['functional_loc']);
            $adaPenanda = isset($colMap['equipment']) || isset($colMap['tech_ident_no']);

            if ($adaFuncLoc && $adaPenanda) {
                return (int) $indeks;
            }
        }

        return null;
    }

    /**
     * Bandingkan isi file dengan aset lama hasil pencocokan Tech Ident No,
     * untuk ditampilkan di halaman preview sebagai daftar "data yang akan
     * diperbarui" (field apa berubah, dari nilai apa ke nilai apa).
     *
     * Aturan update di executeImport() diikuti di sini supaya daftar perubahan
     * tidak menjanjikan sesuatu yang tidak akan ditulis: Equipment Type kosong
     * dari file tidak menimpa nilai lama, dan Status yang tidak dikenal tidak
     * dipakai.
     *
     * @param  Asset  $existing  Aset lama yang cocok Tech Ident No-nya
     * @param  array  $row       Baris file yang sudah dinormalisasi
     * @param  bool   $abaikanTechIdent  true = jangan bandingkan kolom Tech Ident
     *                                   No (dipakai untuk baris yang dicocokkan
     *                                   dari nilai angka hasil konversi Excel,
     *                                   nilai di database dipertahankan)
     * @return array<int, array{field: string, lama: string, baru: string}>
     */
    private function bandingkanPerubahan(Asset $existing, array $row, bool $abaikanTechIdent = false): array
    {
        $perubahan = [];

        $kolom = [
            'description'    => 'Description',
            'tech_ident_no'  => 'Tech Ident No',
            'equipment_type' => 'Equipment Type',
            'functional_loc' => 'Functional Loc',
            'manufacturer'   => 'Manufacturer',
            'model_number'   => 'Model Number',
            'construct_year' => 'Construct Year',
        ];

        foreach ($kolom as $nama => $label) {
            if ($abaikanTechIdent && $nama === 'tech_ident_no') {
                continue;
            }

            $baru = trim((string) ($row[$nama] ?? ''));
            $lama = trim((string) ($existing->{$nama} ?? ''));

            if ($nama === 'equipment_type' && $baru === '') {
                continue;
            }

            // Kolom deskriptif tidak pernah dikosongkan oleh file: sel kosong
            // (mis. file tanpa kolom Manufacturer/Model/Tahun) TIDAK menimpa
            // nilai yang sudah ada di database.
            if ($baru === '' && in_array($nama, self::KOLOM_TIDAK_BOLEH_KOSONG, true)) {
                continue;
            }

            // Beda hanya pada bentuk penulisan angka (artefak float spreadsheet)
            // dianggap TIDAK berubah supaya tidak muncul perubahan palsu.
            if ($this->hanyaBedaFormatAngka($baru, $lama)) {
                continue;
            }

            if ($baru !== $lama) {
                $perubahan[] = ['field' => $label, 'lama' => $lama, 'baru' => $baru];
            }
        }

        $statusBaru = $this->statusValid($row['status'] ?? null);

        if ($statusBaru !== null && $statusBaru !== $existing->status) {
            $perubahan[] = [
                'field' => 'Status',
                'lama'  => (string) $existing->status,
                'baru'  => $statusBaru,
            ];
        }

        return $perubahan;
    }

    private function buildColMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            // Lowercase dulu, baru buang karakter non-huruf — urutan penting
            // supaya huruf kapital tidak ikut terhapus oleh regex [^a-z].
            $name = preg_replace('/[^a-z]/', '', strtolower((string) $header));

            switch ($name) {
                // Nama kolom SAP (template import) dan nama kolom hasil Export
                // aplikasi ini sama-sama dikenali, supaya file hasil export bisa
                // disesuaikan lalu diimport ulang untuk MEMPERBARUI data
                // ("Equipment No" pada export vs "Equipment" pada template SAP).
                case 'equipment':
                case 'equipmentno':   $map['equipment'] = $index; break;

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

                // Kolom tambahan yang hanya ada pada file hasil export aplikasi.
                case 'company':       $map['company_col'] = $index; break;
                case 'department':    $map['department_col'] = $index; break;
                case 'area':          $map['area_col'] = $index; break;
                case 'subarea':       $map['sub_area_col'] = $index; break;
                case 'status':        $map['status'] = $index; break;
                case 'datasource':    $map['data_source'] = $index; break;
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

        $nilai = $row[$colMap[$key]] ?? '';

        // Sel angka dari file Excel terbaca sebagai float. Format ulang tanpa
        // notasi ilmiah (mis. 61600000 tetap "61600000", bukan "6.16E+7")
        // supaya nilai seperti Tech Ident No tidak berubah bentuk.
        if (is_float($nilai)) {
            $nilai = $nilai === floor($nilai) ? sprintf('%.0F', $nilai) : (string) $nilai;
        }

        return $this->rapikanAngkaPanjang(trim((string) $nilai));
    }

    /**
     * Rapikan angka desimal panjang hasil pembulatan float dari pembaca
     * spreadsheet (mis. sel XLSX angka 4373.2602 terbaca "4373.26019999999971").
     *
     * Hanya bentuk desimal dengan 12+ angka di belakang koma yang disentuh;
     * teks lain (termasuk angka biasa dan kode alat) dibiarkan apa adanya.
     *
     * @param  string  $nilai
     * @return string  Nilai yang sudah dirapikan
     */
    private function rapikanAngkaPanjang(string $nilai): string
    {
        if (preg_match('/^-?[0-9]+\.[0-9]{12,}$/', $nilai) !== 1) {
            return $nilai;
        }

        $rapi = rtrim(rtrim(sprintf('%.12F', (float) $nilai), '0'), '.');

        return $rapi !== '' ? $rapi : $nilai;
    }

    /**
     * Apakah dua nilai hanya berbeda bentuk penulisan angka, bukan nilai
     * (mis. "4373.2602" vs "4373.26019999999971" hasil pembulatan float)?
     * Dipakai supaya import tidak melaporkan/menulis perubahan palsu.
     *
     * @param  string|null  $nilaiBaru
     * @param  string|null  $nilaiLama
     * @return bool
     */
    private function hanyaBedaFormatAngka(?string $nilaiBaru, ?string $nilaiLama): bool
    {
        $baru = trim((string) $nilaiBaru);
        $lama = trim((string) $nilaiLama);

        if ($baru === '' || $lama === '' || $baru === $lama) {
            return false;
        }

        if (! is_numeric($baru) || ! is_numeric($lama)) {
            return false;
        }

        return (float) $baru === (float) $lama;
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

        // Semua nilai dibaca apa adanya sebagai TEKS. Tanpa ini, nilai seperti
        // "6153E1" (Tech Ident No) dianggap angka oleh pembaca spreadsheet
        // (is_numeric('6153E1') bernilai true) sehingga berubah menjadi 61530
        // dan baris tersebut gagal dicocokkan dengan aset yang sudah ada.
        if (method_exists($reader, 'setValueBinder')) {
            $reader->setValueBinder(new StringValueBinder());
        }

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

    /**
     * Pastikan nilai status dari file import termasuk status yang dikenal.
     *
     * @param  string|null  $status
     * @return string|null  Status valid, atau null bila kosong/tidak dikenal
     */
    private function statusValid(?string $status): ?string
    {
        $status = trim((string) $status);

        return in_array($status, ['active', 'inactive', 'needs_review', 'spare'], true) ? $status : null;
    }

    /**
     * Ubah kode lokasi dari kolom file export (Company/Department/Area/Sub Area)
     * menjadi ID yang benar-benar ada di database.
     *
     * Dipakai agar penyesuaian kolom lokasi pada file export ikut terupdate saat
     * diimport ulang. Bila salah satu kode yang diisi tidak ditemukan, baris
     * dianggap gagal (ok = false) supaya tidak terjadi perubahan lokasi yang
     * keliru tanpa pemberitahuan.
     *
     * @param  array<string, string>  $kode
     * @return array{ok: bool, ada: bool, ids: array<string, int|null>}
     */
    private function resolveLokasiDariKode(array $kode): array
    {
        $ids = [
            'company_id'    => null,
            'department_id' => null,
            'area_id'       => null,
            'sub_area_id'   => null,
        ];

        $diisi = array_filter($kode, fn ($nilai) => trim((string) $nilai) !== '');

        if ($diisi === []) {
            return ['ok' => true, 'ada' => false, 'ids' => $ids];
        }

        if (!empty($kode['company_code'])) {
            $company = Company::where('code', trim((string) $kode['company_code']))->first();

            if ($company === null) {
                return ['ok' => false, 'ada' => true, 'ids' => $ids];
            }

            $ids['company_id'] = $company->id;
        }

        if (!empty($kode['dept_code'])) {
            $deptQuery = Department::where('code', trim((string) $kode['dept_code']));

            if ($ids['company_id'] !== null) {
                $deptQuery->where('company_id', $ids['company_id']);
            }

            $dept = $deptQuery->first();

            if ($dept === null) {
                return ['ok' => false, 'ada' => true, 'ids' => $ids];
            }

            $ids['department_id'] = $dept->id;
            $ids['company_id']    = $ids['company_id'] ?? $dept->company_id;
        }

        if (!empty($kode['area_code'])) {
            $areaQuery = Area::where('code', trim((string) $kode['area_code']));

            if ($ids['department_id'] !== null) {
                $areaQuery->where('department_id', $ids['department_id']);
            }

            $area = $areaQuery->first();

            if ($area === null) {
                return ['ok' => false, 'ada' => true, 'ids' => $ids];
            }

            $ids['area_id']       = $area->id;
            $ids['department_id'] = $ids['department_id'] ?? $area->department_id;
        }

        if (!empty($kode['subarea_code'])) {
            $subAreaQuery = SubArea::where('code', trim((string) $kode['subarea_code']));

            if ($ids['area_id'] !== null) {
                $subAreaQuery->where('area_id', $ids['area_id']);
            }

            $subArea = $subAreaQuery->first();

            if ($subArea === null) {
                return ['ok' => false, 'ada' => true, 'ids' => $ids];
            }

            $ids['sub_area_id'] = $subArea->id;
            $ids['area_id']     = $ids['area_id'] ?? $subArea->area_id;
        }

        return ['ok' => true, 'ada' => true, 'ids' => $ids];
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
