<?php

namespace App\Services\Shift;

use App\Models\Report;
use App\Models\Technician;
use Carbon\CarbonInterface;

/**
 * Jam kerja teknisi non-shift (reguler dan reguler staff) beserta target
 * menitnya. Target dihitung dari jam kerja dikurangi istirahat, sehingga Jumat
 * (istirahat 2 jam) dan Sabtu (jam kerja lebih pendek) otomatis lebih kecil.
 *
 * Definisi jam kerja ada di config('cm.man_hour.jam_kerja') agar mudah diubah.
 */
class JamKerjaService
{
    /**
     * Definisi jam kerja untuk satu group teknisi (fallback: reguler).
     *
     * @param  string $grupTeknisi Group teknisi (reguler / reguler_staff)
     * @return array<string, mixed> Definisi jam kerja per hari (0 = Minggu)
     */
    public function definisi(string $grupTeknisi): array
    {
        $jamKerja = (array) config('cm.man_hour.jam_kerja', []);

        return (array) ($jamKerja[$grupTeknisi] ?? $jamKerja['reguler'] ?? []);
    }

    /**
     * Jam kerja pada satu tanggal.
     *
     * @param  string          $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal yang dicek
     * @return array<string, mixed>|null    ['mulai','selesai','istirahat'] atau null bila libur
     */
    public function hari(string $grupTeknisi, CarbonInterface $tanggal): ?array
    {
        $definisi = $this->definisi($grupTeknisi);
        $jadwal   = $definisi[$tanggal->dayOfWeek] ?? null;

        return is_array($jadwal) ? $jadwal : null;
    }

    /**
     * Target menit kerja non-shift pada satu tanggal.
     *
     * @param  string          $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal yang dihitung
     * @return int                          Target menit (0 bila libur)
     */
    public function targetMenit(string $grupTeknisi, CarbonInterface $tanggal): int
    {
        $jadwal = $this->hari($grupTeknisi, $tanggal);

        if ($jadwal === null) {
            return 0;
        }

        $menit = $this->menit((string) $jadwal['selesai']) - $this->menit((string) $jadwal['mulai']);

        foreach ((array) ($jadwal['istirahat'] ?? []) as $istirahat) {
            $menit -= $this->menit((string) $istirahat[1]) - $this->menit((string) $istirahat[0]);
        }

        return max(0, $menit);
    }

    /**
     * Jendela kerja (jam mulai & selesai) pada satu tanggal, dipakai untuk
     * memeriksa laporan di luar jam kerja.
     *
     * @param  string          $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal yang dicek
     * @return array{mulai: string, selesai: string}|null Jendela kerja atau null bila libur
     */
    public function jendela(string $grupTeknisi, CarbonInterface $tanggal): ?array
    {
        $jadwal = $this->hari($grupTeknisi, $tanggal);

        if ($jadwal === null) {
            return null;
        }

        return ['mulai' => (string) $jadwal['mulai'], 'selesai' => (string) $jadwal['selesai']];
    }

    /**
     * Label jam kerja satu hari, mis. "08.00-16.00" atau "Libur".
     *
     * @param  string          $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal yang dicek
     * @return string                       Label jam kerja
     */
    public function label(string $grupTeknisi, CarbonInterface $tanggal): string
    {
        $jendela = $this->jendela($grupTeknisi, $tanggal);

        if ($jendela === null) {
            return 'Libur';
        }

        return str_replace(':', '.', $jendela['mulai']) . '-' . str_replace(':', '.', $jendela['selesai']);
    }

    /**
     * Label group non-shift dari config, mis. "Reguler Staff".
     *
     * @param  string $grupTeknisi Group teknisi
     * @return string              Label group
     */
    public function labelGroup(string $grupTeknisi): string
    {
        $definisi = $this->definisi($grupTeknisi);

        return (string) ($definisi['label'] ?? $grupTeknisi);
    }

    /**
     * Jendela istirahat yang berlaku pada satu tanggal untuk satu group teknisi.
     *
     * Pemotongan istirahat HANYA berlaku untuk pekerja NON-SHIFT (reguler dan
     * reguler staff) sesuai keputusan pemilik sistem 13/10/2026. Pekerja shift
     * (grub A-D) dihitung 8 jam penuh tanpa potong istirahat (keputusan
     * 26/09/2026), dan hari libur group tersebut otomatis tidak punya jendela
     * istirahat. Daftar group non-shift diambil dari konstanta
     * Technician::GRUP_NON_SHIFT supaya tidak memakai pola "semua kecuali
     * reguler" (group non-shift ada dua).
     *
     * @param  string|null     $grupTeknisi Group teknisi (mis. reguler / grub_a)
     * @param  CarbonInterface $tanggal     Tanggal yang dicek
     * @return array<int, array<int, string>> Daftar jendela [jam_mulai, jam_selesai]
     */
    public function istirahatHari(?string $grupTeknisi, CarbonInterface $tanggal): array
    {
        if ($grupTeknisi === null || ! in_array($grupTeknisi, Technician::GRUP_NON_SHIFT, true)) {
            return [];
        }

        $jadwal = $this->hari($grupTeknisi, $tanggal);

        return $jadwal === null ? [] : (array) ($jadwal['istirahat'] ?? []);
    }

    /**
     * Menit istirahat yang MASUK ke dalam satu rentang jam kerja.
     *
     * Rentang yang melewati tengah malam (jam selesai lebih kecil dari jam
     * mulai, mis. 22:00-06:00) dihitung pada satu garis waktu seperti
     * Report::durasiDariJam(), sehingga jendela istirahat hari itu tetap
     * dinilai benar. Rentang yang tidak bersinggungan dengan jendela istirahat
     * menghasilkan 0 (mis. pekerjaan 08:00-11:00).
     *
     * @param  string|null     $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal pekerjaan
     * @param  string|null     $mulai       Jam mulai kerja format HH:MM
     * @param  string|null     $selesai     Jam selesai kerja format HH:MM (boleh 24:00)
     * @return int                          Menit istirahat yang dipotong (0 bila tidak ada)
     */
    public function menitIstirahat(?string $grupTeknisi, CarbonInterface $tanggal, ?string $mulai, ?string $selesai): int
    {
        $menitMulai   = Report::menitDariJam($mulai);
        $menitSelesai = Report::menitDariJam($selesai);

        // Rentang tidak sah (atau jam mulai 24:00) tidak dipotong: durasiDariJam()
        // juga menolak rentang seperti itu. Jam mulai = jam selesai BUKAN
        // rentang, jadi tidak ada istirahat yang bisa dihitung.
        if ($menitMulai === null || $menitSelesai === null || $menitMulai >= Report::MENIT_SEHARI) {
            return 0;
        }

        if ($menitMulai === $menitSelesai) {
            return 0;
        }

        $akhirRentang = $menitSelesai < $menitMulai
            ? $menitSelesai + Report::MENIT_SEHARI
            : $menitSelesai;

        $total = 0;

        foreach ($this->istirahatHari($grupTeknisi, $tanggal) as $jendela) {
            $awalJendela  = Report::menitDariJam((string) ($jendela[0] ?? ''));
            $akhirJendela = Report::menitDariJam((string) ($jendela[1] ?? ''));

            if ($awalJendela === null || $akhirJendela === null) {
                continue;
            }

            $total += max(0, min($akhirRentang, $akhirJendela) - max($menitMulai, $awalJendela));
        }

        return $total;
    }

    /**
     * Durasi kerja bersih (menit) dari rentang jam: selisih jam dikurangi
     * istirahat yang bersinggungan dengan rentang itu.
     *
     * Ini SATU sumber aturan "durasi laporan" untuk seluruh jalur (form edit
     * halaman detail, wizard Telegram, dan perintah penyamaan laporan lama):
     * pekerja non-shift dipotong istirahat (Senin-Sabtu 12:00-13:00; Jumat
     * 11:00-13:00), pekerja shift dan hari libur tidak dipotong. Selisih jamnya
     * sendiri tetap memakai Report::durasiDariJam() supaya aturan jam (lintas
     * tengah malam, 24:00, jam sama ditolak) tidak ditulis dua kali.
     *
     * @param  string|null     $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal pekerjaan
     * @param  string|null     $mulai       Jam mulai kerja format HH:MM
     * @param  string|null     $selesai     Jam selesai kerja format HH:MM (boleh 24:00)
     * @return int|null                     Durasi bersih dalam menit, null bila rentang tidak sah
     */
    public function durasiKerja(?string $grupTeknisi, CarbonInterface $tanggal, ?string $mulai, ?string $selesai): ?int
    {
        $durasi = Report::durasiDariJam($mulai, $selesai);

        if ($durasi === null) {
            return null;
        }

        return max(0, $durasi - $this->menitIstirahat($grupTeknisi, $tanggal, $mulai, $selesai));
    }

    /**
     * Ubah "HH:MM" menjadi menit sejak tengah malam.
     *
     * @param  string $jam Jam format HH:MM
     * @return int         Menit sejak 00:00
     */
    private function menit(string $jam): int
    {
        $potongan = explode(':', $jam);

        return ((int) ($potongan[0] ?? 0)) * 60 + ((int) ($potongan[1] ?? 0));
    }
}
