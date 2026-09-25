<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;

/**
 * AssetNumberService
 *
 * Pembuat nomor equipment otomatis untuk asset baru yang tidak menyertakan
 * nomornya. Dipakai dua jalur:
 *   - import Excel (baris tanpa kolom Equipment No), lihat ImportService;
 *   - penambahan manual di halaman Asset Management (kolom Equipment No
 *     dikosongkan), lihat AssetController::store().
 *
 * Aturan nomor (KEPUTUSAN BISNIS, jangan diubah tanpa instruksi user):
 *   - panjang 8 digit (config assets.equipment_no.panjang);
 *   - mengikuti seri milik PT bersangkutan, diambil dari angka pertama nomor
 *     yang sudah ada (contoh nyata EPE = 1xxxxxxx, CES = 6xxxxxxx,
 *     NPA = 7xxxxxxx);
 *   - dilanjutkan dari nomor TERBESAR pada seri tersebut;
 *   - nomor yang sudah terpakai otomatis dilewati;
 *   - awalan cadangan per kode PT di config('assets.equipment_no.awalan_pt')
 *     dipakai bila PT tersebut belum punya nomor sama sekali.
 */
class AssetNumberService
{
    /**
     * Buat nomor equipment otomatis untuk sebuah PT.
     *
     * @param  int|null  $companyId  Id PT pemilik asset; boleh null bila lokasi
     *                               tidak terhubung ke master Company
     * @return string  Nomor equipment (8 digit) yang belum dipakai
     */
    public function generateForCompanyId(?int $companyId): string
    {
        $panjang = (int) config('assets.equipment_no.panjang', 8);
        $awalan  = $this->awalanForCompanyId($companyId);

        $query = Asset::whereNotNull('equipment_no')->where('equipment_no', '!=', '');

        if ($awalan !== null) {
            $query->where('equipment_no', 'like', $awalan . '%');
        }

        $terbesar = $query->max('equipment_no');

        // Belum ada nomor sama sekali pada seri ini: mulai dari 1 diikuti nol.
        $nomor = ($terbesar !== null && ctype_digit((string) $terbesar))
            ? (int) $terbesar + 1
            : (int) str_pad((string) ($awalan ?? '1'), $panjang, '0', STR_PAD_RIGHT);

        return (string) str_pad((string) $this->lewatkanNomorTerpakai($nomor), $panjang, '0', STR_PAD_LEFT);
    }

    /**
     * Buat nomor equipment otomatis berdasarkan lokasi (FuncLoc) yang dipilih.
     *
     * PT ditentukan dari relasi node ke master Company; bila node belum
     * terhubung (banyak node hasil sinkron belum punya company_id), PT diambil
     * dari awalan kode FuncLoc yang berformat COMPANY-DEPT-AREA-SUBAREA.
     *
     * @param  FunctionalLocation|null  $funcLoc  Lokasi terpilih; boleh null
     * @return string  Nomor equipment (8 digit) yang belum dipakai
     */
    public function generateForFuncLoc(?FunctionalLocation $funcLoc): string
    {
        return $this->generateForCompanyId($this->companyIdForFuncLoc($funcLoc));
    }

    /**
     * Tentukan id PT pemilik sebuah node FuncLoc.
     *
     * Dipakai bersama oleh pembuat nomor dan penentu kolom lokasi asset baru:
     * bila relasi node ke master Company kosong, PT diambil dari awalan kode
     * FuncLoc (banyak node hasil sinkron belum punya company_id).
     *
     * @param  FunctionalLocation|null  $funcLoc  Lokasi terpilih; boleh null
     * @return int|null  Id PT, atau null bila tidak bisa ditentukan
     */
    public function companyIdForFuncLoc(?FunctionalLocation $funcLoc): ?int
    {
        if ($funcLoc === null) {
            return null;
        }

        return $funcLoc->company_id ?? $this->companyIdDariKodeFuncLoc($funcLoc->code);
    }

    /**
     * Kode PT pemilik sebuah node FuncLoc (untuk ditampilkan di form).
     *
     * @param  FunctionalLocation|null  $funcLoc  Lokasi terpilih; boleh null
     * @return string|null  Kode PT, atau null bila tidak bisa ditentukan
     */
    public function kodePtForFuncLoc(?FunctionalLocation $funcLoc): ?string
    {
        $companyId = $this->companyIdForFuncLoc($funcLoc);

        if ($companyId === null) {
            return null;
        }

        return Company::where('id', $companyId)->value('code');
    }

    /**
     * Cari id PT dari awalan kode FuncLoc (format COMPANY-DEPT-AREA-SUBAREA).
     *
     * Dipakai karena sebagian besar node FuncLoc belum punya company_id,
     * sehingga seri nomor per PT tidak bisa ditentukan dari relasi saja.
     *
     * @param  string|null  $kode  Kode FuncLoc, mis. "EPE-PROD-TF01-6010"
     * @return int|null  Id Company yang cocok dengan awalan kode, atau null
     */
    public function companyIdDariKodeFuncLoc(?string $kode): ?int
    {
        $kode = trim((string) $kode);

        if ($kode === '' || ! str_contains($kode, '-')) {
            return null;
        }

        $awalan = explode('-', $kode)[0];

        if ($awalan === '') {
            return null;
        }

        return Company::where('code', $awalan)->value('id');
    }

    /**
     * Tentukan awalan nomor equipment untuk sebuah PT.
     *
     * Awalan diambil dari seri yang paling banyak dipakai PT tersebut; bila PT
     * belum punya nomor sama sekali, dipakai awalan cadangan dari config.
     *
     * @param  int|null  $companyId  Id PT; boleh null
     * @return string|null  Satu digit awalan, atau null bila tidak bisa ditentukan
     */
    public function awalanForCompanyId(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $awalan = Asset::where('company_id', $companyId)
            ->whereNotNull('equipment_no')
            ->where('equipment_no', '!=', '')
            ->selectRaw('SUBSTR(equipment_no, 1, 1) as digit, COUNT(*) as jumlah')
            ->groupBy('digit')
            ->orderByDesc('jumlah')
            ->value('digit');

        if ($awalan !== null && ctype_digit((string) $awalan)) {
            return (string) $awalan;
        }

        return $this->awalanCadanganUntukPerusahaan($companyId);
    }

    /**
     * Awalan cadangan berdasarkan kode PT pada config assets.equipment_no.awalan_pt.
     *
     * @param  int  $companyId  Id PT
     * @return string|null  Satu digit awalan, atau null bila tidak ada di config
     */
    private function awalanCadanganUntukPerusahaan(int $companyId): ?string
    {
        $kodePt = Company::where('id', $companyId)->value('code');

        if ($kodePt === null) {
            return null;
        }

        $cadangan = (array) config('assets.equipment_no.awalan_pt', []);

        return isset($cadangan[$kodePt]) ? (string) $cadangan[$kodePt] : null;
    }

    /**
     * Naikkan nomor sampai menemukan nomor yang belum dipakai.
     *
     * @param  int  $nomor  Nomor usulan hasil hitungan seri
     * @return int  Nomor yang benar-benar bebas
     */
    private function lewatkanNomorTerpakai(int $nomor): int
    {
        while (Asset::where('equipment_no', (string) $nomor)->exists()) {
            $nomor++;
        }

        return $nomor;
    }
}
