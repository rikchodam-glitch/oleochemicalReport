<?php

namespace App\Services\Sparepart\Concerns;

use App\Models\SpMaterial;

/**
 * Perhitungan keadaan material Integrasi PR/PO Spare Part.
 *
 * Aturan pemilihan dokumen (PR/PO) dan penetapan kolom aksi mengikuti PANDUAN
 * Integrasi PR/PO Spare Part Bab 7-10 dan sudah dibandingkan kolom demi kolom
 * dengan berkas hasil lama, sehingga angka ringkasannya tetap sama.
 *
 * Trait ini hanya MENGHITUNG; penyimpanan (upsert) dikerjakan
 * SparepartImportService.
 */
trait SparepartHitungTrait
{
    /** Panjang maksimal nilai teks, mengikuti panjang kolom database. */
    private const BATAS_DESKRIPSI = 255;

    private const BATAS_PENANDA = 120;

    private const BATAS_PR_STATUS = 120;

    private const BATAS_PURCH = 60;

    private const BATAS_LOKASI = 255;

    private const BATAS_SATUAN = 40;

    private const BATAS_NO_DOKUMEN = 40;

    private const BATAS_KET = 120;

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
     * @return array<string, mixed>                     PO unik, PO/PR terpilih, daftar terbuka
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
            return SpMaterial::AKSI_KEJAR;
        }

        if ($prTerbuka !== []) {
            return SpMaterial::AKSI_BUAT_PO;
        }

        if ($prBaris !== []) {
            return SpMaterial::AKSI_CEK;
        }

        return SpMaterial::AKSI_BUAT_PR;
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
        $closedJml  = 0;
        $batalJml   = 0;
        $qtyTotal   = 0.0;

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

        $statusAksi    = '';
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
     * `purch` mengikuti urutan: Our Reference PO terpilih, lalu Our Reference
     * PO sebelumnya, terakhir daftar Purchasing Group dari baris PR.
     *
     * @param  array<int, array<string, mixed>>   $poBaris    Seluruh baris PO material
     * @param  array<string, array<string, mixed>> $poUnik    PO unik per nomor
     * @param  array<string, mixed>|null           $poTerpilih Baris PO terpilih
     * @param  array<int, array<string, mixed>>   $prBaris    Seluruh baris PR material
     * @return array<string, mixed>                           Rekap PO
     */
    private function rekapPo(array $poBaris, array $poUnik, ?array $poTerpilih, array $prBaris): array
    {
        $closed     = 0;
        $terbuka    = 0;
        $sisaTotal  = 0.0;
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
            $tanggal    = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal > $tanggalMax) {
                $tanggalMax = $tanggal;
            }
        }

        // PO kedua terbaru (selain yang terpilih) dan PO terbaru secara umum.
        $sebelum        = null;
        $tanggalSebelum = '';

        foreach ($poUnik as $item) {
            if ($poTerpilih !== null && (string) $item['no_po'] === (string) $poTerpilih['no_po']) {
                continue;
            }

            $tanggal = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal !== '' && $tanggal >= $tanggalSebelum) {
                $tanggalSebelum = $tanggal;
                $sebelum        = $item;
            }
        }

        $terakhir        = null;
        $tanggalTerakhir = '';

        foreach ($poUnik as $item) {
            $tanggal = (string) ($item['tanggal_dokumen'] ?? '');

            if ($tanggal !== '' && $tanggal >= $tanggalTerakhir) {
                $tanggalTerakhir = $tanggal;
                $terakhir        = $item;
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
     * Menyusun nilai kolom tabel sp_material untuk satu kode material.
     *
     * Dipakai import (dari baris berkas) maupun perhitungan ulang setelah stok
     * minimum pada daftar pantau diubah, karena itu argumennya nilai yang
     * sudah dibaca/dihitung, bukan berkas.
     *
     * Material yang belum punya catatan stok gudang dihitung seperti stok nol
     * (mengikuti dashboard lama: 110 material ber-stok nol + 39 tanpa catatan
     * = 149 REORDER), tetapi kolom stok_tersedia tetap dibiarkan kosong supaya
     * terlihat bahwa datanya memang belum ada.
     *
     * @param  string                           $kode         Kode material
     * @param  string|null                      $deskripsi    Deskripsi material
     * @param  float|null                       $stokMin      Stok minimum dari daftar pantau
     * @param  float|null                       $stokTersedia Stok tersedia (G001), null bila tidak ada
     * @param  array<string, mixed>|null        $g            Ringkasan stok gudang material
     * @param  array<int, array<string, mixed>> $prBaris      Seluruh baris PR material
     * @param  array<int, array<string, mixed>> $poBaris      Seluruh baris PO material
     * @return array<string, mixed>                           Nilai kolom sp_material
     */
    public function keadaanMaterial(
        string $kode,
        ?string $deskripsi,
        ?float $stokMin,
        ?float $stokTersedia,
        ?array $g,
        array $prBaris,
        array $poBaris
    ): array {
        $pilihan = $this->pilihDokumen($prBaris, $poBaris);
        $aksi    = $this->kunciAksi($pilihan['po_terbuka'], $pilihan['pr_terbuka'], $prBaris);
        $rekapPr = $this->rekapPr($prBaris, $pilihan['pr_terpilih']);
        $rekapPo = $this->rekapPo($poBaris, $pilihan['po_unik'], $pilihan['po_terpilih'], $prBaris);

        // Baris PR kosong dikenali dari status PR yang kosong, PO kosong dari
        // jumlah PO unik nol.
        $adaPr = (string) ($rekapPr['status'] ?? '') !== '';
        $adaPo = (int) ($rekapPo['uniq'] ?? 0) > 0;

        $reorder = $stokMin !== null && ($stokTersedia ?? 0.0) <= $stokMin;
        $penanda = [];

        if ($stokTersedia === null) {
            $penanda[] = 'tanpa catatan stok gudang';
        }

        if ($stokMin === null) {
            $penanda[] = 'stok minimum belum diisi';
        }

        $ket = ($adaPr || $adaPo)
            ? 'Sudah ada PR/PO'
            : ($reorder ? 'REORDER - belum ada PR/PO' : 'AMAN');

        $tanggalTerakhir = (string) ($rekapPo['tanggal_terakhir'] ?? '');

        return [
            'kode_material'   => $kode,
            'deskripsi'       => $this->potong($deskripsi, self::BATAS_DESKRIPSI),
            'stok_min'        => $stokMin,
            'stok_tersedia'   => $stokTersedia,
            'reorder'         => $reorder ? 1 : 0,
            'penanda'         => $this->potong(implode('; ', $penanda), self::BATAS_PENANDA),
            'aksi'            => $aksi,
            'pr_no'           => $this->potong((string) ($rekapPr['nomor'] ?? ''), self::BATAS_NO_DOKUMEN),
            'pr_status_aksi'  => $this->potong((string) ($rekapPr['status_aksi'] ?? ''), self::BATAS_PURCH),
            'pr_status'       => $this->potong((string) ($rekapPr['status'] ?? ''), self::BATAS_PR_STATUS),
            'pr_qty'          => $rekapPr['qty'],
            'pr_line'         => count($prBaris),
            'pr_terbuka'      => (int) ($rekapPr['terbuka_jml'] ?? 0),
            'pr_nums'         => $rekapPr['nums'],
            'req_list'        => implode('; ', (array) ($rekapPr['req'] ?? [])),
            'po_no'           => $this->potong((string) ($rekapPo['po_no'] ?? ''), self::BATAS_NO_DOKUMEN),
            'po_sisa'         => $rekapPo['po_sisa'],
            'po_line'         => count($poBaris),
            'po_uniq'         => (int) ($rekapPo['uniq'] ?? 0),
            'po_closed'       => (int) ($rekapPo['closed'] ?? 0),
            'po_open'         => (int) ($rekapPo['open'] ?? 0),
            'po_sisa_total'   => $rekapPo['sisa_total'],
            'po_tanggal'      => ((string) ($rekapPo['tanggal_max'] ?? '')) === '' ? null : $rekapPo['tanggal_max'],
            'po_tanggal_teks' => $tanggalTerakhir === ''
                ? null
                : strtoupper(date('d-M-Y', strtotime($tanggalTerakhir))),
            'po_nums'         => $rekapPo['nums'],
            'purch'           => $this->potong((string) ($rekapPo['purch'] ?? ''), self::BATAS_PURCH),
            'vendor'          => $this->potong((string) ($rekapPo['vendor'] ?? ''), self::BATAS_LOKASI),
            'po_prev'         => $this->potong((string) ($rekapPo['prev_no'] ?? ''), self::BATAS_NO_DOKUMEN),
            'vendor_prev'     => $this->potong((string) ($rekapPo['prev_vendor'] ?? ''), self::BATAS_LOKASI),
            'purch_last'      => $this->potong((string) ($rekapPo['last_ref'] ?? ''), self::BATAS_PURCH),
            'vendor_last'     => $this->potong((string) ($rekapPo['last_vendor'] ?? ''), self::BATAS_LOKASI),
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
            'ket'             => $this->potong($ket, self::BATAS_KET),
        ];
    }
}