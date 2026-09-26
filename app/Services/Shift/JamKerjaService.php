<?php

namespace App\Services\Shift;

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
