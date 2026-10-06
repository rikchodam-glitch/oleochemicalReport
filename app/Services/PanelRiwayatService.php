<?php

namespace App\Services;

use App\Models\PanelRuangan;
use Carbon\CarbonInterface;

/**
 * PanelRiwayatService
 *
 * Menyusun rekap pembacaan ruang panel per HARI dari tabel panel_pembacaan.
 * Rekap ini dipakai halaman detail ruangan (tabel riwayat harian) dan ekspor
 * Excel-nya, sehingga perhitungan min/max/rata-rata hanya ada di satu tempat.
 *
 * Semua perhitungan dikerjakan di sisi database (MIN/MAX/AVG + CASE WHEN) supaya
 * satu bulan data tidak perlu diambil baris per baris ke memori PHP.
 */
class PanelRiwayatService
{
    /**
     * Rekap harian pembacaan satu ruangan pada rentang tanggal tertentu.
     *
     * Batas tanggal memakai jam (00:00:00 s/d 23:59:59) supaya perilakunya sama
     * di MySQL maupun SQLite: kolom `waktu` bertipe datetime, sehingga memakai
     * tanggal polos membuat hari terakhir rentang tidak ikut terhitung.
     *
     * @param  PanelRuangan     $ruangan  Ruangan yang direkap
     * @param  CarbonInterface  $awal     Tanggal awal (inklusif)
     * @param  CarbonInterface  $akhir    Tanggal akhir (inklusif)
     * @return array<int, array<string, mixed>> Baris rekap per hari, urut tanggal menaik
     */
    public function harian(PanelRuangan $ruangan, CarbonInterface $awal, CarbonInterface $akhir): array
    {
        // Ambang batas ruangan dipakai menghitung berapa pembacaan yang berada di
        // luar rentang nyaman. Bila ambang belum diisi, dipakai nilai yang tidak
        // mungkin terlewati supaya hitungannya tetap nol (tanpa error).
        $atas  = $ruangan->ambang_suhu_atas ?? 9999.0;
        $bawah = $ruangan->ambang_suhu_bawah ?? -9999.0;

        $baris = $ruangan->pembacaan()
            ->whereBetween('waktu', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->selectRaw('DATE(waktu) as tanggal')
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('MIN(suhu) as suhu_min, MAX(suhu) as suhu_max, AVG(suhu) as suhu_rata')
            ->selectRaw('MIN(kelembapan) as kelembapan_min, MAX(kelembapan) as kelembapan_max, AVG(kelembapan) as kelembapan_rata')
            ->selectRaw('SUM(CASE WHEN suhu > ? THEN 1 ELSE 0 END) as jumlah_panas', [(float) $atas])
            ->selectRaw('SUM(CASE WHEN suhu < ? THEN 1 ELSE 0 END) as jumlah_dingin', [(float) $bawah])
            ->groupByRaw('DATE(waktu)')
            ->orderByRaw('DATE(waktu) ASC')
            ->get();

        return $baris
            ->map(fn ($rekap): array => $this->rapikan($rekap))
            ->all();
    }

    /**
     * Total ringkas seluruh baris rekap (dipakai keterangan di atas tabel).
     *
     * @param  array<int, array<string, mixed>>  $harian  Hasil harian()
     * @return array<string, mixed> Ringkasan: jumlah_pembacaan, jumlah_hari, jumlah_panas, jumlah_dingin
     */
    public function ringkasan(array $harian): array
    {
        $pembacaan = 0;
        $panas     = 0;
        $dingin    = 0;

        foreach ($harian as $baris) {
            $pembacaan += (int) ($baris['jumlah'] ?? 0);
            $panas     += (int) ($baris['jumlah_panas'] ?? 0);
            $dingin    += (int) ($baris['jumlah_dingin'] ?? 0);
        }

        return [
            'jumlah_hari'       => count($harian),
            'jumlah_pembacaan'  => $pembacaan,
            'jumlah_panas'      => $panas,
            'jumlah_dingin'     => $dingin,
        ];
    }

    /**
     * Rapikan satu baris hasil agregasi database menjadi array siap tampil.
     *
     * @param  object  $rekap  Baris hasil query agregasi
     * @return array<string, mixed>
     */
    private function rapikan(object $rekap): array
    {
        $jumlahPanas  = (int) ($rekap->jumlah_panas ?? 0);
        $jumlahDingin = (int) ($rekap->jumlah_dingin ?? 0);

        return [
            'tanggal'          => (string) $rekap->tanggal,
            'jumlah'           => (int) $rekap->jumlah,
            'suhu_min'         => $rekap->suhu_min === null ? null : round((float) $rekap->suhu_min, 2),
            'suhu_max'         => $rekap->suhu_max === null ? null : round((float) $rekap->suhu_max, 2),
            'suhu_rata'        => $rekap->suhu_rata === null ? null : round((float) $rekap->suhu_rata, 2),
            'kelembapan_min'   => $rekap->kelembapan_min === null ? null : round((float) $rekap->kelembapan_min, 2),
            'kelembapan_max'   => $rekap->kelembapan_max === null ? null : round((float) $rekap->kelembapan_max, 2),
            'kelembapan_rata'  => $rekap->kelembapan_rata === null ? null : round((float) $rekap->kelembapan_rata, 2),
            'jumlah_panas'     => $jumlahPanas,
            'jumlah_dingin'    => $jumlahDingin,
            'keterangan'       => $this->keterangan($jumlahPanas, $jumlahDingin),
        ];
    }

    /**
     * Keterangan kondisi satu hari berdasarkan jumlah pembacaan di luar ambang.
     *
     * @param  int  $jumlahPanas   Jumlah pembacaan di atas ambang atas
     * @param  int  $jumlahDingin  Jumlah pembacaan di bawah ambang bawah
     * @return string
     */
    private function keterangan(int $jumlahPanas, int $jumlahDingin): string
    {
        if ($jumlahPanas > 0 && $jumlahDingin > 0) {
            return 'Pernah terlalu panas dan terlalu dingin';
        }

        if ($jumlahPanas > 0) {
            return 'Pernah terlalu panas';
        }

        if ($jumlahDingin > 0) {
            return 'Pernah terlalu dingin';
        }

        return 'Suhu normal';
    }
}
