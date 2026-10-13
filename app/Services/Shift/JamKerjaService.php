<?php

namespace App\Services\Shift;

use App\Models\Report;
use App\Models\Technician;
use App\Models\TechnicianShift;
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
     * Baris jadwal (shift) satu teknisi untuk satu rentang tanggal.
     *
     * Jadwal hasil import SELALU menang (dipakai untuk koreksi manual), sedangkan
     * tanggal yang belum punya baris diisi proyeksi pola rotasi 20 hari
     * (ShiftPatternService). Pekerja non-shift tidak punya baris jadwal sama
     * sekali, jadi jendela kerjanya diambil dari config jam kerja harian
     * (lihat jendelaKerja()).
     *
     * @param  Technician|null $teknisi Teknisi pemilik jadwal
     * @param  CarbonInterface $mulai   Tanggal awal (inklusif)
     * @param  CarbonInterface $selesai Tanggal akhir (inklusif)
     * @return array<string, TechnicianShift> Peta tanggal Y-m-d => baris jadwal
     */
    public function jadwalRentang(?Technician $teknisi, CarbonInterface $mulai, CarbonInterface $selesai): array
    {
        if ($teknisi === null) {
            return [];
        }

        $awal  = $mulai->copy()->startOfDay();
        $akhir = $selesai->copy()->startOfDay();

        $jadwal = TechnicianShift::query()
            ->where('technician_id', $teknisi->id)
            ->antara($awal->toDateString(), $akhir->toDateString())
            ->get()
            ->keyBy(fn (TechnicianShift $shift): string => $shift->shift_date->toDateString())
            ->all();

        foreach (app(ShiftPatternService::class)->proyeksi($teknisi->group, $awal, $akhir) as $tanggalPola => $kodePola) {
            if (isset($jadwal[$tanggalPola])) {
                continue;
            }

            $jadwal[$tanggalPola] = new TechnicianShift([
                'technician_id' => $teknisi->id,
                'shift_date'    => $tanggalPola,
                'shift_code'    => $kodePola,
            ]);
        }

        return $jadwal;
    }

    /**
     * Baris jadwal satu teknisi pada satu tanggal, null bila tidak ada.
     *
     * @param  Technician|null $teknisi Teknisi yang dicari
     * @param  CarbonInterface $tanggal Tanggal yang dicari
     * @return TechnicianShift|null     Baris jadwal atau null
     */
    public function jadwalHari(?Technician $teknisi, CarbonInterface $tanggal): ?TechnicianShift
    {
        return $this->jadwalRentang($teknisi, $tanggal, $tanggal)[$tanggal->toDateString()] ?? null;
    }

    /**
     * Apakah group teknisi termasuk group yang punya aturan jam kerja di
     * aplikasi (pekerja shift A-D atau non-shift reguler/reguler staff).
     *
     * @param  string|null $grupTeknisi Group teknisi
     * @return bool                     True bila aturannya dikenal
     */
    public function grupDikenal(?string $grupTeknisi): bool
    {
        return $grupTeknisi !== null
            && in_array($grupTeknisi, array_merge(Technician::GRUP_SHIFT, Technician::GRUP_NON_SHIFT), true);
    }

    /**
     * Jendela kerja BRUTO (jam mulai & selesai) satu teknisi pada satu tanggal.
     *
     * Urutan: jadwal shift hari itu (import atau proyeksi pola) -> jam kerja
     * harian non-shift dari config. Hasil null berarti hari libur/OFF, sehingga
     * SELURUH jam kerja pada hari itu dihitung lembur.
     *
     * @param  Technician|null $teknisi Teknisi yang dinilai
     * @param  CarbonInterface $tanggal Tanggal pekerjaan
     * @return array{mulai: string, selesai: string}|null Jendela kerja atau null
     */
    public function jendelaKerja(?Technician $teknisi, CarbonInterface $tanggal): ?array
    {
        return $this->jendelaDariJadwal($this->jadwalHari($teknisi, $tanggal), $teknisi?->group, $tanggal);
    }

    /**
     * Menit satu rentang jam yang jatuh DI DALAM satu jendela kerja.
     *
     * Memakai garis waktu yang sama dengan Report::durasiDariJam() (rentang yang
     * melewati tengah malam memakai akhir +24 jam) supaya irisan dengan jam kerja
     * dan dengan jam istirahat dihitung dengan cara yang sama.
     *
     * @param  array{mulai: string, selesai: string}|null $jendela Jendela kerja
     * @param  string|null $mulai   Jam mulai kerja format HH:MM
     * @param  string|null $selesai Jam selesai kerja format HH:MM (boleh 24:00)
     * @return int                  Menit yang berada di dalam jendela
     */
    public function menitDalamJendela(?array $jendela, ?string $mulai, ?string $selesai): int
    {
        if ($jendela === null) {
            return 0;
        }

        $menitMulai   = Report::menitDariJam($mulai);
        $menitSelesai = Report::menitDariJam($selesai);
        $awalJendela  = Report::menitDariJam((string) ($jendela['mulai'] ?? ''));
        $akhirJendela = Report::menitDariJam((string) ($jendela['selesai'] ?? ''));

        if ($menitMulai === null || $menitSelesai === null || $awalJendela === null || $akhirJendela === null) {
            return 0;
        }

        if ($menitMulai >= Report::MENIT_SEHARI || $menitMulai === $menitSelesai) {
            return 0;
        }

        $akhirRentang = $menitSelesai < $menitMulai
            ? $menitSelesai + Report::MENIT_SEHARI
            : $menitSelesai;

        return max(0, min($akhirRentang, $akhirJendela) - max($menitMulai, $awalJendela));
    }

    /**
     * Ringkasan jam kerja satu rentang laporan.
     *
     * Aturan (diputuskan user 13/10/2026, disempurnakan pada hari yang sama):
     *   - jam kerja NORMAL = menit di dalam jendela kerja bruto hari itu
     *     DIKURANGI istirahat (istirahat hanya berlaku pekerja non-shift),
     *   - LEMBUR = seluruh menit di LUAR jendela kerja bruto (sebelum jam masuk
     *     dan sesudah jam pulang) DITAMBAH seluruh jam pada hari libur/OFF.
     *     Jam ISTIRAHAT bukan lembur (diputuskan user 13/10/2026): menit itu
     *     terpotong dari durasi sehingga tidak dihitung di mana pun. Akibatnya
     *     total rentang = normal + lembur + istirahat, dan aturan ini berlaku
     *     untuk pekerja shift juga (jam di luar jendela shift = lembur).
     *   - Group teknisi yang tidak dikenal (mis. belum diisi) tidak dinilai:
     *     seluruh menit dianggap jam kerja normal supaya angka tidak berubah.
     *
     * @param  TechnicianShift|null $shift   Baris jadwal hari itu (boleh null)
     * @param  string|null          $grup    Group teknisi
     * @param  CarbonInterface      $tanggal Tanggal pekerjaan
     * @param  string|null          $mulai   Jam mulai kerja format HH:MM
     * @param  string|null          $selesai Jam selesai kerja format HH:MM
     * @return array{total: int|null, normal: int|null, lembur: int, istirahat: int, jendela: array|null, libur: bool, aturan: bool}
     */
    public function ringkasJamKerja(
        ?TechnicianShift $shift,
        ?string $grup,
        CarbonInterface $tanggal,
        ?string $mulai,
        ?string $selesai
    ): array {
        $total = Report::durasiDariJam($mulai, $selesai);

        if ($total === null) {
            return [
                'total'     => null,
                'normal'    => null,
                'lembur'    => 0,
                'istirahat' => 0,
                'jendela'   => null,
                'libur'     => false,
                'aturan'    => false,
            ];
        }

        if (! $this->grupDikenal($grup)) {
            return [
                'total'     => $total,
                'normal'    => $total,
                'lembur'    => 0,
                'istirahat' => 0,
                'jendela'   => null,
                'libur'     => false,
                'aturan'    => false,
            ];
        }

        $jendela      = $this->jendelaDariJadwal($shift, $grup, $tanggal);
        $dalamJendela = $this->menitDalamJendela($jendela, $mulai, $selesai);

        // Istirahat yang BENAR-BENAR dipotong = irisan jendela istirahat dengan
        // jendela kerja hari itu. Istirahat di luar jendela kerja (mis. rentang
        // yang hanya menyentuh jam istirahat pada hari libur) tidak pernah
        // mengurangi apa pun dan tidak pernah menjadi lembur.
        $istirahat = min($this->menitIstirahat($grup, $tanggal, $mulai, $selesai), $dalamJendela);

        return [
            'total'     => $total,
            'normal'    => max(0, $dalamJendela - $istirahat),
            // Lembur = menit di luar jendela kerja saja. Jam istirahat TIDAK
            // dihitung lembur (keputusan user 13/10/2026) sehingga durasi
            // otomatis terpotong dan tidak ada menit yang dihitung dua kali.
            'lembur'    => max(0, $total - $dalamJendela),
            'istirahat' => $istirahat,
            'jendela'   => $jendela,
            'libur'     => $jendela === null,
            'aturan'    => true,
        ];
    }

    /**
     * Durasi kerja NORMAL (menit) dari rentang jam: menit di dalam jendela kerja
     * hari itu dikurangi istirahat.
     *
     * Ini satu-satunya angka yang disimpan di reports.work_duration_minutes;
     * LEMBUR dihitung terpisah lewat ringkasJamKerja() karena tidak menambah man
     * hour normal (diputuskan user 13/10/2026).
     *
     * @param  Technician|null $teknisi Teknisi pemilik laporan
     * @param  CarbonInterface $tanggal Tanggal pekerjaan
     * @param  string|null     $mulai   Jam mulai kerja format HH:MM
     * @param  string|null     $selesai Jam selesai kerja format HH:MM (boleh 24:00)
     * @return int|null                 Durasi normal dalam menit, null bila rentang tidak sah
     */
    public function durasiKerja(?Technician $teknisi, CarbonInterface $tanggal, ?string $mulai, ?string $selesai): ?int
    {
        return $this->ringkasJamKerja(
            $this->jadwalHari($teknisi, $tanggal),
            $teknisi?->group,
            $tanggal,
            $mulai,
            $selesai
        )['normal'];
    }

    /**
     * Jendela kerja dari baris jadwal, atau dari jam kerja harian bila teknisi
     * tidak punya baris jadwal (pekerja reguler / reguler staff atau tanggal di
     * luar jadwal import).
     *
     * @param  TechnicianShift|null $shift   Baris jadwal hari itu
     * @param  string|null          $grup    Group teknisi
     * @param  CarbonInterface      $tanggal Tanggal yang dinilai
     * @return array{mulai: string, selesai: string}|null Jendela kerja atau null
     */
    private function jendelaDariJadwal(?TechnicianShift $shift, ?string $grup, CarbonInterface $tanggal): ?array
    {
        if ($shift !== null) {
            return $shift->jendela($grup);
        }

        if ($grup === null) {
            return null;
        }

        return $this->jendela($grup, $tanggal);
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
