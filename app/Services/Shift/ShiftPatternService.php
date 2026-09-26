<?php

namespace App\Services\Shift;

use App\Models\Technician;
use App\Models\TechnicianShift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Proyeksi jadwal shift dari pola rotasi (siklus 20 hari) sehingga jadwal bulan
 * berikutnya tidak perlu diupload lagi. Hasil verifikasi pola ini:
 * 976 dari 976 hari-orang pada 4 file jadwal (26 Juni - 25 Oktober 2026) cocok.
 */
class ShiftPatternService
{
    /**
     * Siklus pola dalam bentuk daftar kode per hari.
     *
     * @return array<int, string> Kode shift urut hari dalam satu siklus
     */
    public function siklus(): array
    {
        $siklus = [];

        foreach ((array) config('cm.shift_pattern.siklus', []) as $blok) {
            $kode   = (string) ($blok[0] ?? '');
            $jumlah = (int) ($blok[1] ?? 0);

            if ($kode === '' || $jumlah <= 0) {
                continue;
            }

            $siklus = array_merge($siklus, array_fill(0, $jumlah, $kode));
        }

        return $siklus;
    }

    /**
     * Huruf group jadwal (A-D) untuk group teknisi di aplikasi.
     *
     * @param  string|null $grupTeknisi Group teknisi (mis. grub_a)
     * @return string|null              Huruf group jadwal atau null bila bukan pekerja shift
     */
    public function hurufGrup(?string $grupTeknisi): ?string
    {
        $peta = (array) config('cm.shift_pattern.grup_ke_huruf', []);

        return $peta[(string) $grupTeknisi] ?? null;
    }

    /**
     * Kode shift hasil proyeksi pola untuk satu group pada satu tanggal.
     *
     * @param  string|null     $grupTeknisi Group teknisi
     * @param  CarbonInterface $tanggal     Tanggal yang dicari
     * @return string|null                  Kode shift ('1','2','3','x') atau null bila bukan pekerja shift
     */
    public function kode(?string $grupTeknisi, CarbonInterface $tanggal): ?string
    {
        $huruf = $this->hurufGrup($grupTeknisi);

        if ($huruf === null) {
            return null;
        }

        $siklus = $this->siklus();

        if ($siklus === []) {
            return null;
        }

        $acuan   = Carbon::parse((string) config('cm.shift_pattern.acuan'))->startOfDay();
        $offset  = (int) (((array) config('cm.shift_pattern.offset', []))[$huruf] ?? 0);
        $selisih = (int) floor(($tanggal->copy()->startOfDay()->timestamp - $acuan->timestamp) / 86400);
        $jumlah  = count($siklus);
        $indeks  = (($selisih + $offset) % $jumlah + $jumlah) % $jumlah;

        return $siklus[$indeks];
    }

    /**
     * Proyeksi kode shift per tanggal untuk satu group dalam rentang tertentu.
     *
     * @param  string|null     $grupTeknisi Group teknisi
     * @param  CarbonInterface $mulai       Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai     Tanggal akhir (inklusif)
     * @return array<string, string>        Peta tanggal Y-m-d => kode shift
     */
    public function proyeksi(?string $grupTeknisi, CarbonInterface $mulai, CarbonInterface $selesai): array
    {
        $hasil   = [];
        $akhir   = $selesai->copy()->startOfDay();

        for ($tanggal = $mulai->copy()->startOfDay(); $tanggal->lte($akhir); $tanggal->addDay()) {
            $kode = $this->kode($grupTeknisi, $tanggal);

            if ($kode !== null) {
                $hasil[$tanggal->toDateString()] = $kode;
            }
        }

        return $hasil;
    }

    /**
     * Simpan hasil proyeksi pola ke tabel technician_shifts untuk semua teknisi
     * pekerja shift (group A-D) pada rentang tanggal tertentu. Baris dengan
     * (nik, tanggal) yang sama akan diperbarui, jadi bisa dijalankan berkali-kali.
     *
     * @param  CarbonInterface $mulai   Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai Tanggal akhir (inklusif)
     * @return int                      Jumlah baris yang ditulis
     */
    public function simpanProyeksi(CarbonInterface $mulai, CarbonInterface $selesai): int
    {
        $baris    = [];
        $sekarang = now();

        $grupShift = array_keys((array) config('cm.shift_pattern.grup_ke_huruf', []));

        $teknisi = Technician::query()
            ->whereIn('group', $grupShift)
            ->whereNotNull('nik')
            ->get(['id', 'nik', 'name', 'group']);

        foreach ($teknisi as $orang) {
            foreach ($this->proyeksi($orang->group, $mulai, $selesai) as $tanggal => $kode) {
                $baris[] = [
                    'shift_schedule_id' => null,
                    'technician_id'     => $orang->id,
                    'nik'               => (string) $orang->nik,
                    'nama'              => (string) $orang->name,
                    'group_code'        => $this->hurufGrup($orang->group),
                    'section_label'     => 'POLA',
                    'shift_date'        => $tanggal,
                    'shift_code'        => $kode,
                    'created_at'        => $sekarang,
                    'updated_at'        => $sekarang,
                ];
            }
        }

        foreach (array_chunk($baris, 500) as $potongan) {
            TechnicianShift::upsert(
                $potongan,
                ['nik', 'shift_date'],
                ['technician_id', 'nama', 'group_code', 'section_label', 'shift_code', 'updated_at']
            );
        }

        return count($baris);
    }
}
