<?php

namespace App\Services\Sparepart;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Pembaca lima berkas sumber Integrasi PR/PO Spare Part.
 *
 * Lima sumbernya:
 * 1. SparePart_Monitoring.csv  - stok minimum & stok tersedia per material (input manual).
 * 2. G001_clean.csv            - stok gudang aktual per SLoc.
 * 3. EXPORT PR.XLSX            - daftar permintaan pembelian (SAP ME5A).
 * 4. EXPORT PO.XLSX            - daftar pesanan pembelian (SAP ME2M).
 * 5. EXPORT MRP.XLSX           - plant stock, safety stock, reorder point (SAP MD07).
 *
 * Seluruh pembacaan XLSX memakai `setReadDataOnly(true)` dan kolom dikenali
 * dari NAMA JUDUL (bukan posisi), supaya revisi berkas SAP yang menggeser
 * urutan kolom tidak merusak hasil import.
 */
class SparepartReader
{
    /**
     * Pola kode material yang dianggap sah (angka minimal 6 digit).
     */
    public const POLA_KODE = '/^\d{6,}$/';

    /**
     * Judul kolom yang dibutuhkan pada EXPORT PR.XLSX.
     *
     * @var array<int, string>
     */
    public const KOLOM_PR = [
        'Processing status',
        'Deletion Indicator',
        'Purchase order',
        'Material',
        'Quantity requested',
        'Delivery Date',
        'Requisitioner',
        'Purchasing Group',
    ];

    /**
     * Judul kolom yang dibutuhkan pada EXPORT PO.XLSX.
     *
     * @var array<int, string>
     */
    public const KOLOM_PO = [
        'Purchasing Document',
        'Material',
        'Supplier/Supplying Plant',
        'Order Quantity',
        'Deletion Indicator',
        'Our Reference',
        'Document Date',
        'Plant',
    ];

    /**
     * Judul kolom yang dibutuhkan pada EXPORT MRP.XLSX.
     *
     * @var array<int, string>
     */
    public const KOLOM_MRP = [
        'Material',
        'Plnt stock',
        'Safety Stk',
        'Reorder Pt',
    ];

    /**
     * Membaca SparePart_Monitoring.csv (baris pertama = judul).
     *
     * Kolom yang dipakai: 0 Kode Material, 1 Deskripsi, 2 Stok Minimum,
     * 3 Stok Tersedia, 4 Status (memuat kata REORDER), 5 Penanda.
     * Hanya kode material berbentuk angka minimal 6 digit yang disimpan.
     *
     * @param  string $path Path berkas CSV
     * @return array{data: array<string, array<string, mixed>>, baris: int}
     */
    public function bacaMonitoring(string $path): array
    {
        $data = [];
        $baris = 0;

        foreach ($this->bacaCsv($path) as $i => $s) {
            if ($i === 0) {
                continue;
            }

            $kode = self::teks($s[0] ?? '');
            if (! $this->kodeSah($kode)) {
                continue;
            }

            $baris++;
            $data[$kode] = [
                'kode_material'  => $kode,
                'deskripsi'      => mb_substr(self::teks($s[1] ?? ''), 0, 255),
                'stok_min'       => self::angka($s[2] ?? 0),
                'stok_tersedia'  => self::angka($s[3] ?? 0),
                'reorder'        => mb_stripos(self::teks($s[4] ?? ''), 'REORDER') !== false,
                'penanda'        => mb_substr(self::teks($s[5] ?? ''), 0, 100),
            ];
        }

        return ['data' => $data, 'baris' => $baris];
    }

    /**
     * Membaca G001_clean.csv dan menjumlahkan stok per material.
     *
     * Kolom yang dipakai: 0 S.Loc, 1 Kode, 2 Nama Item, 3 Stok Awal,
     * 4 Masuk, 5 Keluar, 6 Sisa Stok, 7 UN.
     *
     * @param  string $path Path berkas CSV
     * @return array{data: array<string, array<string, mixed>>, baris: int}
     */
    public function bacaG001(string $path): array
    {
        $data = [];
        $baris = 0;

        foreach ($this->bacaCsv($path) as $i => $s) {
            if ($i === 0) {
                continue;
            }

            $kode = self::teks($s[1] ?? '');
            if ($kode === '') {
                continue;
            }

            $baris++;
            $g = $data[$kode] ?? [
                'kode_material' => $kode,
                'nama_item'     => '',
                'sloc'          => [],
                'satuan'        => [],
                'baris'         => 0,
                'stok_awal'     => 0.0,
                'masuk'         => 0.0,
                'keluar'        => 0.0,
                'sisa'          => 0.0,
            ];

            $g['baris']++;
            $g['stok_awal'] += self::angka($s[3] ?? 0);
            $g['masuk']     += self::angka($s[4] ?? 0);
            $g['keluar']    += self::angka($s[5] ?? 0);
            $g['sisa']      += self::angka($s[6] ?? 0);

            if ($g['nama_item'] === '') {
                $g['nama_item'] = mb_substr(self::teks($s[2] ?? ''), 0, 255);
            }

            $satuan = self::teks($s[7] ?? '');
            if ($satuan !== '' && ! in_array($satuan, $g['satuan'], true)) {
                $g['satuan'][] = $satuan;
            }

            $sloc = self::teks($s[0] ?? '');
            if ($sloc !== '' && ! in_array($sloc, $g['sloc'], true)) {
                $g['sloc'][] = $sloc;
            }

            $data[$kode] = $g;
        }

        return ['data' => $data, 'baris' => $baris];
    }

    /**
     * Membaca EXPORT PR.XLSX (SAP ME5A).
     *
     * Baris dokumen dikenali dari sel A yang dimulai dengan
     * "Purchase Requisition"; nomor PR diambil dari SISA teks sel itu dan
     * berlaku sampai baris dokumen berikutnya. Baris item hanya diproses bila
     * sel A berbentuk angka dan kolom Material terisi.
     *
     * @param  string $path Path berkas XLSX
     * @return array{data: array<string, array<int, array<string, mixed>>>, baris: int, nomor: int}
     */
    public function bacaPr(string $path): array
    {
        $rows = $this->bacaSheet($path);
        $kolom = $this->petaKolom($rows[0] ?? [], self::KOLOM_PR);

        $data = [];
        $nomor = [];
        $baris = 0;
        $prNo = '';

        foreach ($rows as $i => $r) {
            if ($i === 0) {
                continue;
            }

            $selA = self::teks($r[0] ?? '');
            if (stripos($selA, 'Purchase Requisition') === 0) {
                $prNo = trim(substr($selA, strlen('Purchase Requisition')));
                $nomor[$prNo] = true;
                continue;
            }

            if ($selA === '' || ! is_numeric($selA)) {
                continue;
            }

            $material = self::teks($this->sel($r, $kolom, 'Material'));
            if ($material === '') {
                continue;
            }

            $baris++;
            $deletion = self::teks($this->sel($r, $kolom, 'Deletion Indicator'));

            $data[$material][] = [
                'kode_material'      => $material,
                'no_pr'              => mb_substr($prNo, 0, 40),
                'processing_status'  => mb_substr(strtoupper(self::teks($this->sel($r, $kolom, 'Processing status'))), 0, 10),
                'deletion_indicator' => mb_substr($deletion, 0, 20),
                'batal'              => preg_match('/true/i', $deletion) === 1,
                'qty_requested'      => self::angka($this->sel($r, $kolom, 'Quantity requested')),
                'tanggal_kirim'      => self::hari($this->sel($r, $kolom, 'Delivery Date')) ?: null,
                'no_po'              => mb_substr(self::teks($this->sel($r, $kolom, 'Purchase order')), 0, 40),
                'requisitioner'      => mb_substr(self::teks($this->sel($r, $kolom, 'Requisitioner')), 0, 100),
                'purchasing_group'   => mb_substr(self::teks($this->sel($r, $kolom, 'Purchasing Group')), 0, 20),
            ];
        }

        return ['data' => $data, 'baris' => $baris, 'nomor' => count($nomor)];
    }

    /**
     * Membaca EXPORT PO.XLSX (SAP ME2M).
     *
     * Kolom "Still to be delivered" dicari dari AWALAN judulnya karena nama
     * lengkapnya memuat satuan dan bisa berbeda tiap ekspor. Baris tanpa nomor
     * PO atau tanpa material diabaikan.
     *
     * @param  string $path Path berkas XLSX
     * @return array{data: array<string, array<int, array<string, mixed>>>, baris: int, nomor: int}
     */
    public function bacaPo(string $path): array
    {
        $rows = $this->bacaSheet($path);
        $judul = $rows[0] ?? [];
        $kolom = $this->petaKolom($judul, self::KOLOM_PO);
        $iSisa = $this->kolomAwalan($judul, 'still to be delivered');

        $data = [];
        $nomor = [];
        $baris = 0;

        foreach ($rows as $i => $r) {
            if ($i === 0) {
                continue;
            }

            $no = self::teks($this->sel($r, $kolom, 'Purchasing Document'));
            $material = self::teks($this->sel($r, $kolom, 'Material'));
            if ($no === '' || $material === '') {
                continue;
            }

            $baris++;
            $nomor[$no] = true;
            $deletion = self::teks($this->sel($r, $kolom, 'Deletion Indicator'));
            $sisa = $iSisa === null ? null : self::angka($r[$iSisa] ?? 0);

            $data[$material][] = [
                'kode_material'   => $material,
                'no_po'           => mb_substr($no, 0, 40),
                'tanggal_dokumen' => self::hari($this->sel($r, $kolom, 'Document Date')) ?: null,
                'sisa_kirim'      => $sisa,
                'status'          => $deletion !== '' ? 'DELETED' : (($sisa ?? 0) > 0 ? 'OPEN' : 'CLOSED'),
                'vendor'          => mb_substr(self::teks($this->sel($r, $kolom, 'Supplier/Supplying Plant')), 0, 255),
                'our_reference'   => mb_substr(self::teks($this->sel($r, $kolom, 'Our Reference')), 0, 100),
                'plant'           => mb_substr(self::teks($this->sel($r, $kolom, 'Plant')), 0, 20),
                'order_qty'       => self::angka($this->sel($r, $kolom, 'Order Quantity')),
            ];
        }

        return ['data' => $data, 'baris' => $baris, 'nomor' => count($nomor)];
    }

    /**
     * Membaca EXPORT MRP.XLSX (SAP MD07). Baris terakhir untuk satu material
     * yang dipakai bila material muncul lebih dari sekali.
     *
     * @param  string $path Path berkas XLSX
     * @return array{data: array<string, array<string, float>>, baris: int}
     */
    public function bacaMrp(string $path): array
    {
        $rows = $this->bacaSheet($path);
        $kolom = $this->petaKolom($rows[0] ?? [], self::KOLOM_MRP);

        $data = [];
        $baris = 0;

        foreach ($rows as $i => $r) {
            if ($i === 0) {
                continue;
            }

            $material = self::teks($this->sel($r, $kolom, 'Material'));
            if ($material === '') {
                continue;
            }

            $baris++;
            $data[$material] = [
                'kode_material' => $material,
                'plant_stock'   => self::angka($this->sel($r, $kolom, 'Plnt stock')),
                'safety_stock'  => self::angka($this->sel($r, $kolom, 'Safety Stk')),
                'reorder_point' => self::angka($this->sel($r, $kolom, 'Reorder Pt')),
            ];
        }

        return ['data' => $data, 'baris' => $baris];
    }

    /**
     * Membaca seluruh baris satu sheet XLSX (tanpa formula).
     *
     * @param  string $path Path berkas XLSX
     * @param  string $nama Nama sheet (kosong = sheet pertama)
     * @return array<int, array<int, mixed>> Daftar baris berisi daftar sel
     */
    public function bacaSheet(string $path, string $nama = ''): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        if ($nama !== '') {
            $reader->setLoadSheetsOnly([$nama]);
        }

        $spreadsheet = $reader->load($path);
        $rows = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * Membaca berkas CSV menjadi daftar baris.
     *
     * @param  string $path Path berkas CSV
     * @return array<int, array<int, string|null>> Daftar baris hasil str_getcsv
     */
    public function bacaCsv(string $path): array
    {
        $isi = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($isi === false) {
            return [];
        }

        $hasil = [];
        foreach ($isi as $baris) {
            $hasil[] = str_getcsv($baris);
        }

        return $hasil;
    }

    /**
     * Memetakan nama judul (dinormalkan) ke indeks kolom 0-based.
     *
     * @param  array<int, mixed>  $judul Baris judul
     * @param  array<int, string> $cari  Nama judul yang dicari
     * @return array<string, int|null>   Judul -> indeks kolom (null bila tidak ada)
     */
    public function petaKolom(array $judul, array $cari): array
    {
        $peta = [];
        foreach ($judul as $i => $nama) {
            $kunci = $this->normJudul($nama);
            if ($kunci !== '' && ! isset($peta[$kunci])) {
                $peta[$kunci] = $i;
            }
        }

        $hasil = [];
        foreach ($cari as $alias) {
            $hasil[$alias] = $peta[$this->normJudul($alias)] ?? null;
        }

        return $hasil;
    }

    /**
     * Mencari indeks kolom yang judulnya DIMULAI dengan awalan tertentu.
     *
     * @param  array<int, mixed> $judul  Baris judul
     * @param  string            $awalan Awalan judul yang dicari
     * @return int|null                  Indeks kolom (null bila tidak ada)
     */
    public function kolomAwalan(array $judul, string $awalan): ?int
    {
        $awalan = $this->normJudul($awalan);
        foreach ($judul as $i => $nama) {
            if (strpos($this->normJudul($nama), $awalan) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Apakah kode material berbentuk angka minimal 6 digit (mis. 30000009)?
     *
     * @param  string $kode Kode material yang diperiksa
     * @return bool         true bila kode dianggap sah
     */
    public function kodeSah(string $kode): bool
    {
        return preg_match(self::POLA_KODE, $kode) === 1;
    }

    /**
     * Mengambil satu sel berdasarkan peta kolom.
     *
     * @param  array<int, mixed>       $baris Baris sumber
     * @param  array<string, int|null> $kolom Peta judul -> indeks
     * @param  string                  $nama  Judul kolom
     * @return mixed                          Nilai sel (null bila kolom tidak ada)
     */
    private function sel(array $baris, array $kolom, string $nama): mixed
    {
        $i = $kolom[$nama] ?? null;
        if ($i === null) {
            return null;
        }

        return $baris[$i] ?? null;
    }

    /**
     * Menormalkan judul kolom: huruf kecil dan spasi dirapikan.
     *
     * @param  mixed  $nilai Nilai judul
     * @return string        Judul yang sudah dinormalkan
     */
    private function normJudul(mixed $nilai): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim((string) $nilai)) ?? '');
    }

    /**
     * Menjadikan nilai apa pun sebagai teks bersih.
     *
     * @param  mixed  $nilai Nilai sumber
     * @return string        Teks tanpa spasi tepi
     */
    public static function teks(mixed $nilai): string
    {
        return trim((string) $nilai);
    }

    /**
     * Menjadikan nilai apa pun sebagai angka (bukan angka = 0).
     *
     * @param  mixed $nilai Nilai sumber
     * @return float        Nilai angka
     */
    public static function angka(mixed $nilai): float
    {
        return is_numeric($nilai) ? (float) $nilai : 0.0;
    }

    /**
     * Menjadikan tanggal SAP (serial Excel atau teks) sebagai Y-m-d.
     *
     * @param  mixed  $nilai Nilai sel tanggal
     * @return string        Tanggal Y-m-d (string kosong bila tidak terbaca)
     */
    public static function hari(mixed $nilai): string
    {
        if ($nilai instanceof DateTimeInterface) {
            return $nilai->format('Y-m-d');
        }

        $teks = self::teks($nilai);
        if ($teks === '') {
            return '';
        }

        if (is_numeric($teks) && (float) $teks > 20000) {
            return Date::excelToDateTimeObject((float) $teks)->format('Y-m-d');
        }

        $ts = strtotime($teks);

        return $ts === false ? '' : date('Y-m-d', $ts);
    }
}
