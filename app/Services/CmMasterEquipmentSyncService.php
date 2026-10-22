<?php

namespace App\Services;

use App\Models\CmEquipment;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * CmMasterEquipmentSyncService
 *
 * Menjadikan sheet "Master Equipment" pada spreadsheet Data CM sebagai
 * sumber kebenaran daftar equipment yang dimonitoring.
 *
 * Latar (permintaan user 21/10/2026): user terus memperbarui sheet
 * "Master Equipment" (dan "Monitoring Bulanan") di spreadsheet Data CM —
 * tagging baru ditambahkan, tagging yang tidak perlu dimonitoring dihapus.
 * Tanpa langkah ini, equipment lama tetap tersimpan di `cm_equipment` dan
 * terus muncul di kartu/modal "belum diambil data" meskipun sudah dihapus
 * dari spreadsheet (contoh: CT PUMP 1 & 2 di PT. CES).
 *
 * Aturan yang dipakai:
 *   1. Tag di sheet Master Equipment  -> equipment dibuat / diperbarui,
 *      `in_master` = true, dan diaktifkan kembali bila sebelumnya nonaktif.
 *   2. Tag yang TADINYA ada di master (`in_master` = true) lalu hilang dari
 *      sheet -> dinonaktifkan (`is_active` = false). Histori reading dan
 *      finding TIDAK dihapus, hanya disembunyikan dari halaman monitoring.
 *   3. Tag yang belum pernah ada di master (dibuat otomatis dari baris
 *      "Data AppSheet" sebelum user menambahkannya ke master) TIDAK
 *      dinonaktifkan, supaya equipment baru tidak "hilang" di sync pertama.
 *   4. Perbandingan tag tidak membedakan huruf besar/kecil dan spasi ganda
 *      ("SC-Rework A" di database = "SC-REWORK A" di master), sehingga tidak
 *      muncul duplikat hanya karena beda penulisan.
 *   5. Bila sheet kosong / barisnya terlalu sedikit (kemungkinan sedang
 *      diisi atau terpotong), deaktivasi dilewati agar tidak mematikan
 *      seluruh daftar equipment.
 */
class CmMasterEquipmentSyncService
{
    /**
     * Jumlah baris master minimal sebelum deaktivasi dianggap aman.
     * Di bawah angka ini, equipment hanya ditambah/diperbarui — tidak ada
     * yang dinonaktifkan.
     */
    public const MIN_ROWS_FOR_DEACTIVATION = 10;

    public const SHEET_NAME = 'Master Equipment';

    /**
     * Baca sheet "Master Equipment" dari file xlsx lalu sinkronkan.
     *
     * @param  string $filePath Path file xlsx (hasil download / upload)
     * @return array Ringkasan hasil sinkronisasi
     *
     * @throws RuntimeException Bila sheet/kolom wajib tidak ditemukan
     */
    public function syncFromFile(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::SHEET_NAME]);

        $sheetNames = $reader->listWorksheetNames($filePath);

        if (!in_array(self::SHEET_NAME, $sheetNames, true)) {
            throw new RuntimeException(
                'Sheet "' . self::SHEET_NAME . '" tidak ditemukan di file spreadsheet.'
            );
        }

        $spreadsheet = $reader->load($filePath);
        $sheet       = $spreadsheet->getSheetByName(self::SHEET_NAME) ?? $spreadsheet->getActiveSheet();

        // calculateFormulas = false: sheet ini tidak berisi formula, dan
        // menghitung formula memperlambat import secara signifikan.
        $rows = $sheet->toArray(null, false, false, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        return $this->syncRows($rows);
    }

    /**
     * Sinkronkan daftar equipment dengan baris sheet Master Equipment.
     *
     * @param  array<int, array<int, mixed>> $rows Baris mentah sheet
     * @return array Ringkasan hasil sinkronisasi
     */
    public function syncRows(array $rows): array
    {
        [$headerIndex, $colTag, $colPt, $colPlant] = $this->detectColumns($rows);

        $master   = [];
        $duplikat = [];

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i] ?? [];
            $tag = $this->cleanCell($row[$colTag] ?? null);
            $tag = $tag !== '' ? preg_replace('/\s+/u', ' ', $tag) : '';

            if ($tag === '') {
                continue;
            }

            $key = $this->normalizeTag($tag);

            if (isset($master[$key])) {
                $duplikat[] = $tag;
                continue;
            }

            $master[$key] = [
                'tag'   => $tag,
                'pt'    => $colPt !== null ? $this->cleanCell($row[$colPt] ?? null) : '',
                'plant' => $colPlant !== null ? $this->cleanCell($row[$colPlant] ?? null) : '',
            ];
        }

        if (count($master) === 0) {
            throw new RuntimeException(
                'Sheet "' . self::SHEET_NAME . '" tidak berisi satu pun Equipment Tag.'
            );
        }

        $equipments = CmEquipment::query()->get();
        $dbByKey    = [];

        foreach ($equipments as $equipment) {
            $dbByKey[$this->normalizeTag($equipment->equipment_tag)][] = $equipment;
        }

        $dibuat        = [];
        $diperbarui    = [];
        $diaktifkan    = [];
        $dinonaktifkan = [];

        foreach ($master as $key => $row) {
            $existing = $dbByKey[$key] ?? [];

            if ($existing === []) {
                $equipment = CmEquipment::create([
                    'equipment_tag' => $row['tag'],
                    'pt_location'   => $row['pt'] !== '' ? $row['pt'] : 'Unknown',
                    'plant'         => $row['plant'] !== '' ? $row['plant'] : 'Unknown',
                    'is_active'     => true,
                    'in_master'     => true,
                ]);

                $dbByKey[$key] = [$equipment];
                $dibuat[]      = $equipment->equipment_tag;
                continue;
            }

            foreach ($existing as $equipment) {
                $changes = [];

                if ($row['pt'] !== '' && $equipment->pt_location !== $row['pt']) {
                    $changes['pt_location'] = $row['pt'];
                }
                if ($row['plant'] !== '' && $equipment->plant !== $row['plant']) {
                    $changes['plant'] = $row['plant'];
                }
                if (!$equipment->in_master) {
                    $changes['in_master'] = true;
                }
                if (!$equipment->is_active) {
                    $changes['is_active']      = true;
                    $changes['deactivated_at'] = null;
                    $diaktifkan[]              = $equipment->equipment_tag;
                }

                if ($changes !== []) {
                    $equipment->update($changes);
                    $diperbarui[] = $equipment->equipment_tag;
                }
            }
        }

        $deaktivasiDilewati = count($master) < self::MIN_ROWS_FOR_DEACTIVATION;

        if (!$deaktivasiDilewati) {
            foreach ($equipments as $equipment) {
                if (isset($master[$this->normalizeTag($equipment->equipment_tag)])) {
                    continue;
                }

                // Belum pernah ada di master -> equipment baru dari input
                // reading, jangan dimatikan sebelum user menambahkannya.
                if (!$equipment->in_master) {
                    continue;
                }

                $equipment->update([
                    'in_master'      => false,
                    'is_active'      => false,
                    'deactivated_at' => $equipment->deactivated_at ?? now(),
                ]);

                $dinonaktifkan[] = $equipment->equipment_tag;
            }
        }

        $summary = [
            'total_master'        => count($master),
            'dibuat'              => count($dibuat),
            'diperbarui'          => count(array_values(array_unique($diperbarui))),
            'diaktifkan'          => count(array_values(array_unique($diaktifkan))),
            'dinonaktifkan'       => count($dinonaktifkan),
            'tags_dibuat'         => array_values($dibuat),
            'tags_diaktifkan'     => array_values(array_unique($diaktifkan)),
            'tags_dinonaktifkan'  => array_values($dinonaktifkan),
            'duplikat'            => array_values($duplikat),
            'deaktivasi_dilewati' => $deaktivasiDilewati,
            'batas_minimal_baris' => self::MIN_ROWS_FOR_DEACTIVATION,
        ];

        Log::info('Sinkronisasi Master Equipment CM selesai', $summary);

        return $summary;
    }

    /**
     * Cari baris header dan kolom PT Location / Plant / Equipment Tag.
     *
     * @param  array<int, array<int, mixed>> $rows
     * @return array{0:int,1:int,2:?int,3:?int}
     *
     * @throws RuntimeException Bila kolom Equipment Tag tidak ditemukan
     */
    private function detectColumns(array $rows): array
    {
        $colTag = $colPt = $colPlant = null;
        $headerIndex = null;

        foreach (array_slice($rows, 0, 15, true) as $index => $row) {
            foreach ($row as $column => $value) {
                $header = strtolower(trim((string) $value));

                if ($header === 'equipment tag') {
                    $headerIndex = (int) $index;
                    $colTag      = (int) $column;
                } elseif (in_array($header, ['pt location', 'pt'], true)) {
                    $colPt = (int) $column;
                } elseif ($header === 'plant') {
                    $colPlant = (int) $column;
                }
            }

            if ($headerIndex !== null) {
                break;
            }
        }

        if ($headerIndex === null || $colTag === null) {
            throw new RuntimeException(
                'Kolom "Equipment Tag" tidak ditemukan di sheet "' . self::SHEET_NAME . '".'
            );
        }

        return [$headerIndex, $colTag, $colPt, $colPlant];
    }

    /**
     * Normalisasi tag untuk perbandingan: buang spasi depan/belakang,
     * rapikan spasi ganda, dan samakan huruf besar/kecil.
     */
    private function normalizeTag(?string $tag): string
    {
        $tag = preg_replace('/\s+/u', ' ', trim((string) $tag));

        return mb_strtoupper($tag, 'UTF-8');
    }

    /**
     * Ambil nilai sel sebagai teks bersih.
     */
    private function cleanCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
