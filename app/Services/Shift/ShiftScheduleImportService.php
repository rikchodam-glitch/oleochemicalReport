<?php

namespace App\Services\Shift;

use App\Models\ShiftSchedule;
use App\Models\Technician;
use App\Models\TechnicianShift;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Import file jadwal shift maintenance (Excel) ke tabel technician_shifts.
 *
 * Struktur file yang didukung: satu sheet berisi beberapa blok tabel, masing
 * masing dengan baris judul ("SHIFT SCHEDULE ... periode <tgl> <bulan> -
 * <tgl> <bulan> <tahun>"), baris header "NAME ...", baris nama hari, baris
 * tanggal, lalu baris data (No | NIK | Nama | Group | kode shift per tanggal).
 * Kode shift: x = OFF, 1/2/3 = Shift 1/2/3, REGULER = teknisi non-shift.
 */
class ShiftScheduleImportService
{
    /**
     * Nama bulan (Indonesia) untuk membaca periode dari judul file.
     */
    private const BULAN = [
        'januari'   => 1,
        'februari'  => 2,
        'maret'     => 3,
        'april'     => 4,
        'mei'       => 5,
        'juni'      => 6,
        'juli'      => 7,
        'agustus'   => 8,
        'september' => 9,
        'oktober'   => 10,
        'november'  => 11,
        'desember'  => 12,
    ];

    /**
     * Import file jadwal shift.
     *
     * @param  string   $path    Path absolut file Excel jadwal
     * @param  int|null $userId  ID user pengunggah (nullable)
     * @param  bool     $dryRun  True = hanya baca & laporkan tanpa menyimpan
     * @return array{
     *     periode_mulai: ?string,
     *     periode_selesai: ?string,
     *     jumlah_baris: int,
     *     jumlah_terpetakan: int,
     *     jumlah_tidak_cocok: int,
     *     jumlah_baris_shift: int,
     *     detail_tidak_cocok: array<int, string>,
     *     dicocokkan_lewat_nama: array<int, string>,
     *     per_section: array<string, int>,
     *     per_kode: array<string, int>
     * }
     */
    public function import(string $path, ?int $userId = null, bool $dryRun = false): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('File jadwal tidak ditemukan: ' . $path);
        }

        $reader     = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $namaSheet  = $reader->listWorksheetNames($path);
        $sheetPilih = in_array('MTC', $namaSheet, true) ? 'MTC' : ($namaSheet[0] ?? null);

        if ($sheetPilih === null) {
            throw new RuntimeException('File jadwal tidak memiliki sheet yang bisa dibaca.');
        }

        // Load satu sheet saja agar hemat memori.
        $reader->setLoadSheetsOnly([$sheetPilih]);
        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getSheetByName($sheetPilih) ?? $spreadsheet->getSheet(0);

        $barisMaks = $sheet->getHighestRow();
        $kolomMaks = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $nilai = function (int $baris, int $kolom) use ($sheet): string {
            $isi = $sheet->getCell([$kolom, $baris])->getValue();

            return trim((string) ($isi ?? ''));
        };

        // Peta NIK/nama teknisi untuk pencocokan tanpa query berulang.
        $teknisiAll = Technician::get(['id', 'nik', 'name']);
        $petaNik    = [];
        $petaNama   = [];

        foreach ($teknisiAll as $t) {
            if ($t->nik !== null && trim((string) $t->nik) !== '') {
                $petaNik[strtoupper(trim((string) $t->nik))] = $t->id;
            }

            $petaNama[strtoupper(trim((string) $t->name))] = $t->id;
        }

        $section     = '';
        $petaTanggal = [];
        $bulanMulai  = null;
        $tahun       = (int) date('Y');

        $barisData        = [];
        $tidakCocok       = [];
        $lewatNama        = [];
        $perSection       = [];
        $perKode          = [];
        $jumlahBaris      = 0;
        $jumlahTerpetakan = 0;
        $tanggalSemua     = [];

        for ($r = 1; $r <= $barisMaks; $r++) {
            $c1 = $nilai($r, 1);
            $c3 = $nilai($r, 3);

            // Judul periode: reset peta tanggal dan baca bulan mulai.
            if (stripos($c1, 'SHIFT SCHEDULE') === 0) {
                [$bulanMulai, $tahun] = $this->parsePeriode($c1, $tahun);
                $petaTanggal = [];

                continue;
            }

            // Judul blok tabel: "NAME STAF" / "NAME OS" / "NAME SHIFT".
            if (stripos($c3, 'NAME') === 0) {
                $section     = $c3;
                $petaTanggal = [];

                continue;
            }

            // Baris tanggal (kolom 5 berisi angka hari).
            if ($petaTanggal === [] && ctype_digit($nilai($r, 5))) {
                $petaTanggal = $this->bangunPetaTanggal($nilai, $r, $kolomMaks, $bulanMulai ?? 1, $tahun);

                continue;
            }

            $nik  = $nilai($r, 2);
            $nama = $c3;

            if (!ctype_digit($c1) || $nik === '' || $nama === '' || $petaTanggal === []) {
                continue;
            }

            $kode = [];

            foreach ($petaTanggal as $kolom => $tanggal) {
                $kode[$tanggal] = $this->normalisasiKode($nilai($r, $kolom));
            }

            // Blok staf/reguler hanya menulis "REGULER" pada kolom tanggal pertama.
            if (count(array_filter($kode, fn (string $k): bool => $k !== '')) <= 1) {
                $kode = array_fill_keys(array_keys($kode), TechnicianShift::KODE_REGULER);
            }

            [$technicianId, $metode] = $this->cariTeknisi($petaNik, $petaNama, $nik, $nama);

            $labelSection = $section !== '' ? $section : '(tanpa section)';
            $perSection[$labelSection] = ($perSection[$labelSection] ?? 0) + 1;

            $jumlahBaris++;
            $tanggalSemua = array_merge($tanggalSemua, array_keys($kode));

            if ($technicianId !== null) {
                $jumlahTerpetakan++;
            } else {
                $tidakCocok[] = sprintf('%s (%s) - %s', $nama, $nik, $labelSection);
            }

            if ($metode === 'nama') {
                $lewatNama[] = sprintf('%s (%s) dicocokkan lewat nama karena NIK berbeda', $nama, $nik);
            }

            $groupCode = $nilai($r, 4);

            foreach ($kode as $tanggal => $kodeShift) {
                if ($kodeShift === '') {
                    continue;
                }

                $perKode[$kodeShift] = ($perKode[$kodeShift] ?? 0) + 1;

                $barisData[] = [
                    'technician_id' => $technicianId,
                    'nik'           => $nik,
                    'nama'          => $nama,
                    'group_code'    => $groupCode !== '' ? $groupCode : null,
                    'section_label' => $labelSection,
                    'shift_date'    => $tanggal,
                    'shift_code'    => $kodeShift,
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        gc_collect_cycles();

        $periodeMulai   = $tanggalSemua !== [] ? min($tanggalSemua) : null;
        $periodeSelesai = $tanggalSemua !== [] ? max($tanggalSemua) : null;

        if (!$dryRun) {
            $schedule = ShiftSchedule::create([
                'periode_mulai'      => $periodeMulai,
                'periode_selesai'    => $periodeSelesai,
                'nama_file'          => basename($path),
                'jumlah_baris'       => $jumlahBaris,
                'jumlah_terpetakan'  => $jumlahTerpetakan,
                'jumlah_tidak_cocok' => count($tidakCocok),
                'detail_tidak_cocok' => array_merge($tidakCocok, $lewatNama),
                'diupload_oleh'      => $userId,
            ]);

            $sekarang = now();

            foreach ($barisData as $index => $baris) {
                $barisData[$index]['shift_schedule_id'] = $schedule->id;
                $barisData[$index]['created_at']        = $sekarang;
                $barisData[$index]['updated_at']        = $sekarang;
            }

            foreach (array_chunk($barisData, 500) as $potongan) {
                TechnicianShift::upsert(
                    $potongan,
                    ['nik', 'shift_date'],
                    ['technician_id', 'nama', 'group_code', 'section_label', 'shift_code', 'shift_schedule_id', 'updated_at']
                );
            }
        }

        Log::info('ShiftScheduleImport: selesai', [
            'file'         => basename($path),
            'dry_run'      => $dryRun,
            'jumlah_baris' => $jumlahBaris,
            'terpetakan'   => $jumlahTerpetakan,
            'tidak_cocok'  => count($tidakCocok),
            'baris_shift'  => count($barisData),
            'periode'      => $periodeMulai . ' s/d ' . $periodeSelesai,
        ]);

        return [
            'periode_mulai'         => $periodeMulai,
            'periode_selesai'       => $periodeSelesai,
            'jumlah_baris'          => $jumlahBaris,
            'jumlah_terpetakan'     => $jumlahTerpetakan,
            'jumlah_tidak_cocok'    => count($tidakCocok),
            'jumlah_baris_shift'    => count($barisData),
            'detail_tidak_cocok'    => $tidakCocok,
            'dicocokkan_lewat_nama' => $lewatNama,
            'per_section'           => $perSection,
            'per_kode'              => $perKode,
        ];
    }

    /**
     * Bangun peta kolom => tanggal (Y-m-d) dari baris tanggal jadwal.
     *
     * @param  callable $nilai     Fungsi pembaca sel: fn(int $baris, int $kolom): string
     * @param  int      $baris     Nomor baris tanggal
     * @param  int      $kolomMaks Nomor kolom terakhir sheet
     * @param  int      $bulan     Bulan awal (dari judul periode)
     * @param  int      $tahun     Tahun periode
     * @return array<int, string>  Peta kolom => tanggal Y-m-d
     */
    private function bangunPetaTanggal(
        callable $nilai,
        int $baris,
        int $kolomMaks,
        int $bulan,
        int $tahun
    ): array {
        $peta       = [];
        $sebelumnya = 0;

        for ($kolom = 5; $kolom <= $kolomMaks; $kolom++) {
            $isi = $nilai($baris, $kolom);

            if ($isi === '' || !ctype_digit($isi)) {
                continue;
            }

            $hari = (int) $isi;

            // Hari mengecil = sudah masuk bulan berikutnya.
            if ($hari < $sebelumnya) {
                $bulan++;

                if ($bulan > 12) {
                    $bulan = 1;
                    $tahun++;
                }
            }

            $peta[$kolom] = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
            $sebelumnya   = $hari;
        }

        return $peta;
    }

    /**
     * Normalisasi kode shift dari sel jadwal.
     *
     * @param  string $nilai Isi sel apa adanya
     * @return string        Kode shift: 'x', '1', '2', '3', 'R', atau kode asli huruf besar
     */
    private function normalisasiKode(string $nilai): string
    {
        $nilai = strtoupper(trim($nilai));

        if ($nilai === '') {
            return '';
        }

        if (in_array($nilai, ['X', 'OFF', 'O', 'L'], true)) {
            return TechnicianShift::KODE_OFF;
        }

        if (in_array($nilai, ['1', '2', '3'], true)) {
            return $nilai;
        }

        if ($nilai === 'R' || str_starts_with($nilai, 'REGULER')) {
            return TechnicianShift::KODE_REGULER;
        }

        return $nilai;
    }

    /**
     * Cari teknisi dari NIK (utama) atau nama (cadangan) memakai peta yang
     * sudah dimuat sekali di awal import.
     *
     * @param  array<string, int> $petaNik  Peta NIK huruf besar => ID teknisi
     * @param  array<string, int> $petaNama Peta nama huruf besar => ID teknisi
     * @param  string             $nik      NIK dari jadwal
     * @param  string             $nama     Nama dari jadwal
     * @return array{0: ?int, 1: ?string}   [ID teknisi, metode pencocokan]
     */
    private function cariTeknisi(array $petaNik, array $petaNama, string $nik, string $nama): array
    {
        $kunciNik = strtoupper(trim($nik));

        if ($kunciNik !== '' && isset($petaNik[$kunciNik])) {
            return [$petaNik[$kunciNik], 'nik'];
        }

        $kunciNama = strtoupper(trim($nama));

        if ($kunciNama !== '' && isset($petaNama[$kunciNama])) {
            return [$petaNama[$kunciNama], 'nama'];
        }

        return [null, null];
    }

    /**
     * Baca bulan & tahun periode dari judul blok jadwal.
     *
     * @param  string $judul        Teks judul, mis. "... periode 26 September - 25 Oktober 2026"
     * @param  int    $tahunDefault Tahun cadangan bila judul tidak memuat tahun
     * @return array{0: ?int, 1: int} [bulan mulai, tahun]
     */
    private function parsePeriode(string $judul, int $tahunDefault): array
    {
        if (preg_match('/periode\s+\d{1,2}\s+([A-Za-z]+)\s*-\s*\d{1,2}\s+([A-Za-z]+)\s+(\d{4})/i', $judul, $cocok) === 1) {
            return [self::BULAN[strtolower($cocok[1])] ?? null, (int) $cocok[3]];
        }

        return [null, $tahunDefault];
    }
}
