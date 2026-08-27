<?php

namespace App\Services;

use App\Models\ItAsset;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ItAssetImportService
{
    /**
     * Import seluruh sheet perangkat IT dari file
     * "List Perangkat IT Oleochemicals.xlsx" ke tabel it_assets.
     *
     * Sheet komputer/office: PC, LAPTOP, PRINTER (Sesi 2).
     * Sheet infrastruktur: CCTV APPROVED, NVR, Access Door & Absensi,
     * WIFI, MODUL JARINGAN, UPS (Sesi 3).
     *
     * Sheet "BARANG DAN ALAT" dan "Sheet23" sengaja belum diimport karena
     * strukturnya multi-kolom berulang dan bukan prioritas modul ini.
     *
     * @param  UploadedFile  $file
     * @return array<string, array{total: int, inserted: int, updated: int, skipped: int, sheet_found: bool}>
     */
    public function import(UploadedFile $file): array
    {
        $sheets = $this->readExcel($file);
        $sheetsByName = collect($sheets)->keyBy('name');

        return [
            'PC'          => $this->importSheet($sheetsByName->get('PC'), 'PC', fn (array $mapped) => $this->transformPcRow($mapped), headerRowOffset: 2),
            'LAPTOP'      => $this->importSheet($sheetsByName->get('LAPTOP'), 'LAPTOP', fn (array $mapped) => $this->transformLaptopRow($mapped)),
            'PRINTER'     => $this->importSheet($sheetsByName->get('PRINTER'), 'PRINTER', fn (array $mapped) => $this->transformPrinterRow($mapped)),
            'CCTV'        => $this->importSheet($sheetsByName->get('CCTV APPROVED'), 'CCTV', fn (array $mapped) => $this->transformCctvRow($mapped)),
            'NVR'         => $this->importSheet($sheetsByName->get('NVR'), 'NVR', fn (array $mapped) => $this->transformNvrRow($mapped)),
            'ACCESS_DOOR' => $this->importSheet($sheetsByName->get('Access Door & Absensi'), 'ACCESS_DOOR', fn (array $mapped) => $this->transformAccessDoorRow($mapped)),
            'WIFI'        => $this->importSheet($sheetsByName->get('WIFI'), 'WIFI', fn (array $mapped) => $this->transformWifiRow($mapped), requireIp: false, skipFirstDataRows: 1),
            'NETWORK'     => $this->importSheet($sheetsByName->get('MODUL JARINGAN'), 'NETWORK', fn (array $mapped) => $this->transformNetworkRow($mapped)),
            'UPS'         => $this->importSheet($sheetsByName->get('UPS'), 'UPS', fn (array $mapped) => $this->transformUpsRow($mapped), requireIp: false),
        ];
    }

    /**
     * Baca seluruh sheet dari file Excel, satu sheet per waktu untuk
     * menghemat memori (mengikuti pola ImportService::readExcel).
     *
     * @param  UploadedFile  $file
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

            // Buang baris kosong di akhir sheet
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

    /**
     * Proses satu sheet menjadi kumpulan record it_assets, sesuai
     * device_type dan transformer yang diberikan. Duplikat dicek dari
     * device_type + serial_number, lalu device_type + tag_name jika serial
     * kosong.
     *
     * Untuk device_type berjaringan ($requireIp = true, default), baris
     * tanpa IP dianggap skipped. Untuk device_type non-jaringan seperti UPS
     * ($requireIp = false, sheet sumbernya memang tidak punya kolom IP),
     * baris hanya di-skip bila seluruh kolom inti dan spec kosong.
     *
     * @param  array{name: string, rows: array}|null  $sheet
     * @param  string  $deviceType
     * @param  callable(array<string, string|null>): array{0: array<string, mixed>, 1: array<string, mixed>}  $transformer
     * @param  bool  $requireIp
     * @param  int  $headerRowOffset
     * @param  int  $skipFirstDataRows
     * @return array{total: int, inserted: int, updated: int, skipped: int, sheet_found: bool}
     */
    private function importSheet(?array $sheet, string $deviceType, callable $transformer, bool $requireIp = true, int $headerRowOffset = 0, int $skipFirstDataRows = 0): array
    {
        $result = [
            'total'       => 0,
            'inserted'    => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'sheet_found' => $sheet !== null,
        ];

        if ($sheet === null || count($sheet['rows']) <= $headerRowOffset + 1) {
            return $result;
        }

        $headers = $sheet['rows'][$headerRowOffset];
        $colMap  = $this->buildColMap($headers);
        $dataRows = array_slice($sheet['rows'], $headerRowOffset + 1);

        if ($skipFirstDataRows > 0) {
            $dataRows = array_slice($dataRows, $skipFirstDataRows);
        }

        $result['total'] = count($dataRows);

        foreach ($dataRows as $row) {
            $mapped = $this->mapRow($row, $colMap);
            [$core, $spec] = $transformer($mapped);

            $isEmptyRow = $requireIp
                ? empty($core['ip_address'])
                : $this->isEffectivelyEmpty($core, $spec);

            if ($isEmptyRow) {
                $result['skipped']++;
                continue;
            }

            $isNew = $this->upsertItAsset($deviceType, $core, $spec);
            $isNew ? $result['inserted']++ : $result['updated']++;
        }

        return $result;
    }

    /**
     * Petakan baris sheet PC ke kolom inti it_assets dan kolom spec.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformPcRow(array $mapped): array
    {
        $core = [
            'tag_name'      => $mapped['tagname'] ?? null,
            'name'          => $mapped['name'] ?? null,
            'plant'         => $mapped['pt'] ?? null,
            'department'    => $mapped['dept'] ?? null,
            'brand'         => $mapped['typepc'] ?? null,
            'serial_number' => $mapped['snpc'] ?? null,
            'ip_address'    => $mapped['ip'] ?? null,
            'location'      => $mapped['jabatan'] ?? null,
        ];

        $spec = $this->filterSpec([
            'os'            => $mapped['os'] ?? null,
            'type_monitor'  => $mapped['typemonitor'] ?? null,
            'sn_monitor'    => $mapped['snmonitor'] ?? null,
            'cpu'           => $mapped['cpu'] ?? null,
            'ram'           => $mapped['ram'] ?? null,
            'type_ram'      => $mapped['typeram'] ?? null,
            'slot_ram'      => $mapped['slotram'] ?? null,
            'bandwidth_ram' => $mapped['bandwidthram'] ?? null,
            'disk_type'     => $mapped['tipedisk'] ?? null,
            'disk_capacity' => $mapped['kapasitas'] ?? null,
            'antivirus'     => $mapped['antivirus'] ?? null,
            'jcloud'        => $mapped['jcloud'] ?? null,
            'mail'          => $mapped['mail'] ?? null,
            'remote'        => $mapped['remote'] ?? null,
            'nas'           => $mapped['nas'] ?? null,
            'type_access'   => $mapped['typeaccess'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Petakan baris sheet LAPTOP ke kolom inti it_assets dan kolom spec.
     * Lokasi diambil dari kolom Letak, fallback ke Lokasi bila Letak kosong.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformLaptopRow(array $mapped): array
    {
        $core = [
            'tag_name'      => $mapped['laptop'] ?? null,
            'name'          => $mapped['users'] ?? null,
            'serial_number' => $mapped['sn'] ?? null,
            'ip_address'    => $mapped['ip'] ?? null,
            'location'      => $this->firstNonEmpty($mapped, ['letak', 'lokasi']),
        ];

        $spec = $this->filterSpec([
            'mail' => $mapped['mail'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Petakan baris sheet PRINTER ke kolom inti it_assets dan kolom spec.
     * IP diambil dari kolom IP, fallback ke IP HOST bila IP kosong.
     * Lokasi diambil dari kolom Letak, fallback ke Lokasi bila Letak kosong.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformPrinterRow(array $mapped): array
    {
        $core = [
            'tag_name'      => $mapped['printer'] ?? null,
            'name'          => $mapped['milik'] ?? null,
            'serial_number' => $mapped['sn'] ?? null,
            'ip_address'    => $this->firstNonEmpty($mapped, ['ip', 'iphost']),
            'location'      => $this->firstNonEmpty($mapped, ['letak', 'lokasi']),
        ];

        return [$core, []];
    }

    /**
     * Petakan baris sheet CCTV APPROVED ke kolom inti it_assets dan kolom
     * spec. Kolom "Func Lock" dipetakan ke department mengikuti keputusan
     * di rencana Sesi 3.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformCctvRow(array $mapped): array
    {
        $core = [
            'tag_name'    => $mapped['tagname'] ?? null,
            'brand'       => $mapped['brand'] ?? null,
            'model'       => $mapped['type'] ?? null,
            'plant'       => $mapped['plant'] ?? null,
            'location'    => $mapped['location'] ?? null,
            'application' => $mapped['application'] ?? null,
            'ip_address'  => $mapped['ip'] ?? null,
            'department'  => $mapped['funclock'] ?? null,
        ];

        $spec = $this->filterSpec([
            'connect_to' => $mapped['connectto'] ?? null,
            'pic'        => $mapped['pic'] ?? null,
            'remarks'    => $mapped['remarks'] ?? null,
            'status'     => $mapped['status'] ?? null,
            'header'     => $mapped['header'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Petakan baris sheet NVR ke kolom inti it_assets dan kolom spec.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformNvrRow(array $mapped): array
    {
        // Header sheet NVR sebenarnya bernama "Type" (bukan "Model") dan
        // "Chanel" (typo pada file sumber, bukan "Channel"). Pemetaan
        // sebelumnya memakai key 'model' dan 'channel' yang tidak pernah
        // cocok dengan hasil normalisasi header asli, sehingga kedua kolom
        // selalu tersimpan null. Diperbaiki memakai key hasil normalisasi
        // header yang sebenarnya: 'type' dan 'chanel'.
        $core = [
            'tag_name'   => $mapped['tagname'] ?? null,
            'brand'      => $mapped['brand'] ?? null,
            'model'      => $mapped['type'] ?? null,
            'ip_address' => $mapped['ip'] ?? null,
        ];

        $spec = $this->filterSpec([
            'channel' => $mapped['chanel'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Petakan baris sheet Access Door & Absensi ke kolom inti it_assets.
     * Kolom "Hardware" dipetakan ke brand, "Type" dipetakan ke model.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformAccessDoorRow(array $mapped): array
    {
        $core = [
            'plant'         => $mapped['pt'] ?? null,
            'tag_name'      => $mapped['tagname'] ?? null,
            'location'      => $mapped['location'] ?? null,
            'brand'         => $mapped['hardware'] ?? null,
            'model'         => $mapped['type'] ?? null,
            'serial_number' => $mapped['sn'] ?? null,
            'ip_address'    => $mapped['ip'] ?? null,
        ];

        return [$core, []];
    }

    /**
     * Petakan baris sheet WIFI ke kolom inti it_assets.
     *
     * Sudah diverifikasi terhadap file Excel asli. Header sheet WIFI dua
     * baris: baris pertama "No, TYPE, SN, LOCATION, Access, -, -, PASSWORD
     * WIFI", baris kedua (sub-header) "-, -, -, -, IP Address, SSID, Pass,
     * jan-mar, ...". Hanya baris pertama yang terbaca sebagai header
     * (readExcel tidak menggabung dua baris header), sehingga:
     * - TYPE  -> model perangkat WiFi.
     * - SN    -> serial number asli (jarang terisi, tapi bukan lokasi).
     * - LOCATION -> deskripsi lokasi fisik perangkat.
     * - Access (kolom ke-5, sebenarnya berlabel "IP Address" di baris
     *   sub-header) -> selalu kosong pada data sumber, tetap dipetakan ke
     *   ip_address untuk jaga-jaga bila suatu saat diisi.
     * - Kolom SSID/Pass ada di sub-header (baris kedua) sehingga tidak
     *   ikut terbaca oleh readExcel/buildColMap; sengaja tidak
     *   diikutsertakan karena berisi kredensial WiFi (tidak disimpan di
     *   luar konteks yang lebih aman).
     * Sheet ini tidak memiliki kolom IP yang konsisten terisi, jadi import
     * dipanggil dengan requireIp: false.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformWifiRow(array $mapped): array
    {
        $core = [
            'model'         => $mapped['type'] ?? null,
            'serial_number' => $mapped['sn'] ?? null,
            'location'      => $mapped['location'] ?? null,
            'ip_address'    => $mapped['access'] ?? null,
        ];

        return [$core, []];
    }

    /**
     * Petakan baris sheet MODUL JARINGAN ke kolom inti it_assets. Lokasi
     * diambil dari kolom Lokasi, fallback ke Location bila Lokasi kosong.
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformNetworkRow(array $mapped): array
    {
        // Kolom "Lokasi" adalah nama spesifik perangkat (mis. "Radio POS 1 NPA"),
        // sedangkan kolom "Perangkat" adalah kategori generik (Radio, Switch).
        // tag_name memakai nama spesifik agar tiap perangkat unik.
        $core = [
            'tag_name'   => $mapped['lokasi'] ?? null,
            'model'      => $mapped['model'] ?? null,
            'location'   => $mapped['location'] ?? null,
            'ip_address' => $mapped['ip'] ?? null,
        ];

        $spec = $this->filterSpec([
            'perangkat' => $mapped['perangkat'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Petakan baris sheet UPS ke kolom inti it_assets dan kolom spec.
     * Kolom "Brand & Model" disimpan apa adanya ke kolom brand karena
     * tidak bisa dipisah brand/model secara aman dari data mentah.
     *
     * CATATAN: sheet UPS tidak memiliki kolom IP di sumber data (UPS bukan
     * perangkat berjaringan), sehingga import UPS dipanggil dengan
     * requireIp: false pada importSheet() -- lihat method import().
     *
     * @param  array<string, string|null>  $mapped
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function transformUpsRow(array $mapped): array
    {
        $core = [
            'plant'         => $mapped['pt'] ?? null,
            'department'    => $mapped['dept'] ?? null,
            'location'      => $mapped['location'] ?? null,
            'brand'         => $mapped['brandmodel'] ?? null,
            'serial_number' => $mapped['sn'] ?? null,
        ];

        $spec = $this->filterSpec([
            'capacity'   => $mapped['capacity'] ?? null,
            'penggunaan' => $mapped['penggunaan'] ?? null,
            'header'     => $mapped['header'] ?? null,
        ]);

        return [$core, $spec];
    }

    /**
     * Simpan atau perbarui satu record it_assets. Duplikat dicek dari
     * device_type + serial_number, lalu device_type + tag_name jika
     * serial_number kosong.
     *
     * @param  string  $deviceType
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $spec
     * @return bool  true jika record baru dibuat, false jika record lama diperbarui
     */
    private function upsertItAsset(string $deviceType, array $core, array $spec): bool
    {
        $serial   = $core['serial_number'] ?? null;
        $tagName  = $core['tag_name'] ?? null;
        $ip       = $core['ip_address'] ?? null;
        $existing = $this->findExistingItAsset($deviceType, $serial, $tagName, $ip);

        $payload = array_filter($core, fn ($value) => $value !== null && $value !== '');
        $payload['device_type']  = $deviceType;
        $payload['spec']         = $spec;
        $payload['data_source']  = 'import_excel';
        $payload['imported_at']  = now();

        if ($existing) {
            $existing->update($payload);
            return false;
        }

        ItAsset::create($payload);
        return true;
    }

    /**
     * Cari record it_assets yang sudah ada. Urutan prioritas kunci identitas:
     * IP (paling unik untuk perangkat berjaringan), lalu serial_number, lalu
     * tag_name (last resort karena bisa berisi model generik).
     *
     * @param  string       $deviceType
     * @param  string|null  $serial
     * @param  string|null  $tagName
     * @param  string|null  $ip
     * @return ItAsset|null
     */
    private function findExistingItAsset(string $deviceType, ?string $serial, ?string $tagName, ?string $ip): ?ItAsset
    {
        if (!empty($ip)) {
            $found = ItAsset::where('device_type', $deviceType)
                ->where('ip_address', $ip)
                ->first();

            if ($found) {
                return $found;
            }
        }

        if (!empty($serial)) {
            $found = ItAsset::where('device_type', $deviceType)
                ->where('serial_number', $serial)
                ->first();

            if ($found) {
                return $found;
            }
        }

        if (!empty($tagName)) {
            return ItAsset::where('device_type', $deviceType)
                ->where('tag_name', $tagName)
                ->first();
        }

        return null;
    }

    /**
     * Bangun peta kolom berdasarkan NAMA header (bukan indeks tetap), agar
     * urutan kolom antar file Excel yang sedikit berbeda tetap terbaca.
     * Normalisasi: lowercase lalu buang semua karakter selain huruf dan
     * angka (spasi, "/", "-", "." dibuang), mengikuti pola
     * ImportService::buildColMap.
     *
     * @param  array<int, mixed>  $headers
     * @return array<string, int>
     */
    private function buildColMap(array $headers): array
    {
        $colMap = [];

        foreach ($headers as $index => $header) {
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $header));

            if ($key === '' || isset($colMap[$key])) {
                continue;
            }

            $colMap[$key] = $index;
        }

        return $colMap;
    }

    /**
     * Ambil nilai satu baris Excel berdasarkan peta kolom, dikembalikan
     * sebagai array asosiatif key normalisasi header => nilai sel (trim).
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $colMap
     * @return array<string, string|null>
     */
    private function mapRow(array $row, array $colMap): array
    {
        $mapped = [];

        foreach ($colMap as $key => $index) {
            $value = $row[$index] ?? null;
            $value = $value !== null ? trim((string) $value) : null;

            // Beberapa sheet (terbukti di kolom IP sheet PRINTER) memakai
            // "-" sebagai placeholder "tidak ada data". Tanpa normalisasi
            // ini, "-" dianggap nilai valid sehingga banyak baris berbeda
            // memakai ip_address yang sama persis ("-") dan saling
            // menimpa lewat dedup upsertItAsset(). Normalisasi ke null di
            // sini berlaku untuk semua sheet agar konsisten.
            if ($value === '-' || $value === '') {
                $value = null;
            }

            $mapped[$key] = $value;
        }

        return $mapped;
    }

    /**
     * Ambil nilai pertama yang tidak kosong dari beberapa kemungkinan key,
     * dipakai untuk kolom yang punya lebih dari satu sumber (mis. Letak
     * atau Lokasi, IP atau IP HOST).
     *
     * @param  array<string, string|null>  $mapped
     * @param  array<int, string>  $keys
     * @return string|null
     */
    private function firstNonEmpty(array $mapped, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!empty($mapped[$key])) {
                return $mapped[$key];
            }
        }

        return null;
    }

    /**
     * Buang entry spec yang kosong supaya kolom JSON spec tidak dipenuhi
     * nilai null/string kosong.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function filterSpec(array $spec): array
    {
        return array_filter($spec, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Cek apakah kolom inti dan spec hasil transform sama sekali tidak
     * berisi data (dipakai untuk device_type non-jaringan seperti UPS,
     * yang tidak punya kolom IP sebagai penentu baris valid).
     *
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $spec
     * @return bool
     */
    private function isEffectivelyEmpty(array $core, array $spec): bool
    {
        $coreHasValue = collect($core)->contains(fn ($value) => $value !== null && $value !== '');
        $specHasValue = collect($spec)->contains(fn ($value) => $value !== null && $value !== '');

        return !$coreHasValue && !$specHasValue;
    }
}

