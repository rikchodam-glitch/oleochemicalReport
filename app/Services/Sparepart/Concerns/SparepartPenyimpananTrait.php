<?php

namespace App\Services\Sparepart\Concerns;

use App\Models\SpIntegrasi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penyimpanan baris mentah dan perhitungan hasil Integrasi PR/PO Spare Part.
 *
 * Trait ini dipakai SparepartImportService supaya berkas service tetap fokus
 * pada alur batch (import, berkas wajib, pembersihan batch lama), sedangkan
 * seluruh penyimpanan baris sp_* dan aturan hitung integrasi ada di sini.
 *
 * Aturan pemilihan dokumen dan penetapan kolom aksi MENGIKUTI PANDUAN
 * Integrasi PR/PO Spare Part Bab 7-10 dan sudah dibandingkan kolom demi kolom
 * dengan berkas hasil lama, sehingga angka ringkasannya harus tetap sama.
 */
trait SparepartPenyimpananTrait
{
    /**
     * Menyisipkan baris ke tabel dengan pemotongan per ukuran sisip.
     *
     * @param  string                           $tabel Nama tabel tujuan
     * @param  array<int, array<string, mixed>> $baris Baris yang disisipkan
     * @return void
     */
    private function sisip(string $tabel, array $baris): void
    {
        foreach (array_chunk($baris, self::UKURAN_SISIP) as $potongan) {
            DB::table($tabel)->insert($potongan);
        }
    }

    /**
     * Memotong teks agar tidak melebihi panjang kolom database.
     *
     * @param  string|null $teks  Teks sumber
     * @param  int         $batas Panjang maksimal
     * @return string|null        Teks terpotong (null bila sumber kosong)
     */
    private function potong(?string $teks, int $batas): ?string
    {
        $teks = trim((string) $teks);

        return $teks === '' ? null : mb_substr($teks, 0, $batas);
    }

    /**
     * Mengubah nilai menjadi angka; nilai yang tidak terbaca menjadi null.
     *
     * @param  mixed     $nilai Nilai sumber
     * @return float|null       Angka (null bila bukan angka)
     */
    private function angkaAtau(mixed $nilai): ?float
    {
        return is_numeric($nilai) ? (float) $nilai : null;
    }

    /**
     * Menyimpan ringkasan stok gudang (G001) satu baris per material.
     *
     * Pembaca sudah menjumlahkan seluruh SLoc satu material, sehingga daftar
     * SLoc dan satuan disimpan sebagai teks gabungan supaya tetap terlihat.
     *
     * @param  int                                 $batchId Id batch
     * @param  array<string, array<string, mixed>> $data    Data hasil baca
     * @param  Carbon                              $kini    Cap waktu baris
     * @return void
     */
    private function simpanG001(int $batchId, array $data, Carbon $kini): void
    {
        if ($data === []) {
            return;
        }

        $baris = [];

        foreach ($data as $item) {
            $baris[] = [
                'batch_id'      => $batchId,
                'kode_material' => $item['kode_material'],
                'sloc'          => $this->potong(implode(', ', (array) ($item['sloc'] ?? [])), 30),
                'nama_item'     => $this->potong($item['nama_item'] ?? null, self::BATAS_DESKRIPSI),
                'stok_awal'     => $this->angkaAtau($item['stok_awal'] ?? null) ?? 0,
                'masuk'         => $this->angkaAtau($item['masuk'] ?? null) ?? 0,
                'keluar'        => $this->angkaAtau($item['keluar'] ?? null) ?? 0,
                'sisa'          => $this->angkaAtau($item['sisa'] ?? null) ?? 0,
                'satuan'        => $this->potong(implode('/', (array) ($item['satuan'] ?? [])), self::BATAS_SATUAN),
                'created_at'    => $kini,
                'updated_at'    => $kini,
            ];
        }

        $this->sisip('sp_g001', $baris);
    }

    /**
     * Menyimpan baris mentah permintaan pembelian (EXPORT PR).
     *
     * @param  int                                            $batchId Id batch
     * @param  array<string, array<int, array<string, mixed>>> $data    Data hasil baca
     * @param  Carbon                                         $kini    Cap waktu baris
     * @return void
     */
    private function simpanPr(int $batchId, array $data, Carbon $kini): void
    {
        if ($data === []) {
            return;
        }

        $baris = [];

        foreach ($data as $daftar) {
            foreach ($daftar as $item) {
                $baris[] = [
                    'batch_id'            => $batchId,
                    'kode_material'       => $item['kode_material'],
                    'no_pr'               => $this->potong($item['no_pr'] ?? null, self::BATAS_NO_DOKUMEN),
                    'processing_status'   => $this->potong($item['processing_status'] ?? null, 10),
                    'deletion_indicator'  => $this->potong($item['deletion_indicator'] ?? null, 20),
                    'batal'               => ! empty($item['batal']) ? 1 : 0,
                    'qty_requested'       => $this->angkaAtau($item['qty_requested'] ?? null) ?? 0,
                    'tanggal_kirim'       => ($item['tanggal_kirim'] ?? null) ?: null,
                    'no_po'               => $this->potong($item['no_po'] ?? null, self::BATAS_NO_DOKUMEN),
                    'requisitioner'       => $this->potong($item['requisitioner'] ?? null, 100),
                    'purchasing_group'    => $this->potong($item['purchasing_group'] ?? null, 20),
                    'created_at'          => $kini,
                    'updated_at'          => $kini,
                ];
            }
        }

        $this->sisip('sp_pr', $baris);
    }

    /**
     * Menyimpan baris mentah pesanan pembelian (EXPORT PO).
     *
     * @param  int                                             $batchId Id batch
     * @param  array<string, array<int, array<string, mixed>>> $data    Data hasil baca
     * @param  Carbon                                          $kini    Cap waktu baris
     * @return void
     */
    private function simpanPo(int $batchId, array $data, Carbon $kini): void
    {
        if ($data === []) {
            return;
        }

        $baris = [];

        foreach ($data as $daftar) {
            foreach ($daftar as $item) {
                $baris[] = [
                    'batch_id'        => $batchId,
                    'kode_material'   => $item['kode_material'],
                    'no_po'           => $this->potong($item['no_po'] ?? null, self::BATAS_NO_DOKUMEN) ?? '',
                    'tanggal_dokumen' => ($item['tanggal_dokumen'] ?? null) ?: null,
                    'sisa_kirim'      => $this->angkaAtau($item['sisa_kirim'] ?? null),
                    'status'          => $this->potong($item['status'] ?? null, 10) ?? 'CLOSED',
                    'vendor'          => $this->potong($item['vendor'] ?? null, self::BATAS_LOKASI),
                    'our_reference'   => $this->potong($item['our_reference'] ?? null, 100),
                    'plant'           => $this->potong($item['plant'] ?? null, 20),
                    'order_qty'       => $this->angkaAtau($item['order_qty'] ?? null) ?? 0,
                    'created_at'      => $kini,
                    'updated_at'      => $kini,
                ];
            }
        }

        $this->sisip('sp_po', $baris);
    }

    /**
     * Menyimpan baris mentah MD07 (plant stock, safety stock, reorder point).
     *
     * @param  int                                 $batchId Id batch
     * @param  array<string, array<string, mixed>> $data    Data hasil baca
     * @param  Carbon                              $kini    Cap waktu baris
     * @return void
     */
    private function simpanMrp(int $batchId, array $data, Carbon $kini): void
    {
        if ($data === []) {
            return;
        }

        $baris = [];

        foreach ($data as $item) {
            $baris[] = [
                'batch_id'      => $batchId,
                'kode_material' => $item['kode_material'],
                'plant_stock'   => $this->angkaAtau($item['plant_stock'] ?? null) ?? 0,
                'safety_stock'  => $this->angkaAtau($item['safety_stock'] ?? null) ?? 0,
                'reorder_point' => $this->angkaAtau($item['reorder_point'] ?? null) ?? 0,
                'created_at'    => $kini,
                'updated_at'    => $kini,
            ];
        }

        $this->sisip('sp_mrp', $baris);
    }

    /**
     * Mengambil nilai teks unik (tanpa nilai kosong) satu kolom, urut kemunculan.
     *
     * @param  array<int, array<string, mixed>> $baris Daftar baris sumber
     * @param  string                           $kunci Nama kolom yang diambil
     * @return array<int, string>                      Nilai unik berurutan
     */
    private function nilaiUnik(array $baris, string $kunci): array
    {
        $hasil = [];

        foreach ($baris as $item) {
            $nilai = trim((string) ($item[$kunci] ?? ''));

            if ($nilai !== '' && ! in_array($nilai, $hasil, true)) {
                $hasil[] = $nilai;
            }
        }

        return $hasil;
    }

    /**
     * Memilih dokumen PR dan PO yang dianggap paling relevan untuk satu material.
     *
     * PO unik per nomor dokumen, PO terbuka diurutkan tanggal terbaru. PR
     * terpilih adalah PR yang sudah menjadi PO terpilih; bila tidak ada, PR
     * terbuka terbaru; bila tetap tidak ada, PR terbaru apa pun.
     *
     * @param  array<int, array<string, mixed>> $prBaris Baris PR satu material
     * @param  array<int, array<string, mixed>> $poBaris Baris PO satu material
     * @return array<string, mixed>                     PO unik, PO/PR terpilih, dan daftar terbuka
     */
    private function pilihDokumen(array $prBaris, array $poBaris): array
    {
        $poUnik = [];

        foreach ($poBaris as $item) {
            $poUnik[(string) ($item['no_po'] ?? '')] = $item;
        }

        $poTerbuka = array_values(array_filter(
            $poUnik,
            fn (array $item): bool => ($item['status'] ?? '') === 'OPEN'
        ));
        usort($poTerbuka, fn (array $a, array $b): int => strcmp(
            (string) ($b['tanggal_dokumen'] ?? ''),
            (string) ($a['tanggal_dokumen'] ?? '')
        ));

        $poTerpilih = $poTerbuka[0] ?? null;

        $prTerbuka = array_values(array_filter(
            $prBaris,
            fn (array $item): bool => empty($item['batal']) && ($item['processing_status'] ?? '') === 'N'
        ));
        usort($prTerbuka, fn (array $a, array $b): int => strcmp(
            (string) ($b['no_pr'] ?? ''),
            (string) ($a['no_pr'] ?? '')
        ));

        $prSemua = $prBaris;
        usort($prSemua, fn (array $a, array $b): int => strcmp(
            (string) ($b['no_pr'] ?? ''),
            (string) ($a['no_pr'] ?? '')
        ));

        $prTerpilih = null;

        if ($poTerpilih !== null) {
            foreach ($prBaris as $item) {
                $noPoPr = (string) ($item['no_po'] ?? '');

                if ($noPoPr !== '' && $noPoPr === (string) $poTerpilih['no_po']) {
                    $prTerpilih = $item;
                    break;
                }
            }
        }

        if ($prTerpilih === null) {
            $prTerpilih = $prTerbuka[0] ?? ($prSemua[0] ?? null);
        }

        if ($poTerpilih === null
            && $prTerpilih !== null
            && ($prTerpilih['processing_status'] ?? '') === 'B'
            && (string) ($prTerpilih['no_po'] ?? '') !== '') {
            $poTerpilih = $poUnik[(string) $prTerpilih['no_po']] ?? null;
        }

        return [
            'po_unik'     => $poUnik,
            'po_terbuka'  => $poTerbuka,
            'po_terpilih' => $poTerpilih,
            'pr_terbuka'  => $prTerbuka,
            'pr_terpilih' => $prTerpilih,
        ];
    }

    /**
     * Menentukan kolom aksi tindak lanjut satu material.
     *
     * @param  array<int, array<string, mixed>> $poTerbuka PO terbuka satu material
     * @param  array<int, array<string, mixed>> $prTerbuka PR terbuka satu material
     * @param  array<int, array<string, mixed>> $prBaris   Seluruh baris PR material
     * @return string                                     Nilai kolom aksi
     */
    private function kunciAksi(array $poTerbuka, array $prTerbuka, array $prBaris): string
    {
        if ($poTerbuka !== []) {
            return SpIntegrasi::AKSI_KEJAR;
        }

        if ($prTerbuka !== []) {
            return SpIntegrasi::AKSI_BUAT_PO;
        }

        if ($prBaris !== []) {
            return SpIntegrasi::AKSI_CEK;
        }

        return SpIntegrasi::AKSI_BUAT_PR;
    }

    /**
     * Merekap baris PR satu material: jumlah per status, total qty, dan pemohon.
     *
     * @param  array<int, array<string, mixed>> $prBaris   Seluruh baris PR material
     * @param  array<string, mixed>|null        $prTerpilih Baris PR terpilih
     * @return array<string, mixed>                        Rekap PR
     */
    private function rekapPr(array $prBaris, ?array $prTerpilih): array
    {
        $terbukaJml = 0;
        $closedJml = 0;
        $batalJml = 0;
        $qtyTotal = 0.0;

        foreach ($prBaris as $item) {
            if (empty($item['batal']) && ($item['processing_status'] ?? '') === 'N') {
                $terbukaJml++;
            }

            if (($item['processing_status'] ?? '') === 'B') {
                $closedJml++;
            }

            if (! empty($item['batal'])) {
                $batalJml++;
            }

            $qtyTotal += (float) ($item['qty_requested'] ?? 0);
        }

        $status = '';

        if ($prBaris !== []) {
            $status = 'Terbuka ' . $terbukaJml . ' / Closed ' . $closedJml;

            if ($batalJml > 0) {
                $status .= ' / Batal ' . $batalJml;
            }
        }

        $statusAksi = '';
        $nomorTerpilih = (string) ($prTerpilih['no_pr'] ?? '');

        if ($nomorTerpilih !== '') {
            $statusAksi = 'Dibatalkan';

            foreach ($prBaris as $item) {
                if ((string) ($item['no_pr'] ?? '') !== $nomorTerpilih) {
                    continue;
                }

                if (empty($item['batal']) && ($item['processing_status'] ?? '') === 'N') {
                    $statusAksi = 'Terbuka (belum PO)';
                    break;
                }

                if (($item['processing_status'] ?? '') === 'B') {
                    $statusAksi = 'Sudah jadi PO';
                }
            }
        }

        return [
            'terbuka_jml' => $terbukaJml,
            'closed_jml'  => $closedJml,
            'batal_jml'   => $batalJml,
            'qty'         => $prBaris === [] ? null : $qtyTotal,
            'status'      => $status,
            'status_aksi' => $statusAksi,
            'nomor'       => $nomorTerpilih,
            'req'         => $this->nilaiUnik($prBaris, 'requisitioner'),
            'nums'        => implode('; ', array_keys(array_flip(array_column($prBaris, 'no_pr')))),
        ];
    }

    /**
     * Merekap baris PO satu material: jumlah per status, sisa kirim, dan riwayat.
     *
     * `purch` mengikuti urutan: Our Reference PO terpilih, lalu Our Reference PO
     * sebelumnya, terakhir daftar Purchasing Group dari baris PR.
     *
     * @param  array<int, array<string, mixed>>  $poBaris    Seluruh baris PO material
     * @param  array<string, array<string, mixed>> $poUnik   PO unik per nomor
     * @param  array<string, mixed>|null         $poTerpilih Baris PO terpilih
     * @param  array<int, array<string, mixed>>  $prBaris    Seluruh baris PR material
     * @return array<string, mixed>                          Rekap PO
     */
    private function rekapPo(array $poBaris, array $poUnik, ?array $poTerpilih, array $prBaris): array
    {
        $closed = 0;
        $terbuka = 0;
        $sisaTotal = 0.0;
        $tanggalMax = '';

        foreach ($poBaris as $item) {
            $status = (string) ($item['status'] ?? '');

            if ($status === 'CLOSED') {
                $closed++;
            }

            if ($status === 'OPEN') {
                $terbuka++;
            }

            $sisaTotal += (float) ($item['sisa_kirim'] ?? 0);
            $tanggal = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal > $tanggalMax) {
                $tanggalMax = $tanggal;
            }
        }

        // PO kedua terbaru (selain yang terpilih) dan PO terbaru secara umum.
        $sebelum = null;
        $tanggalSebelum = '';

        foreach ($poUnik as $item) {
            if ($poTerpilih !== null && (string) $item['no_po'] === (string) $poTerpilih['no_po']) {
                continue;
            }

            $tanggal = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal !== '' && $tanggal >= $tanggalSebelum) {
                $tanggalSebelum = $tanggal;
                $sebelum = $item;
            }
        }

        $terakhir = null;
        $tanggalTerakhir = '';

        foreach ($poUnik as $item) {
            $tanggal = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal !== '' && $tanggal >= $tanggalTerakhir) {
                $tanggalTerakhir = $tanggal;
                $terakhir = $item;
            }
        }

        $purch = (string) ($poTerpilih['our_reference'] ?? '');

        if ($purch === '') {
            $purch = (string) ($sebelum['our_reference'] ?? '');
        }

        if ($purch === '') {
            $purch = implode('/', $this->nilaiUnik($prBaris, 'purchasing_group'));
        }

        return [
            'closed'           => $closed,
            'open'             => $terbuka,
            'sisa_total'       => $poBaris === [] ? null : $sisaTotal,
            'tanggal_max'      => $tanggalMax,
            'tanggal_terakhir' => $tanggalTerakhir,
            'purch'            => $purch,
            'po_no'            => (string) ($poTerpilih['no_po'] ?? ''),
            'po_sisa'          => $poTerpilih === null ? null : (float) ($poTerpilih['sisa_kirim'] ?? 0),
            'vendor'           => (string) ($poTerpilih['vendor'] ?? ''),
            'prev_no'          => (string) ($sebelum['no_po'] ?? ''),
            'prev_vendor'      => (string) ($sebelum['vendor'] ?? ''),
            'last_ref'         => (string) ($terakhir['our_reference'] ?? ''),
            'last_vendor'      => (string) ($terakhir['vendor'] ?? ''),
            'uniq'             => count($poUnik),
            'nums'             => implode('; ', array_keys($poUnik)),
        ];
    }

    /**
     * Menyusun satu baris siap simpan pada tabel sp_integrasi.
     *
     * @param  string                          $kode     Kode material monitoring
     * @param  array<string, mixed>            $mon      Baris monitoring material
     * @param  array<string, mixed>|null       $g        Ringkasan stok gudang
     * @param  array<string, mixed>|null       $mrpBaris Nilai MRP material
     * @param  string                          $aksi     Kolom aksi tindak lanjut
     * @param  array<string, mixed>            $rekapPr  Rekap PR material
     * @param  array<string, mixed>            $rekapPo  Rekap PO material
     * @param  int                             $prLine   Jumlah baris mentah PR material
     * @param  int                             $poLine   Jumlah baris mentah PO material
     * @return array<string, mixed>                     Baris sp_integrasi
     */
    private function barisIntegrasi(
        string $kode,
        array $mon,
        ?array $g,
        ?array $mrpBaris,
        string $aksi,
        array $rekapPr,
        array $rekapPo,
        int $prLine,
        int $poLine
    ): array {
        // Penanda "ada baris" dipakai untuk Keterangan: baris PR kosong selalu
        // menghasilkan status kosong, dan PO kosong menghasilkan PO unik nol.
        $adaPr = (string) ($rekapPr['status'] ?? '') !== '';
        $adaPo = (int) ($rekapPo['uniq'] ?? 0) > 0;

        $minCmp = '-';

        if ($mrpBaris !== null) {
            $selisih = abs((float) ($mrpBaris['reorder_point'] ?? 0) - (float) ($mon['stok_min'] ?? 0));
            $minCmp = $selisih < self::TOLERANSI_MIN ? 'Sama' : 'Beda';
        }

        $ket = ($adaPr || $adaPo)
            ? 'Sudah ada PR/PO'
            : (! empty($mon['reorder']) ? 'REORDER - belum ada PR/PO' : 'AMAN');

        return [
            'kode_material'   => $kode,
            'deskripsi'       => $this->potong($mon['deskripsi'] ?? null, self::BATAS_DESKRIPSI),
            'stok_min'        => $this->angkaAtau($mon['stok_min'] ?? null),
            'stok_tersedia'   => $this->angkaAtau($mon['stok_tersedia'] ?? null),
            'reorder'         => ! empty($mon['reorder']) ? 1 : 0,
            'penanda'         => $this->potong($mon['penanda'] ?? null, self::BATAS_PENANDA),
            'aksi'            => $aksi,
            'pr_no'           => $this->potong($rekapPr['nomor'] ?? null, self::BATAS_NO_DOKUMEN),
            'pr_status_aksi'  => $this->potong($rekapPr['status_aksi'] ?? null, self::BATAS_PURCH),
            'pr_status'       => $this->potong($rekapPr['status'] ?? null, self::BATAS_PR_STATUS),
            'pr_qty'          => $rekapPr['qty'],
            'pr_line'         => $prLine,
            'pr_nums'         => $rekapPr['nums'],
            'req_list'        => implode('; ', (array) ($rekapPr['req'] ?? [])),
            'po_no'           => $this->potong($rekapPo['po_no'] ?? null, self::BATAS_NO_DOKUMEN),
            'po_sisa'         => $rekapPo['po_sisa'],
            'po_line'         => $poLine,
            'po_uniq'         => (int) ($rekapPo['uniq'] ?? 0),
            'po_closed'       => (int) ($rekapPo['closed'] ?? 0),
            'po_open'         => (int) ($rekapPo['open'] ?? 0),
            'po_sisa_total'   => $rekapPo['sisa_total'],
            'po_tanggal'      => ($rekapPo['tanggal_max'] ?? '') === '' ? null : $rekapPo['tanggal_max'],
            'po_tanggal_teks' => ($rekapPo['tanggal_terakhir'] ?? '') === ''
                ? null
                : strtoupper(date('d-M-Y', strtotime((string) $rekapPo['tanggal_terakhir']))),
            'po_nums'         => $rekapPo['nums'],
            'purch'           => $this->potong($rekapPo['purch'] ?? null, self::BATAS_PURCH),
            'vendor'          => $this->potong($rekapPo['vendor'] ?? null, self::BATAS_LOKASI),
            'po_prev'         => $this->potong($rekapPo['prev_no'] ?? null, self::BATAS_NO_DOKUMEN),
            'vendor_prev'     => $this->potong($rekapPo['prev_vendor'] ?? null, self::BATAS_LOKASI),
            'purch_last'      => $this->potong($rekapPo['last_ref'] ?? null, self::BATAS_PURCH),
            'vendor_last'     => $this->potong($rekapPo['last_vendor'] ?? null, self::BATAS_LOKASI),
            'g_baris'         => (int) ($g['baris'] ?? 0),
            'g_awal'          => $g === null ? null : (float) ($g['stok_awal'] ?? 0),
            'g_masuk'         => $g === null ? null : (float) ($g['masuk'] ?? 0),
            'g_keluar'        => $g === null ? null : (float) ($g['keluar'] ?? 0),
            'g_sisa'          => $g === null ? null : (float) ($g['sisa'] ?? 0),
            'g_satuan'        => $g === null
                ? null
                : $this->potong(implode('/', (array) ($g['satuan'] ?? [])), self::BATAS_SATUAN),
            'g_lokasi'        => $g === null
                ? null
                : $this->potong(implode('; ', (array) ($g['sloc'] ?? [])), self::BATAS_LOKASI),
            'mrp_plant'       => $mrpBaris === null ? null : (float) ($mrpBaris['plant_stock'] ?? 0),
            'mrp_safety'      => $mrpBaris === null ? null : (float) ($mrpBaris['safety_stock'] ?? 0),
            'mrp_reorder'     => $mrpBaris === null ? null : (float) ($mrpBaris['reorder_point'] ?? 0),
            'min_cmp'         => $minCmp,
            'ket'             => $this->potong($ket, self::BATAS_KET),
        ];
    }

    /**
     * Menghitung baris hasil integrasi, satu baris per material monitoring.
     *
     * @param  array<string, array<string, mixed>>            $monitoring Data monitoring
     * @param  array<string, array<string, mixed>>            $g001       Data stok gudang
     * @param  array<string, array<int, array<string, mixed>>> $pr        Data PR per material
     * @param  array<string, array<int, array<string, mixed>>> $po        Data PO per material
     * @param  array<string, array<string, mixed>>            $mrp        Data MRP per material
     * @return array<string, mixed> Baris hasil, rekap aksi, rekap stok, dan rekap pembanding
     */
    private function hitungIntegrasi(
        array $monitoring,
        array $g001,
        array $pr,
        array $po,
        array $mrp
    ): array {
        $aksi = [
            SpIntegrasi::AKSI_KEJAR   => 0,
            SpIntegrasi::AKSI_BUAT_PO => 0,
            SpIntegrasi::AKSI_CEK     => 0,
            SpIntegrasi::AKSI_BUAT_PR => 0,
        ];

        $aman = 0;
        $jumlahReorder = 0;
        $minSama = 0;
        $minBeda = 0;
        $minTanpaMrp = 0;
        $prIrisan = 0;
        $poIrisan = 0;
        $baris = [];

        foreach ($monitoring as $kode => $mon) {
            $poBaris = $po[$kode] ?? [];
            $prBaris = $pr[$kode] ?? [];

            if (isset($po[$kode])) {
                $poIrisan++;
            }

            if (isset($pr[$kode])) {
                $prIrisan++;
            }

            if (! empty($mon['reorder'])) {
                $jumlahReorder++;
            } else {
                $aman++;
            }

            $pilihan = $this->pilihDokumen($prBaris, $poBaris);
            $kunci = $this->kunciAksi($pilihan['po_terbuka'], $pilihan['pr_terbuka'], $prBaris);

            // Rekap aksi hanya menghitung material yang perlu ditindaklanjuti.
            if (! empty($mon['reorder'])) {
                $aksi[$kunci]++;
            }

            $rekapPr = $this->rekapPr($prBaris, $pilihan['pr_terpilih']);
            $rekapPo = $this->rekapPo($poBaris, $pilihan['po_unik'], $pilihan['po_terpilih'], $prBaris);

            $row = $this->barisIntegrasi(
                (string) $kode,
                $mon,
                $g001[$kode] ?? null,
                $mrp[$kode] ?? null,
                $kunci,
                $rekapPr,
                $rekapPo,
                count($prBaris),
                count($poBaris)
            );

            $baris[] = $row;

            if (($row['min_cmp'] ?? '-') === '-') {
                $minTanpaMrp++;
            } elseif ($row['min_cmp'] === 'Sama') {
                $minSama++;
            } else {
                $minBeda++;
            }
        }

        return [
            'baris'     => $baris,
            'aksi'      => $aksi,
            'stok'      => ['total' => count($monitoring), 'aman' => $aman, 'reorder' => $jumlahReorder],
            'min'       => ['sama' => $minSama, 'beda' => $minBeda, 'tanpa_mrp' => $minTanpaMrp],
            'pr_irisan' => $prIrisan,
            'po_irisan' => $poIrisan,
        ];
    }

    /**
     * Menyimpan baris hasil hitung integrasi ke tabel sp_integrasi.
     *
     * @param  int                             $batchId Id batch
     * @param  array<int, array<string, mixed>> $baris  Baris hasil hitung
     * @param  Carbon                          $kini    Cap waktu baris
     * @return void
     */
    private function simpanIntegrasi(int $batchId, array $baris, Carbon $kini): void
    {
        if ($baris === []) {
            return;
        }

        $siap = [];

        foreach ($baris as $item) {
            $siap[] = $item + [
                'batch_id'   => $batchId,
                'created_at' => $kini,
                'updated_at' => $kini,
            ];
        }

        $this->sisip('sp_integrasi', $siap);
    }
}
