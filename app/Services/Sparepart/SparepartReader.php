<?php

namespace App\Services\Sparepart;

use App\Exceptions\SparepartFormatException;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Throwable;

/**
 * Pembaca berkas sumber Integrasi PR/PO Spare Part.
 *
 * Tiga berkas dibaca (nama berkas bebas, isinya yang dikunci):
 * 1. laporan stok gudang (lembar G001, MASUK, dan KELUAR),
 * 2. EXPORT PR (SAP ME5A),
 * 3. EXPORT PO (SAP ME2M).
 *
 * Judul kolom dikenali dari NAMA JUDUL (bukan posisi) dan judul wajib
 * diperiksa lebih dulu; bila ada yang hilang, SparepartFormatException
 * dilempar supaya import dibatalkan sebelum menulis apa pun.
 *
 * Seluruh XLSX dibaca dengan setReadDataOnly(true) dan hanya lembar yang perlu.
 */
class SparepartReader
{
    /** Pola kode material yang dianggap sah (angka minimal 6 digit). */
    public const POLA_KODE = '/^\d{6,}$/';

    /** Judul kolom wajib pada lembar rekap stok gudang (G001). */
    public const JUDUL_STOK = ['S. Loc', 'Kode', 'Nama Item', 'Stok Awal'];

    /**
     * Judul kolom pendukung lembar G001 (boleh tidak ada).
     *
     * "UN" adalah kolom satuan (PCS, SET, L, ...). Kolom ini TIDAK dijadikan
     * wajib supaya laporan stok versi lama tanpa kolom satuan tetap bisa
     * diimpor, tetapi WAJIB dipetakan bila ada: sebelumnya kolom ini tidak
     * ikut dipetakan sehingga pembacaan satuan jatuh ke indeks kolom 0
     * (kolom "S. Loc") dan satuan tiap material berisi kode lokasi
     * (dibuktikan 09/10/2026: g_satuan == g_lokasi untuk semua baris uji).
     * Kecocokan kolom "UN" dengan kolom Satuan dashboard lama sudah
     * diverifikasi: 410 baris sama, 0 beda.
     */
    public const JUDUL_STOK_OPSIONAL = ['UN'];

    /** Judul kolom wajib pada lembar barang masuk. */
    public const JUDUL_MASUK = ['Kode', 'Qty'];

    /** Judul kolom wajib pada lembar barang keluar. */
    public const JUDUL_KELUAR = ['Kode', 'Jumlah'];

    /** Judul kolom wajib pada berkas EXPORT PR (SAP ME5A). */
    public const JUDUL_PR = [
        'Processing status',
        'Deletion Indicator',
        'Purchase order',
        'Material',
        'Quantity requested',
        'Delivery Date',
        'Requisitioner',
        'Purchasing Group',
    ];

    /** Judul kolom wajib pada berkas EXPORT PO (SAP ME2M). */
    public const JUDUL_PO = [
        'Purchasing Document',
        'Supplier/Supplying Plant',
        'Material',
        'Order Quantity',
        'Deletion Indicator',
        'Our Reference',
        'Document Date',
    ];

    /**
     * Judul kolom PO yang dicari dari AWALAN karena nama lengkapnya memuat
     * satuan (mis. "Still to be delivered (qty)").
     */
    public const JUDUL_PO_AWALAN = 'Still to be delivered';

    /** Judul kolom wajib pada berkas EXPORT MRP (SAP MD07/MD04). */
    public const JUDUL_MRP = [
        'Material',
        'Plnt stock',
        'Safety Stk',
        'Reorder Pt',
    ];

    /** Judul kolom pendukung MRP (boleh tidak ada). */
    public const JUDUL_MRP_OPSIONAL = ['Description', 'Unit', 'MRP Type'];

    /** Judul kolom wajib pada berkas daftar pantau (sheet MASTER DATA). */
    public const JUDUL_PANTAU = ['Kode Material'];

    /** Judul kolom pendukung daftar pantau (boleh tidak ada). */
    public const JUDUL_PANTAU_OPSIONAL = ['Deskripsi', 'Jumlah', 'Plant', 'Model', 'Tag No.', 'UOM'];

    /** Batas baris yang dipindai untuk mencari baris judul kolom. */
    private const BATAS_CARI_JUDUL = 30;

    /**
     * Membaca laporan stok gudang: stok awal per S.Loc, barang masuk, dan keluar.
     *
     * Sisa stok TIDAK diambil dari sel formula berkas, melainkan dihitung
     * (stok awal + masuk - keluar) per kode material supaya material yang
     * punya lebih dari satu baris S.Loc tidak dihitung ganda. Cara ini sudah
     * dibandingkan dengan sel "Sisa Stok" berkas nyata (09/10/2026) dan
     * cocok, serta setara dengan sheet StokManual milik pengguna (410
     * material cocok, 0 beda).
     *
     * @param  string $path Path berkas laporan stok gudang (xlsx)
     * @return array<string, mixed> Data per material + rincian baca
     *
     * @throws SparepartFormatException Bila lembar/judul kolom wajib tidak ada
     */
    public function bacaStok(string $path): array
    {
        $daftar  = $this->daftarLembar($path);
        $g001    = $this->cariLembar($daftar, 'G001');
        $masukLm = $this->cariLembar($daftar, 'MASUK');
        $keluarLm = $this->cariLembar($daftar, 'KELUAR');

        $hilang = [];

        if ($g001 === null) {
            $hilang[] = 'lembar G001';
        }

        if ($masukLm === null) {
            $hilang[] = 'lembar MASUK';
        }

        if ($keluarLm === null) {
            $hilang[] = 'lembar KELUAR';
        }

        if ($hilang !== []) {
            throw SparepartFormatException::untukBerkas(
                basename($path),
                $hilang,
                $daftar,
                'Unggah laporan stok gudang yang memuat lembar G001, MASUK, dan KELUAR.'
            );
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$g001, $masukLm, $keluarLm]);
        $buku = $reader->load($path);

        $judul = [];

        try {
            $kolG001 = $this->petaKolomWajib($buku, $g001, self::JUDUL_STOK, $path, $judul, self::JUDUL_STOK_OPSIONAL);
            $kolMas  = $this->petaKolomWajib($buku, $masukLm, self::JUDUL_MASUK, $path, $judul);
            $kolKel  = $this->petaKolomWajib($buku, $keluarLm, self::JUDUL_KELUAR, $path, $judul);

            $masuk  = $this->jumlahKolom($buku, $masukLm, $kolMas, 'Kode', 'Qty');
            $keluar = $this->jumlahKolom($buku, $keluarLm, $kolKel, 'Kode', 'Jumlah');
            $stok   = $this->rangkumStok($buku, $g001, $kolG001);
        } finally {
            $buku->disconnectWorksheets();
        }

        $ganda = 0;

        foreach ($stok['data'] as $kode => $item) {
            $item['masuk']  = (float) ($masuk[$kode] ?? 0.0);
            $item['keluar'] = (float) ($keluar[$kode] ?? 0.0);
            $item['sisa']   = $item['stok_awal'] + $item['masuk'] - $item['keluar'];

            if ($item['baris'] > 1) {
                $ganda++;
            }

            $stok['data'][$kode] = $item;
        }

        $catatan = [];

        if ($ganda > 0) {
            $catatan[] = $ganda . ' material punya lebih dari satu baris S.Loc; barang masuk dan keluar dihitung sekali per material.';
        }

        return [
            'data'      => $stok['data'],
            'baris'     => $stok['baris'] + (int) $masuk['__baris'] + (int) $keluar['__baris'],
            'diabaikan' => $stok['diabaikan'],
            'lembar'    => [$g001, $masukLm, $keluarLm],
            'judul'     => $judul,
            'catatan'   => $catatan,
        ];
    }

    /**
     * Membaca berkas EXPORT PR (SAP ME5A).
     *
     * Nomor PR diambil dari baris dokumen yang sel A-nya dimulai "Purchase
     * Requisition" dan berlaku sampai baris dokumen berikutnya. Baris item
     * hanya diproses bila sel A berbentuk angka dan kolom Material terisi.
     *
     * @param  string $path Path berkas xlsx
     * @return array<string, mixed> Baris per material + rincian baca
     *
     * @throws SparepartFormatException Bila judul kolom wajib tidak ada
     */
    public function bacaPr(string $path): array
    {
        $lembar = $this->daftarLembar($path)[0] ?? '';
        $rows   = $this->bacaLembar($path, $lembar);
        $judul  = [];

        [$barisJudul, $kolom] = $this->cariJudul($rows, self::JUDUL_PR, [], basename($path), $judul);

        $data      = [];
        $nomor     = [];
        $baris     = 0;
        $diabaikan = 0;
        $prNo      = '';

        foreach ($rows as $i => $r) {
            if ($i <= $barisJudul) {
                continue;
            }

            $selA = self::teks($r[0] ?? '');

            if (stripos($selA, 'Purchase Requisition') === 0) {
                $prNo = trim(substr($selA, strlen('Purchase Requisition')));
                $nomor[$prNo] = true;
                continue;
            }

            if ($selA === '' || ! is_numeric($selA)) {
                $diabaikan++;
                continue;
            }

            $material = self::teks($this->sel($r, $kolom, 'Material'));

            if ($material === '') {
                $diabaikan++;
                continue;
            }

            $deletion = self::teks($this->sel($r, $kolom, 'Deletion Indicator'));
            $baris++;

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

        return [
            'data'      => $data,
            'baris'     => $baris,
            'diabaikan' => $diabaikan,
            'dokumen'   => count($nomor),
            'lembar'    => [$lembar],
            'judul'     => $judul,
            'catatan'   => $prNo === '' ? ['Tidak ada baris Purchase Requisition yang terbaca; kolom No PR akan kosong.'] : [],
        ];
    }

    /**
     * Membaca berkas EXPORT PO (SAP ME2M).
     *
     * Kolom sisa kirim dicari dari AWALAN judulnya ("Still to be delivered")
     * karena nama lengkapnya memuat satuan (mis. "(qty)"). Baris tanpa nomor
     * PO atau tanpa material diabaikan.
     *
     * @param  string $path Path berkas xlsx
     * @return array<string, mixed> Baris per material + rincian baca
     *
     * @throws SparepartFormatException Bila judul kolom wajib tidak ada
     */
    public function bacaPo(string $path): array
    {
        $lembar = $this->daftarLembar($path)[0] ?? '';
        $rows   = $this->bacaLembar($path, $lembar);
        $judul  = [];

        [$barisJudul, $kolom] = $this->cariJudul($rows, self::JUDUL_PO, [], basename($path), $judul);

        $kolom['sisa_kirim'] = $this->kolomAwalan($rows[$barisJudul] ?? [], self::JUDUL_PO_AWALAN);

        if ($kolom['sisa_kirim'] === null) {
            throw SparepartFormatException::untukBerkas(
                basename($path),
                [self::JUDUL_PO_AWALAN . ' (qty)'],
                $judul,
                'Unggah hasil ekspor SAP ME2M yang memuat kolom sisa kirim.'
            );
        }

        $data      = [];
        $nomor     = [];
        $baris     = 0;
        $diabaikan = 0;

        foreach ($rows as $i => $r) {
            if ($i <= $barisJudul) {
                continue;
            }

            $noPo     = self::teks($this->sel($r, $kolom, 'Purchasing Document'));
            $material = self::teks($this->sel($r, $kolom, 'Material'));

            if ($noPo === '' || $material === '') {
                $diabaikan++;
                continue;
            }

            $sisa     = self::angka($this->sel($r, $kolom, 'sisa_kirim'));
            $deletion = self::teks($this->sel($r, $kolom, 'Deletion Indicator'));
            $status   = $deletion !== '' ? 'DELETED' : ($sisa > 0 ? 'OPEN' : 'CLOSED');

            $baris++;
            $nomor[$noPo] = true;

            $data[$material][] = [
                'kode_material'   => $material,
                'no_po'           => mb_substr($noPo, 0, 40),
                'tanggal_dokumen' => self::hari($this->sel($r, $kolom, 'Document Date')) ?: null,
                'sisa_kirim'      => $sisa,
                'status'          => $status,
                'vendor'          => mb_substr(self::teks($this->sel($r, $kolom, 'Supplier/Supplying Plant')), 0, 255),
                'our_reference'   => mb_substr(self::teks($this->sel($r, $kolom, 'Our Reference')), 0, 100),
                'plant'           => mb_substr(self::teks($this->sel($r, $kolom, 'Plant')), 0, 20),
                'order_qty'       => self::angka($this->sel($r, $kolom, 'Order Quantity')),
            ];
        }

        return [
            'data'      => $data,
            'baris'     => $baris,
            'diabaikan' => $diabaikan,
            'dokumen'   => count($nomor),
            'lembar'    => [$lembar],
            'judul'     => $judul,
            'catatan'   => [],
        ];
    }

    /**
     * Membaca berkas EXPORT MRP (SAP MD07/MD04 - Stock/Requirements List).
     *
     * Nilai yang diambil: stok di SAP ("Plnt stock"), safety stock, reorder
     * point, satuan, dan tipe MRP. Safety Stk dipakai sebagai stok minimum
     * pada halaman Sparepart; status REORDER membandingkan sisa stok G001
     * dengan Safety Stk tersebut. Stock by SAP dipakai untuk indikator
     * Stok Beda.
     *
     * @param  string $path Path berkas xlsx
     * @return array<string, mixed> Data per material + rincian baca
     *
     * @throws SparepartFormatException Bila judul kolom wajib tidak ada
     */
    public function bacaMrp(string $path): array
    {
        $lembar = $this->daftarLembar($path)[0] ?? '';
        $rows   = $this->bacaLembar($path, $lembar);
        $judul  = [];

        [$barisJudul, $kolom] = $this->cariJudul(
            $rows,
            self::JUDUL_MRP,
            self::JUDUL_MRP_OPSIONAL,
            basename($path),
            $judul
        );

        $data      = [];
        $baris     = 0;
        $diabaikan = 0;
        $ganda     = 0;

        foreach ($rows as $i => $r) {
            if ($i <= $barisJudul) {
                continue;
            }

            $material = self::teks($this->sel($r, $kolom, 'Material'));

            if ($material === '') {
                continue;
            }

            if (! $this->kodeSah($material)) {
                $diabaikan++;
                continue;
            }

            $baris++;

            if (isset($data[$material])) {
                $ganda++;
            }

            $data[$material] = [
                'kode_material' => $material,
                'mrp_stock'     => self::angka($this->sel($r, $kolom, 'Plnt stock')),
                'mrp_safety'    => self::angka($this->sel($r, $kolom, 'Safety Stk')),
                'mrp_reorder'   => self::angka($this->sel($r, $kolom, 'Reorder Pt')),
                'mrp_unit'      => mb_substr(self::teks($this->sel($r, $kolom, 'Unit')), 0, 20),
                'mrp_type'      => mb_substr(self::teks($this->sel($r, $kolom, 'MRP Type')), 0, 20),
                'mrp_desc'      => mb_substr(self::teks($this->sel($r, $kolom, 'Description')), 0, 255),
            ];
        }

        $catatan = [];

        if ($ganda > 0) {
            $catatan[] = $ganda . ' material muncul lebih dari sekali di berkas MRP; nilai baris terakhir yang dipakai.';
        }

        return [
            'data'      => $data,
            'baris'     => $baris,
            'diabaikan' => $diabaikan,
            'dokumen'   => null,
            'lembar'    => [$lembar],
            'judul'     => $judul,
            'catatan'   => $catatan,
        ];
    }

    /**
     * Membaca berkas daftar pantau: xlsx (dicari lembar seperti MASTER DATA)
     * atau CSV dengan kolom "Kode Material".
     *
     * @param  string $path Path berkas xlsx/csv
     * @return array<string, mixed> Baris daftar pantau + rincian baca
     *
     * @throws SparepartFormatException Bila kolom "Kode Material" tidak ada
     */
    public function bacaPantau(string $path): array
    {
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return $this->bacaPantauCsv($path);
        }

        $daftar = $this->daftarLembar($path);
        $lembar = $this->pilihLembarPantau($daftar) ?? ($daftar[0] ?? '');
        $rows   = $this->bacaLembar($path, $lembar);
        $judul  = [];

        [$barisJudul, $kolom] = $this->cariJudul(
            $rows,
            self::JUDUL_PANTAU,
            self::JUDUL_PANTAU_OPSIONAL,
            basename($path),
            $judul
        );

        return $this->susunPantau($rows, $barisJudul, $kolom, [$lembar], $judul);
    }

    /**
     * Memilih lembar daftar pantau dari daftar nama lembar.
     *
     * Nama yang dipakai lebih dulu: tepat "MASTER DATA", lalu lembar yang
     * namanya dimulai "MASTER". Bila tidak ada, null (pemanggil memakai
     * lembar pertama dan kepala kolomnya diperiksa seperti biasa).
     *
     * @param  array<int, string> $daftar Daftar nama lembar berkas
     * @return string|null                Nama lembar pilihan
     */
    private function pilihLembarPantau(array $daftar): ?string
    {
        foreach ($daftar as $nama) {
            if ($this->normJudul($nama) === 'master data') {
                return $nama;
            }
        }

        return $this->cariLembar($daftar, 'MASTER');
    }

    /**
     * Membuka berkas Excel dengan hanya lembar yang diperlukan.
     *
     * @param  string            $path   Path berkas
     * @param  array<int, string> $lembar Nama lembar yang dimuat
     * @return Spreadsheet               Buku kerja
     */
    private function bukaBerkas(string $path, array $lembar): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($lembar);

        return $reader->load($path);
    }

    /**
     * Baris satu lembar buku kerja yang sudah dibuka.
     *
     * @param  Spreadsheet $buku Buku kerja
     * @param  string      $nama Nama lembar
     * @return array<int, array<int, mixed>> Baris lembar (kosong bila lembar tidak ada)
     */
    private function barisLembar(Spreadsheet $buku, string $nama): array
    {
        $lembar = $buku->getSheetByName($nama);

        return $lembar === null ? [] : $lembar->toArray(null, false, false, false);
    }

    /**
     * Membaca satu lembar berkas Excel menjadi array baris.
     *
     * @param  string $path Path berkas
     * @param  string $nama Nama lembar
     * @return array<int, array<int, mixed>> Baris lembar
     *
     * @throws RuntimeException Bila nama lembar kosong
     */
    private function bacaLembar(string $path, string $nama): array
    {
        if ($nama === '') {
            throw new RuntimeException('Berkas ' . basename($path) . ' tidak memuat lembar data.');
        }

        $buku = $this->bukaBerkas($path, [$nama]);
        $rows = $this->barisLembar($buku, $nama);
        $buku->disconnectWorksheets();

        return $rows;
    }

    /**
     * Memeriksa judul kolom satu lembar buku kerja yang sudah dibuka.
     *
     * @param  Spreadsheet        $buku   Buku kerja
     * @param  string             $lembar Nama lembar
     * @param  array<int, string> $wajib  Judul kolom yang wajib ada
     * @param  string             $path   Path berkas (untuk pesan galat)
     * @param  array<string, mixed> $judul Penampung judul per lembar
     * @param  array<int, string> $opsional Judul kolom yang boleh tidak ada
     * @return array<string, int>         Peta judul kolom => indeks kolom
     *
     * @throws SparepartFormatException Bila judul wajib tidak ditemukan
     */
    private function petaKolomWajib(
        Spreadsheet $buku,
        string $lembar,
        array $wajib,
        string $path,
        array &$judul,
        array $opsional = []
    ): array {
        $rows  = $this->barisLembar($buku, $lembar);
        $lokal = [];

        [$barisJudul, $kolom] = $this->cariJudul($rows, $wajib, $opsional, basename($path), $lokal);

        $judul[$lembar] = $lokal;
        $kolom['__judul'] = $barisJudul;

        return $kolom;
    }

    /**
     * Mencari baris judul kolom di antara baris-baris lembar.
     *
     * Baris judul = baris pertama yang memuat SELURUH judul wajib. Judul
     * dibandingkan setelah dinormalkan (huruf kecil, spasi dirapikan) supaya
     * spasi ganda dari ekspor SAP tidak menggagalkan import.
     *
     * @param  array<int, array<int, mixed>> $rows       Baris lembar
     * @param  array<int, string>            $wajib      Judul kolom yang wajib ada
     * @param  array<int, string>            $opsional   Judul kolom yang boleh tidak ada
     * @param  string                        $namaBerkas Nama berkas untuk pesan galat
     * @param  array<int, string>            $judul      Penampung judul yang ditemukan
     * @return array<int, mixed>                        [indeks baris judul, peta judul => indeks kolom]
     *
     * @throws SparepartFormatException Bila judul wajib tidak ditemukan
     */
    private function cariJudul(array $rows, array $wajib, array $opsional, string $namaBerkas, array &$judul): array
    {
        $batas     = min(count($rows), self::BATAS_CARI_JUDUL);
        $terbanyak = [];

        for ($i = 0; $i < $batas; $i++) {
            $peta = [];

            foreach ((array) $rows[$i] as $c => $sel) {
                $norm = $this->normJudul($sel);

                if ($norm !== '' && ! isset($peta[$norm])) {
                    $peta[$norm] = $c;
                }
            }

            if ($peta === []) {
                continue;
            }

            if (count($peta) > count($terbanyak)) {
                $terbanyak = $peta;
            }

            $kurang = [];

            foreach ($wajib as $w) {
                if (! isset($peta[$this->normJudul($w)])) {
                    $kurang[] = $w;
                }
            }

            if ($kurang !== []) {
                continue;
            }

            $kolom = [];

            foreach ($wajib as $w) {
                $kolom[$w] = $peta[$this->normJudul($w)];
            }

            foreach ($opsional as $o) {
                $idx = $peta[$this->normJudul($o)] ?? null;

                if ($idx !== null) {
                    $kolom[$o] = $idx;
                }
            }

            $judul = array_values(array_filter(
                array_map(fn (mixed $v): string => trim((string) $v), (array) $rows[$i]),
                fn (string $v): bool => $v !== ''
            ));

            return [$i, $kolom];
        }

        throw SparepartFormatException::untukBerkas(
            $namaBerkas,
            $wajib,
            array_keys($terbanyak),
            'Periksa kembali berkas sumbernya: urutan kolom boleh bergeser, tetapi nama judul tidak boleh hilang atau berubah.'
        );
    }

    /**
     * Menjumlahkan satu kolom angka per kode material dari lembar barang masuk
     * atau keluar.
     *
     * @param  Spreadsheet       $buku      Buku kerja
     * @param  string            $lembar    Nama lembar
     * @param  array<string, int> $kolom    Peta judul kolom
     * @param  string            $kolKode  Judul kolom kode material
     * @param  string            $kolNilai Judul kolom angka yang dijumlahkan
     * @return array<string, float>        Peta kode => jumlah (kunci "__baris" = jumlah baris dipakai)
     */
    private function jumlahKolom(Spreadsheet $buku, string $lembar, array $kolom, string $kolKode, string $kolNilai): array
    {
        $hasil = ['__baris' => 0.0];
        $rows  = $this->barisLembar($buku, $lembar);
        $mulai = (int) ($kolom['__judul'] ?? 0) + 1;
        $iKode = $kolom[$kolKode] ?? null;
        $iNilai = $kolom[$kolNilai] ?? null;

        if ($iKode === null || $iNilai === null) {
            return $hasil;
        }

        for ($i = $mulai; $i < count($rows); $i++) {
            $kode = self::teks($rows[$i][$iKode] ?? '');

            if ($kode === '' || ! is_numeric($kode)) {
                continue;
            }

            $hasil['__baris'] = (float) $hasil['__baris'] + 1;
            $hasil[$kode]     = (float) ($hasil[$kode] ?? 0.0) + self::angka($rows[$i][$iNilai] ?? 0);
        }

        return $hasil;
    }

    /**
     * Merangkum stok awal per kode material dari lembar G001.
     *
     * @param  Spreadsheet        $buku   Buku kerja
     * @param  string             $lembar Nama lembar rekap stok
     * @param  array<string, int> $kolom  Peta judul kolom
     * @return array<string, mixed>       Data per material + jumlah baris dibaca
     */
    private function rangkumStok(Spreadsheet $buku, string $lembar, array $kolom): array
    {
        $data      = [];
        $rows      = $this->barisLembar($buku, $lembar);
        $mulai     = (int) ($kolom['__judul'] ?? 0) + 1;
        $baris     = 0;
        $diabaikan = 0;

        for ($i = $mulai; $i < count($rows); $i++) {
            $kode = self::teks($rows[$i][$kolom['Kode'] ?? 0] ?? '');

            if ($kode === '' || ! is_numeric($kode)) {
                if ($kode !== '') {
                    $diabaikan++;
                }

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
            $g['stok_awal'] += self::angka($rows[$i][$kolom['Stok Awal'] ?? 0] ?? 0);

            if ($g['nama_item'] === '') {
                $g['nama_item'] = mb_substr(self::teks($rows[$i][$kolom['Nama Item'] ?? 0] ?? ''), 0, 255);
            }

            $sloc = self::teks($rows[$i][$kolom['S. Loc'] ?? 0] ?? '');

            // Satuan dibaca lewat sel() supaya kolom "UN" yang TIDAK ada di
            // berkas menghasilkan string kosong, bukan nilai kolom lain
            // (lihat catatan JUDUL_STOK_OPSIONAL).
            $satuan = self::teks($this->sel($rows[$i], $kolom, 'UN') ?? '');

            if ($sloc !== '' && ! in_array($sloc, $g['sloc'], true)) {
                $g['sloc'][] = $sloc;
            }

            if ($satuan !== '' && ! in_array($satuan, $g['satuan'], true)) {
                $g['satuan'][] = $satuan;
            }

            $data[$kode] = $g;
        }

        return ['data' => $data, 'baris' => $baris, 'diabaikan' => $diabaikan];
    }

    /**
     * Mengambil satu sel berdasarkan peta kolom.
     *
     * @param  array<int, mixed>       $baris Baris sumber
     * @param  array<string, int|null> $kolom Peta judul => indeks
     * @param  string                  $nama  Judul kolom
     * @return mixed                          Nilai sel (null bila kolom tidak ada)
     */
    private function sel(array $baris, array $kolom, string $nama): mixed
    {
        $i = $kolom[$nama] ?? null;

        return $i === null ? null : ($baris[$i] ?? null);
    }

    /**
     * Mencari indeks kolom yang judulnya DIMULAI awalan tertentu.
     *
     * @param  array<int, mixed> $judul  Baris judul
     * @param  string            $awalan Awalan judul yang dicari
     * @return int|null                  Indeks kolom (null bila tidak ada)
     */
    private function kolomAwalan(array $judul, string $awalan): ?int
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
        return preg_match(self::POLA_KODE, trim($kode)) === 1;
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

    /**
     * Membaca berkas CSV menjadi daftar baris.
     *
     * Tanda BOM UTF-8 pada sel pertama dibuang supaya judul kolom pertama
     * tetap terbaca (berkas hasil ekspor spreadsheet sering memuatnya).
     *
     * @param  string $path Path berkas CSV
     * @return array<int, array<int, string>> Baris CSV
     *
     * @throws RuntimeException Bila berkas tidak bisa dibuka
     */
    private function bacaCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Berkas ' . basename($path) . ' tidak bisa dibuka.');
        }

        $rows = [];

        try {
            while (($kol = fgetcsv($handle, 0, ',')) !== false) {
                if ($kol === [null]) {
                    continue;
                }

                $sel = array_map(fn (mixed $v): string => trim((string) $v), $kol);

                if ($rows === [] && isset($sel[0])) {
                    $sel[0] = preg_replace('/^\x{FEFF}/u', '', $sel[0]) ?? $sel[0];
                }

                $rows[] = $sel;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Menyusun daftar pantau dari baris lembar yang judulnya sudah ditemukan.
     *
     * Hanya kode material berbentuk angka minimal 6 digit yang disimpan; kode
     * yang muncul lebih dari sekali dihitung sebagai baris ganda (nilai
     * terakhir yang menang).
     *
     * @param  array<int, array<int, mixed>> $rows       Seluruh baris lembar
     * @param  int                           $barisJudul Indeks baris judul
     * @param  array<string, int>            $kolom      Peta judul kolom
     * @param  array<int, string>            $lembar     Nama lembar yang dibaca
     * @param  array<int, string>            $judul      Judul kolom yang ditemukan
     * @return array<string, mixed>                      Data + rincian baca
     */
    private function susunPantau(array $rows, int $barisJudul, array $kolom, array $lembar, array $judul): array
    {
        $data     = [];
        $baris    = 0;
        $ganda    = 0;
        $tidakSah = 0;

        foreach ($rows as $i => $r) {
            if ($i <= $barisJudul) {
                continue;
            }

            $kode = self::teks($this->sel($r, $kolom, 'Kode Material'));

            if ($kode === '') {
                continue;
            }

            if (! $this->kodeSah($kode)) {
                $tidakSah++;
                continue;
            }

            $baris++;

            if (isset($data[$kode])) {
                $ganda++;
            }

            $data[$kode] = [
                'kode_material' => $kode,
                'deskripsi'     => mb_substr(self::teks($this->sel($r, $kolom, 'Deskripsi')), 0, 255),
                'plant'         => mb_substr(self::teks($this->sel($r, $kolom, 'Plant')), 0, 100),
                'model'         => mb_substr(self::teks($this->sel($r, $kolom, 'Model')), 0, 100),
                'tag_no'        => mb_substr(self::teks($this->sel($r, $kolom, 'Tag No.')), 0, 100),
                'uom'           => mb_substr(self::teks($this->sel($r, $kolom, 'UOM')), 0, 20),
                'stok_min'      => isset($kolom['Jumlah']) ? self::angka($this->sel($r, $kolom, 'Jumlah')) : null,
            ];
        }

        $catatan = [];

        if ($tidakSah > 0) {
            $catatan[] = $tidakSah . ' baris dilewati karena kode materialnya bukan angka (minimal 6 digit).';
        }

        if (! isset($kolom['Jumlah'])) {
            $catatan[] = 'Kolom Jumlah (stok minimum) tidak ada pada berkas; isi stok minimum lewat halaman daftar pantau.';
        }

        return [
            'data'      => $data,
            'baris'     => $baris,
            'diabaikan' => $tidakSah,
            'ganda'     => $ganda,
            'lembar'    => $lembar,
            'judul'     => $judul,
            'catatan'   => $catatan,
        ];
    }

    /**
     * Membaca daftar pantau dari berkas CSV (baris pertama = judul).
     *
     * @param  string $path Path berkas CSV
     * @return array<string, mixed> Data + rincian baca
     *
     * @throws SparepartFormatException Bila kolom "Kode Material" tidak ada
     */
    private function bacaPantauCsv(string $path): array
    {
        $rows  = $this->bacaCsv($path);
        $judul = [];

        [$barisJudul, $kolom] = $this->cariJudul(
            $rows,
            self::JUDUL_PANTAU,
            self::JUDUL_PANTAU_OPSIONAL,
            basename($path),
            $judul
        );

        return $this->susunPantau($rows, $barisJudul, $kolom, [basename($path)], $judul);
    }

    /**
     * Daftar nama lembar sebuah berkas Excel.
     *
     * @param  string $path Path berkas
     * @return array<int, string> Nama lembar berurutan
     *
     * @throws RuntimeException Bila berkas tidak bisa dibaca
     */
    private function daftarLembar(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);

            return $reader->listWorksheetNames($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Berkas ' . basename($path) . ' tidak bisa dibaca: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Mencari nama lembar yang DIMULAI awalan tertentu (tanpa beda huruf besar).
     *
     * @param  array<int, string> $daftar Daftar nama lembar
     * @param  string             $awalan Awalan nama lembar
     * @return string|null                Nama lembar pertama yang cocok
     */
    private function cariLembar(array $daftar, string $awalan): ?string
    {
        foreach ($daftar as $nama) {
            if (stripos($nama, $awalan) === 0) {
                return $nama;
            }
        }

        return null;
    }
}
