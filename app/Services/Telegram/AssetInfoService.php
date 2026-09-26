<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\Company;
use App\Models\CmReading;
use App\Models\Department;
use App\Models\ItAsset;
use App\Models\Report;
use App\Models\SubArea;
use App\Services\FuncLocParser;
use App\Services\TechIdentSearchService;
use Illuminate\Support\Collection;

/**
 * AssetInfoService
 *
 * Asisten informasi perangkat untuk bot Telegram (perintah `/info`), berlaku
 * untuk SELURUH aset (perangkat IT maupun aset industri) - bukan hanya IT.
 *
 * Hirarki penelusuran mengikuti hirarki wizard laporan yang sudah matang
 * (lihat ClarificationService/ClarificationKeyboardBuilderTrait):
 *   Plant/Perusahaan -> Departemen -> Area -> Sub Area -> Section -> Tipe alat
 *   -> Equipment
 * Level Section hanya disisipkan bila jumlah alat pada satu scope melebihi
 * MAX_KEYBOARD (mengikuti perilaku nextLevelAfterAreaOrSubArea() di wizard),
 * dan level Tipe selalu ditampilkan lebih dulu sebelum daftar alat.
 *
 * Setiap keyboard selalu menyertakan tombol "Kembali" dan "Tutup" supaya
 * teknisi tidak pernah terjebak di dalam navigasi.
 *
 * Cara pencarian langsung (tanpa menelusuri hirarki):
 *   1. Alamat IP       -> /info 192.168.41.210
 *   2. Tag alat        -> /info CCTV-CES-3
 *   3. NVR + channel   -> /info CCTV NVR MO 3 Channel D13
 *   4. Nama pemakai    -> /info PC <nama pemakai>
 *   5. Tipe + lokasi   -> /info CCTV Unloading EPE
 *
 * Pesan dibalas dengan Markdown gaya Telegram, tanpa emoji.
 */
class AssetInfoService
{
    /**
     * Batas jumlah tombol alat per keyboard (selaras dengan MAX_KEYBOARD_OPTIONS
     * di ClarificationService).
     */
    public const MAX_KEYBOARD = 10;

    /**
     * @param  TechIdentSearchService  $search         Mesin pencarian alat yang dipakai wizard
     * @param  FuncLocParser           $funcLocParser  Pemecah kode TechIdentNo (section + tipe)
     */
    public function __construct(
        protected TechIdentSearchService $search,
        protected FuncLocParser $funcLocParser
    ) {
    }

    /**
     * Titik masuk perintah /info.
     *
     * @param  string  $query  Kata kunci setelah /info (boleh kosong)
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    public function search(string $query): array
    {
        $kata = trim($query);

        if ($kata === '') {
            return $this->plantLevel();
        }

        // 1. Alamat IP (khusus perangkat IT yang punya IP).
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $kata, $cocokIp)) {
            $hasil = ItAsset::where('ip_address', $cocokIp[1])->get();

            if ($hasil->isNotEmpty()) {
                return $this->candidatesMessage($hasil, 'IP ' . $cocokIp[1]);
            }
        }

        // 2. NVR + nomor channel.
        $channel = $this->detectChannel($kata);

        if ($channel !== null && stripos($kata, 'nvr') !== false) {
            $hasil = $this->findCctvByChannel($kata, $channel);

            if ($hasil->isNotEmpty()) {
                if ($hasil->count() === 1) {
                    return $this->detailForItAsset($hasil->first());
                }

                return $this->candidatesMessage($hasil, 'NVR/channel ' . $channel);
            }
        }

        // 3. Tag alat (tanda hubung dan spasi diabaikan saat mencocokkan).
        $kataPadat = strtoupper(str_replace(['-', ' '], '', $kata));
        $mirip     = Asset::whereNotNull('tech_ident_no')->get()->filter(
            fn (Asset $asset) => $kataPadat !== ''
                && str_contains(strtoupper(str_replace(['-', ' '], '', (string) $asset->tech_ident_no)), $kataPadat)
        )->values();

        if ($mirip->count() === 1) {
            return $this->detail((int) $mirip->first()->id);
        }

        if ($mirip->count() > 1) {
            return $this->candidatesMessageFromAssets($mirip, 'tag mengandung "' . $kata . '"');
        }

        // 4. Pencarian teks pada data IT: tag, nama pemakai, lokasi, dan seluruh
        //    nilai spec (pointing, NVR, channel, connect to).
        $hasilIt = $this->searchItAssets($kata);

        if ($hasilIt->count() === 1) {
            return $this->detailForItAsset($hasilIt->first());
        }

        if ($hasilIt->count() > 1) {
            return $this->candidatesMessage($hasilIt, 'pencarian "' . $kata . '"');
        }

        // 5. Mesin pencarian wizard untuk seluruh aset (IT maupun industri).
        $hasil  = $this->search->search($kata);
        $idAset = collect($hasil['candidates'] ?? [])->pluck('id')->filter()->values();

        if ($idAset->count() === 1) {
            return $this->detail((int) $idAset->first());
        }

        if ($idAset->isNotEmpty()) {
            $kandidat = Asset::with('functionalLocation')->whereIn('id', $idAset->all())->get();

            return $this->candidatesMessageFromAssets($kandidat, 'pencarian "' . $kata . '"');
        }

        return $this->notFound($kata);
    }

    /**
     * Pesan ketika tidak ada aset yang cocok, lengkap dengan contoh generik.
     *
     * @param  string  $kata  Kata kunci dari teknisi
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function notFound(string $kata): array
    {
        return [
            'message'  => "Tidak ada perangkat yang cocok dengan *" . $this->escape($kata) . "*.\n\n"
                . "Contoh pencarian:\n"
                . "- /info PC <nama pemakai>\n"
                . "- /info <tag alat>\n"
                . "- /info 192.168.41.210\n"
                . "- /info CCTV NVR 3 Channel D13\n"
                . "- /info CCTV Unloading EPE\n"
                . "- /info (tanpa kata kunci) untuk menelusuri hirarki alat",
            'keyboard' => [['text' => 'Hirarki Alat', 'callback_data' => 'info:root'], $this->closeButton()],
        ];
    }

    // =========================================================
    // HIRARKI — LEVEL 0: PLANT / PERUSAHAAN
    // =========================================================

    /**
     * Level 0: daftar Plant/Perusahaan yang punya aset terdaftar.
     *
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function plantLevel(): array
    {
        $tombol = [];

        foreach (Company::orderBy('name')->get() as $company) {
            $jumlah = Asset::where('company_id', $company->id)->count();

            if ($jumlah === 0) {
                continue;
            }

            $tombol[] = [
                'text'          => $company->name . ' (' . $jumlah . ' alat)',
                'callback_data' => 'info:co:' . $company->id,
            ];
        }

        if ($tombol === []) {
            return [
                'message'  => 'Belum ada aset yang terdaftar pada sistem.',
                'keyboard' => [$this->closeButton()],
            ];
        }

        $tombol[] = $this->closeButton();

        return [
            'message'  => "*Asisten Informasi Perangkat*\n\n"
                . "Kirim /info diikuti kata kunci, atau pilih Plant di bawah untuk menelusuri hirarki alat:\n"
                . "- /info PC <nama pemakai>\n"
                . "- /info <tag alat>\n"
                . "- /info 192.168.41.210\n"
                . "- /info CCTV Unloading EPE",
            'keyboard' => $tombol,
        ];
    }

    // =========================================================
    // HIRARKI — LEVEL 1: DEPARTEMEN
    // =========================================================

    /**
     * Level 1: daftar Departemen pada satu Plant.
     *
     * @param  int  $companyId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function departmentLevel(int $companyId): array
    {
        $company = Company::find($companyId);

        if ($company === null) {
            return $this->plantLevel();
        }

        $tombol = [];

        foreach (Department::where('company_id', $companyId)->orderBy('name')->get() as $department) {
            $jumlah = Asset::where('company_id', $companyId)
                ->where('department_id', $department->id)
                ->count();

            if ($jumlah === 0) {
                continue;
            }

            $tombol[] = [
                'text'          => $department->name . ' (' . $jumlah . ' alat)',
                'callback_data' => 'info:dp:' . $department->id,
            ];
        }

        if ($tombol === []) {
            return [
                'message'  => 'Belum ada aset pada *' . $this->escape($company->name) . '*.',
                'keyboard' => [['text' => 'Kembali', 'callback_data' => 'info:root'], $this->closeButton()],
            ];
        }

        $tombol[] = ['text' => 'Kembali', 'callback_data' => 'info:root'];
        $tombol[] = $this->closeButton();

        return [
            'message'  => '*' . $this->escape($company->name) . '* - pilih Departemen:',
            'keyboard' => $tombol,
        ];
    }

    // =========================================================
    // HIRARKI — LEVEL 2: AREA
    // =========================================================

    /**
     * Level 2: daftar Area pada satu Departemen, termasuk pintasan untuk aset
     * yang belum punya area (mis. sebagian perangkat IT).
     *
     * @param  int  $departmentId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function areaLevel(int $departmentId): array
    {
        $department = Department::find($departmentId);

        if ($department === null) {
            return $this->plantLevel();
        }

        $tombol = [];

        foreach (Area::where('department_id', $departmentId)->orderBy('code')->get() as $area) {
            $jumlah = Asset::where('department_id', $departmentId)->where('area_id', $area->id)->count();

            if ($jumlah === 0) {
                continue;
            }

            $tombol[] = [
                'text'          => $area->code . ' - ' . mb_substr((string) $area->name, 0, 24) . ' (' . $jumlah . ')',
                'callback_data' => 'info:ar:' . $area->id,
            ];
        }

        $tanpaArea = Asset::where('department_id', $departmentId)->whereNull('area_id')->count();

        if ($tanpaArea > 0) {
            $tombol[] = [
                'text'          => 'Tanpa Area (' . $tanpaArea . ' alat)',
                'callback_data' => 'info:na:' . $departmentId,
            ];
        }

        $kembali = ['text' => 'Kembali', 'callback_data' => 'info:co:' . $department->company_id];

        if ($tombol === []) {
            return [
                'message'  => 'Belum ada aset pada *' . $this->escape($department->name) . '*.',
                'keyboard' => [$kembali, $this->closeButton()],
            ];
        }

        $tombol[] = $kembali;
        $tombol[] = $this->closeButton();

        return [
            'message'  => '*' . $this->escape($department->name) . '* - pilih Area:',
            'keyboard' => $tombol,
        ];
    }

    // =========================================================
    // HIRARKI — LEVEL 3: SUB AREA
    // =========================================================

    /**
     * Level 3: daftar Sub Area pada satu Area.
     *
     * @param  int  $areaId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function subAreaLevel(int $areaId): array
    {
        $area = Area::find($areaId);

        if ($area === null) {
            return $this->plantLevel();
        }

        $tombol = [];
        $scope  = 'a_' . $areaId;

        foreach (SubArea::where('area_id', $areaId)->orderBy('code')->get() as $subArea) {
            $jumlah = Asset::where('sub_area_id', $subArea->id)->count();

            if ($jumlah === 0) {
                continue;
            }

            $tombol[] = [
                'text'          => $subArea->code . ' - ' . mb_substr((string) $subArea->name, 0, 24) . ' (' . $jumlah . ')',
                'callback_data' => 'info:sa:' . $subArea->id,
            ];
        }

        // Pintasan melihat seluruh alat pada area ini tanpa memilih sub area.
        $jumlahArea = $this->scopeAssets($scope)->count();

        if ($jumlahArea > 0) {
            $tombol[] = [
                'text'          => 'Semua alat di area ini (' . $jumlahArea . ')',
                'callback_data' => 'info:all:' . $scope,
            ];
        }

        $kembali = ['text' => 'Kembali', 'callback_data' => 'info:dp:' . $area->department_id];

        if ($tombol === []) {
            return [
                'message'  => 'Belum ada aset pada area *' . $this->escape((string) $area->code) . '*.',
                'keyboard' => [$kembali, $this->closeButton()],
            ];
        }

        $tombol[] = $kembali;
        $tombol[] = $this->closeButton();

        return [
            'message'  => 'Area *' . $this->escape((string) $area->code) . '* - pilih Sub Area '
                . 'atau "Semua alat di area ini":',
            'keyboard' => $tombol,
        ];
    }

    // =========================================================
    // HIRARKI — LEVEL 4-6: SECTION, TIPE, EQUIPMENT
    // =========================================================

    /**
     * Lanjutkan telusuran setelah sub area/scope terpilih: bila alat pada scope
     * terlalu banyak, minta pilih Section dulu; bila tipenya lebih dari satu,
     * minta pilih Tipe dulu; baru kemudian daftar alat.
     *
     * @param  string  $scope  Kode scope (c_/d_/a_/s_/n_ + id)
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function nextLevel(string $scope): array
    {
        $assets = $this->scopeAssets($scope);
        $jumlah = $assets->count();

        if ($jumlah === 0) {
            return [
                'message'  => 'Belum ada alat pada ' . $this->scopeLabel($scope) . '.',
                'keyboard' => [$this->upButton($scope), $this->closeButton()],
            ];
        }

        if ($jumlah > self::MAX_KEYBOARD) {
            return $this->sectionLevel($scope);
        }

        if ($this->daftarTipe($assets)->count() > 1) {
            return $this->typeLevel($scope, null);
        }

        return $this->equipmentLevel($scope, null, null);
    }

    /**
     * Level Section: dikelompokkan memakai FuncLocParser::groupBySectionAndType()
     * supaya urutannya sama dengan wizard laporan.
     *
     * @param  string  $scope
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function sectionLevel(string $scope): array
    {
        $grup   = $this->funcLocParser->groupBySectionAndType($this->scopeAssets($scope));
        $tombol = [];

        foreach ($grup as $section => $perTipe) {
            $jumlah = 0;

            foreach ($perTipe as $daftar) {
                $jumlah += count($daftar);
            }

            $tombol[] = [
                'text'          => 'Section ' . ((string) $section === '0000' ? 'tanpa kode' : $section)
                    . ' (' . $jumlah . ' alat)',
                'callback_data' => 'info:tx:' . $scope . ':' . $section,
            ];
        }

        $tombol[] = $this->upButton($scope);
        $tombol[] = $this->closeButton();

        return [
            'message'  => 'Alat pada ' . $this->scopeLabel($scope)
                . ' cukup banyak. Pilih Section dulu (kode 4 digit pada TechIdentNo):',
            'keyboard' => $tombol,
        ];
    }

    /**
     * Level Tipe alat - selalu ditampilkan sebelum daftar alat bila tipenya
     * lebih dari satu (mengikuti konsep hirarki wizard laporan).
     *
     * @param  string       $scope
     * @param  string|null  $section  Kode section terpilih (null = semua section)
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function typeLevel(string $scope, ?string $section): array
    {
        $assets = $this->assetsPerSection($this->scopeAssets($scope), $section);
        $tombol = [];

        foreach ($this->daftarTipe($assets) as $kode => $jumlah) {
            $labelTipe = ((string) $kode === '?')
                ? 'Tipe tidak terurai'
                : $this->funcLocParser->typeLabel((string) $kode);

            $tombol[] = [
                'text'          => $labelTipe . ' (' . $jumlah . ' alat)',
                'callback_data' => 'info:eq:' . $scope . ':' . ($section ?? '-') . ':' . $kode,
            ];
        }

        $kembali = $section !== null
            ? ['text' => 'Kembali', 'callback_data' => 'info:sc:' . $scope]
            : $this->upButton($scope);

        if ($tombol === []) {
            return [
                'message'  => 'Belum ada alat pada ' . $this->scopeLabel($scope) . '.',
                'keyboard' => [$kembali, $this->closeButton()],
            ];
        }

        $tombol[] = ['text' => 'Semua tipe', 'callback_data' => 'info:eq:' . $scope . ':' . ($section ?? '-') . ':-'];
        $tombol[] = $kembali;
        $tombol[] = $this->closeButton();

        return [
            'message'  => 'Pilih tipe alat pada ' . $this->scopeLabel($scope)
                . ($section !== null ? ' section ' . $section : '') . ':',
            'keyboard' => $tombol,
        ];
    }

    /**
     * Level Equipment: daftar alat (maksimum MAX_KEYBOARD tombol).
     *
     * @param  string       $scope
     * @param  string|null  $section  Kode section terpilih (null = tidak difilter)
     * @param  string|null  $type     Kode tipe alat terpilih (null = tidak difilter)
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function equipmentLevel(string $scope, ?string $section, ?string $type): array
    {
        $assets = $this->assetsPerSection($this->scopeAssets($scope), $section);

        if ($type !== null) {
            // Kode '?' adalah penanda fallback tipe yang tidak bisa diurai
            // (lihat FuncLocParser::groupBySectionAndType), jadi penyaringan
            // memakai penanda yang sama.
            $assets = $assets->filter(function (Asset $asset) use ($type) {
                $kode = $this->funcLocParser->extractEquipmentType((string) $asset->tech_ident_no) ?? '?';

                return $kode === $type;
            })->values();
        }

        $assets = $assets->sortBy('tech_ident_no')->values();
        $tombol = [];

        foreach ($assets->take(self::MAX_KEYBOARD) as $asset) {
            $label = (string) ($asset->tech_ident_no ?? '-');
            $desc  = trim((string) ($asset->description ?? ''));

            if ($desc !== '') {
                $label .= ' - ' . $desc;
            }

            $tombol[] = [
                'text'          => mb_substr($label, 0, 60),
                'callback_data' => 'info:dev:' . $asset->id,
            ];
        }

        if ($type !== null) {
            $kembali = ['text' => 'Kembali', 'callback_data' => 'info:tx:' . $scope . ':' . ($section ?? '-')];
        } elseif ($section !== null) {
            $kembali = ['text' => 'Kembali', 'callback_data' => 'info:sc:' . $scope];
        } else {
            $kembali = $this->upButton($scope);
        }

        if ($assets->isEmpty()) {
            return [
                'message'  => 'Belum ada alat pada ' . $this->scopeLabel($scope) . '.',
                'keyboard' => [$kembali, $this->closeButton()],
            ];
        }

        $tombol[] = $kembali;
        $tombol[] = $this->closeButton();

        $catatan = $assets->count() > self::MAX_KEYBOARD
            ? ' (menampilkan ' . self::MAX_KEYBOARD . ' dari ' . $assets->count() . ' alat, persempit lewat Section/Tipe)'
            : '';

        return [
            'message'  => 'Pilih alat pada ' . $this->scopeLabel($scope) . $catatan . ':',
            'keyboard' => $tombol,
        ];
    }

    // =========================================================
    // SCOPE: KUMPULAN ALAT, LABEL, TOMBOL NAVIGASI
    // =========================================================

    /**
     * Ambil seluruh aset pada satu scope.
     *
     * Kode scope: c_<companyId>, d_<departmentId>, a_<areaId>, s_<subAreaId>,
     * n_<departmentId> (aset pada departemen tersebut yang belum punya area).
     *
     * @param  string  $scope
     * @return Collection<int, Asset>
     */
    protected function scopeAssets(string $scope): Collection
    {
        $bagian = explode('_', $scope, 2);
        $jenis  = $bagian[0] ?? '';
        $id     = (int) ($bagian[1] ?? 0);

        if ($id === 0) {
            return collect();
        }

        $query = Asset::whereNotNull('tech_ident_no')->with(['functionalLocation', 'area', 'subArea']);

        return match ($jenis) {
            'c'     => $query->where('company_id', $id)->get(),
            'd'     => $query->where('department_id', $id)->get(),
            'a'     => $query->where('area_id', $id)->get(),
            's'     => $query->where('sub_area_id', $id)->get(),
            'n'     => $query->where('department_id', $id)->whereNull('area_id')->get(),
            default => collect(),
        };
    }

    /**
     * Label manusiawi untuk sebuah scope (dipakai pada judul pesan).
     *
     * @param  string  $scope
     * @return string
     */
    protected function scopeLabel(string $scope): string
    {
        $bagian = explode('_', $scope, 2);
        $jenis  = $bagian[0] ?? '';
        $id     = (int) ($bagian[1] ?? 0);

        return match ($jenis) {
            'c'     => 'Plant ' . (Company::find($id)?->name ?? '-'),
            'd'     => 'Departemen ' . (Department::find($id)?->name ?? '-'),
            'a'     => 'Area ' . (Area::find($id)?->code ?? '-'),
            's'     => 'Sub Area ' . (SubArea::find($id)?->code ?? '-'),
            'n'     => 'Departemen ' . (Department::find($id)?->name ?? '-') . ' (tanpa area)',
            default => 'lokasi ini',
        };
    }

    /**
     * Tombol naik satu level dari sebuah scope.
     *
     * @param  string  $scope
     * @return array{text: string, callback_data: string}
     */
    protected function upButton(string $scope): array
    {
        return ['text' => 'Kembali', 'callback_data' => 'info:up:' . $scope];
    }

    /**
     * Tombol menutup asisten informasi.
     *
     * @return array{text: string, callback_data: string}
     */
    protected function closeButton(): array
    {
        return ['text' => 'Tutup', 'callback_data' => 'info:close'];
    }

    /**
     * Saring aset berdasarkan kode section pada TechIdentNo.
     *
     * @param  Collection<int, Asset>  $assets
     * @param  string|null             $section
     * @return Collection<int, Asset>
     */
    protected function assetsPerSection(Collection $assets, ?string $section): Collection
    {
        if ($section === null || $section === '') {
            return $assets->values();
        }

        // Kode '0000' adalah penanda fallback untuk TechIdentNo yang section-nya
        // tidak bisa diurai (lihat FuncLocParser::groupBySectionAndType), jadi
        // penyaringan harus memakai penanda yang sama agar cocok.
        return $assets->filter(function (Asset $asset) use ($section) {
            $kode = $this->funcLocParser->extractSectionCode((string) $asset->tech_ident_no) ?? '0000';

            return $kode === $section;
        })->values();
    }

    /**
     * Hitung jumlah alat per kode tipe alat, diurutkan dari yang terbanyak.
     *
     * @param  Collection<int, Asset>  $assets
     * @return Collection<string, int>  Kode tipe => jumlah alat
     */
    protected function daftarTipe(Collection $assets): Collection
    {
        return $assets
            ->groupBy(fn (Asset $asset) => $this->funcLocParser->extractEquipmentType((string) $asset->tech_ident_no) ?? '?')
            ->map(fn (Collection $grup) => $grup->count())
            ->sortDesc();
    }

    // =========================================================
    // DETAIL ASET (BERLAKU UNTUK SEMUA ASET, BUKAN HANYA IT)
    // =========================================================

    /**
     * Susun pesan detail satu aset berdasarkan ID pada tabel assets.
     *
     * Aset yang merupakan cermin perangkat IT (punya it_asset_id) memakai detail
     * IT; aset industri memakai detail umum + riwayat laporan/pengecekan.
     *
     * @param  int  $assetId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    public function detail(int $assetId): array
    {
        $asset = Asset::with([
            'itAsset',
            'company',
            'department',
            'area',
            'subArea',
            'functionalLocation',
        ])->find($assetId);

        if ($asset === null) {
            return [
                'message'  => 'Alat tidak ditemukan.',
                'keyboard' => [['text' => 'Hirarki Alat', 'callback_data' => 'info:root'], $this->closeButton()],
            ];
        }

        return $asset->itAsset !== null
            ? $this->itDetail($asset)
            : $this->industrialDetail($asset);
    }

    /**
     * Detail untuk perangkat IT (dipanggil dari hasil pencarian ItAsset).
     *
     * @param  ItAsset  $itAsset
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function detailForItAsset(ItAsset $itAsset): array
    {
        $mirror = Asset::where('it_asset_id', $itAsset->id)->first();

        if ($mirror === null) {
            return $this->itDetailTanpaAset($itAsset);
        }

        return $this->detail((int) $mirror->id);
    }

    /**
     * Detail perangkat IT lengkap dengan riwayat laporan pada baris cerminnya.
     *
     * @param  Asset  $asset
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function itDetail(Asset $asset): array
    {
        $itAsset = $asset->itAsset;

        if ($itAsset === null) {
            return $this->industrialDetail($asset);
        }

        $baris   = [];
        $baris[] = '*' . $this->escape((string) ($itAsset->tag_name ?: $itAsset->displayName())) . '*';
        $baris[] = 'Tipe: ' . $this->escape($itAsset->deviceLabel() . ' (' . $itAsset->equipmentTypeLabel() . ')');

        if ($itAsset->name) {
            $baris[] = 'Nama/Pemakai: ' . $this->escape((string) $itAsset->name);
        }

        $merekModel = trim(implode(' ', array_filter([$itAsset->brand, $itAsset->model])));

        if ($merekModel !== '') {
            $baris[] = 'Merek/Model: ' . $this->escape($merekModel);
        }

        if ($itAsset->serial_number) {
            $baris[] = 'Serial: ' . $this->escape((string) $itAsset->serial_number);
        }

        if ($itAsset->ip_address) {
            $baris[] = 'IP: ' . $this->escape((string) $itAsset->ip_address);
        }

        $baris[] = 'Ping: ' . $this->pingSummary($itAsset);

        if ($asset->company) {
            $baris[] = 'PT: ' . $this->escape((string) $asset->company->name);
        }

        if ($asset->department) {
            $baris[] = 'Departemen: ' . $this->escape((string) $asset->department->name);
        }

        if ($itAsset->location) {
            $baris[] = 'Lokasi: ' . $this->escape((string) $itAsset->location);
        }

        if ($asset->functionalLocation) {
            $baris[] = 'FuncLoc: ' . $this->escape(
                (string) $asset->functionalLocation->code . ' - ' . (string) $asset->functionalLocation->name
            );
        }

        foreach ($this->itSpecificLines($itAsset) as $tambahan) {
            $baris[] = $tambahan;
        }

        foreach ($this->historyLines($asset) as $riwayat) {
            $baris[] = $riwayat;
        }

        $baris[] = '';
        $baris[] = 'Detail web: ' . rtrim((string) config('app.url'), '/') . '/it-assets/' . $itAsset->id;

        return [
            'message'  => implode("\n", $baris),
            'keyboard' => $this->detailKeyboard($asset),
        ];
    }
    /**
     * Detail perangkat IT yang belum punya baris cermin di tabel assets
     * (mis. belum disinkronkan) - tanpa tombol Laporan karena belum bisa ditautkan.
     *
     * @param  ItAsset  $itAsset
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function itDetailTanpaAset(ItAsset $itAsset): array
    {
        $baris   = [];
        $baris[] = '*' . $this->escape((string) ($itAsset->tag_name ?: $itAsset->displayName())) . '*';
        $baris[] = 'Tipe: ' . $this->escape($itAsset->deviceLabel() . ' (' . $itAsset->equipmentTypeLabel() . ')');

        if ($itAsset->name) {
            $baris[] = 'Nama/Pemakai: ' . $this->escape((string) $itAsset->name);
        }

        if ($itAsset->ip_address) {
            $baris[] = 'IP: ' . $this->escape((string) $itAsset->ip_address);
        }

        if ($itAsset->location) {
            $baris[] = 'Lokasi: ' . $this->escape((string) $itAsset->location);
        }

        foreach ($this->itSpecificLines($itAsset) as $tambahan) {
            $baris[] = $tambahan;
        }

        $baris[] = '';
        $baris[] = 'Catatan: perangkat ini belum tersinkron ke modul aset, jadi belum bisa dipakai untuk laporan.';

        return [
            'message'  => implode("\n", $baris),
            'keyboard' => [['text' => 'Hirarki Alat', 'callback_data' => 'info:root'], $this->closeButton()],
        ];
    }

    /**
     * Detail aset industri: lokasi lengkap, teknisi terakhir, kerusakan terakhir,
     * pengecekan terakhir (PM dan Condition Monitoring), serta laporan terakhir.
     *
     * @param  Asset  $asset
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function industrialDetail(Asset $asset): array
    {
        $baris   = [];
        $baris[] = '*' . $this->escape((string) ($asset->tech_ident_no ?: $asset->equipment_no ?: '-')) . '*';

        if ($asset->description) {
            $baris[] = $this->escape((string) $asset->description);
        }

        $kodeTipe = $this->funcLocParser->extractEquipmentType((string) $asset->tech_ident_no);

        if ($kodeTipe !== null) {
            $baris[] = 'Tipe: ' . $this->escape($this->funcLocParser->typeLabel($kodeTipe))
                . ' (' . $this->escape($kodeTipe) . ')';
        } elseif ($asset->equipment_type) {
            $baris[] = 'Tipe: ' . $this->escape((string) $asset->equipment_type);
        }

        $baris[] = 'Status: ' . $this->escape((string) ($asset->status ?? '-'));

        if ($asset->company) {
            $baris[] = 'PT: ' . $this->escape((string) $asset->company->name);
        }

        if ($asset->department) {
            $baris[] = 'Departemen: ' . $this->escape((string) $asset->department->name);
        }

        if ($asset->area) {
            $baris[] = 'Area: ' . $this->escape((string) $asset->area->code . ' - ' . (string) $asset->area->name);
        }

        if ($asset->subArea) {
            $baris[] = 'Sub Area: ' . $this->escape(
                (string) $asset->subArea->code . ' - ' . (string) $asset->subArea->name
            );
        }

        if ($asset->functionalLocation) {
            $baris[] = 'FuncLoc: ' . $this->escape(
                (string) $asset->functionalLocation->code . ' - ' . (string) $asset->functionalLocation->name
            );
        } elseif ($asset->functional_loc) {
            $baris[] = 'FuncLoc: ' . $this->escape((string) $asset->functional_loc);
        }

        foreach ($this->historyLines($asset) as $riwayat) {
            $baris[] = $riwayat;
        }

        $baris[] = '';
        $baris[] = 'Detail web: ' . rtrim((string) config('app.url'), '/') . '/assets/' . $asset->id;

        return [
            'message'  => implode("\n", $baris),
            'keyboard' => $this->detailKeyboard($asset),
        ];
    }


    /**
     * Susun baris riwayat perawatan aset: teknisi terakhir, kerusakan terakhir,
     * pengecekan PM terakhir, Condition Monitoring terakhir, dan laporan terakhir.
     * Baris hanya muncul bila datanya memang ada.
     *
     * @param  Asset  $asset
     * @return array<int, string>
     */
    protected function historyLines(Asset $asset): array
    {
        $laporan = $this->laporanAsset($asset, 3);
        $baris   = [];

        if ($laporan->isNotEmpty()) {
            $terakhir = $laporan->first();
            $tanggal  = $terakhir->report_date?->format('d/m/Y') ?? '-';
            $nama     = $terakhir->technician?->name ?? 'tanpa nama teknisi';

            $baris[] = '';
            $baris[] = 'Teknisi terakhir: ' . $this->escape($nama) . ' (' . $tanggal
                . ', ' . $this->escape($this->jenisLabel($terakhir)) . ')';

            // Bila tidak ada laporan CM/dCM, pakai laporan terakhir yang punya
            // catatan penyebab/pekerjaan agar teknisi tetap melihat informasinya.
            $rusak = $laporan->first(fn (Report $item) => $this->adalahPekerjaanPerbaikan($item))
                ?? $laporan->first(
                    fn (Report $item) => trim((string) ($item->root_cause ?: $item->work_description)) !== ''
                );

            if ($rusak !== null) {
                $sebab   = trim((string) ($rusak->root_cause ?: $rusak->work_description ?: ''));
                $baris[] = 'Kerusakan/pekerjaan terakhir: ' . ($rusak->report_date?->format('d/m/Y') ?? '-')
                    . ($sebab !== '' ? ' - ' . $this->escape(mb_substr($sebab, 0, 120)) : '');
            }

            $cek = $laporan->first(fn (Report $item) => $this->adalahPengecekan($item));

            if ($cek !== null) {
                $baris[] = 'Pengecekan (PM) terakhir: ' . ($cek->report_date?->format('d/m/Y') ?? '-')
                    . ' oleh ' . $this->escape($cek->technician?->name ?? 'tanpa nama teknisi');
            }

            $baris[] = 'Laporan terakhir:';

            foreach ($laporan as $item) {
                $baris[] = '  - ' . ($item->report_date?->format('d/m/Y') ?? '-')
                    . ' | ' . $this->escape($this->jenisLabel($item))
                    . ' | ' . $this->escape($item->technician?->name ?? '-');
            }
        }

        $cm = $this->cmTerakhir((string) $asset->tech_ident_no);

        if ($cm !== null) {
            $baris[] = 'Condition Monitoring terakhir: ' . ($cm->tanggal?->format('d/m/Y') ?? '-')
                . ' - kondisi ' . $this->escape(strtoupper((string) ($cm->kondisi ?? '-')));

            $ukur = [];

            if ($cm->max_vibration !== null) {
                $ukur[] = 'getaran maks ' . $cm->max_vibration . ' mm/s';
            }

            if ($cm->max_temp !== null) {
                $ukur[] = 'suhu maks ' . $cm->max_temp . ' C';
            }

            if ($ukur !== []) {
                $baris[] = '  (' . implode(', ', $ukur) . ')';
            }
        }

        return $baris;
    }


    /**
     * Ambil laporan terakhir sebuah aset (dicocokkan lewat asset_id atau
     * equipment_tag untuk data lama yang belum tertaut ke aset).
     *
     * @param  Asset  $asset
     * @param  int    $limit
     * @return Collection<int, Report>
     */
    protected function laporanAsset(Asset $asset, int $limit): Collection
    {
        $tag = (string) ($asset->tech_ident_no ?? '');

        return Report::with('technician')
            ->where(function ($query) use ($asset, $tag) {
                $query->where('asset_id', $asset->id);

                if ($tag !== '') {
                    $query->orWhere('equipment_tag', $tag);
                }
            })
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Ambil pengukuran Condition Monitoring terakhir untuk sebuah TechIdentNo.
     *
     * @param  string  $tag
     * @return CmReading|null
     */
    protected function cmTerakhir(string $tag): ?CmReading
    {
        if ($tag === '') {
            return null;
        }

        $equipment = CmEquipment::where('equipment_tag', $tag)->first();

        if ($equipment === null) {
            return null;
        }

        return $equipment->readings()
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Apakah laporan ini termasuk pekerjaan perbaikan (CM/dCM atau klasifikasi ZPM 1).
     *
     * @param  Report  $report
     * @return bool
     */
    protected function adalahPekerjaanPerbaikan(Report $report): bool
    {
        return in_array((string) $report->jenis_pekerjaan, ['CM', 'dCM'], true)
            || (string) $report->work_classification === 'zpm1';
    }

    /**
     * Apakah laporan ini termasuk pengecekan/pekerjaan terencana
     * (PM atau klasifikasi ZPM 3/6).
     *
     * @param  Report  $report
     * @return bool
     */
    protected function adalahPengecekan(Report $report): bool
    {
        return (string) $report->jenis_pekerjaan === 'PM'
            || in_array((string) $report->work_classification, ['zpm3', 'zpm6'], true);
    }

    /**
     * Label singkat jenis pekerjaan sebuah laporan untuk ditampilkan di Telegram.
     *
     * @param  Report  $report
     * @return string
     */
    protected function jenisLabel(Report $report): string
    {
        $jenis = trim((string) ($report->jenis_pekerjaan ?? ''));

        if ($jenis !== '') {
            return $jenis;
        }

        return match ((string) $report->work_classification) {
            'zpm1'  => 'CM',
            'zpm3'  => 'PM',
            'zpm5'  => 'Fabrikasi',
            'zpm6'  => 'PM terencana',
            'zpm7'  => 'Sipil',
            'zpm8'  => 'Project',
            default => '-',
        };
    }

    /**
     * Tombol aksi pada pesan detail aset: buat laporan, hirarki, dan tutup.
     *
     * @param  Asset  $asset
     * @return array<int, array{text: string, callback_data: string}>
     */
    protected function detailKeyboard(Asset $asset): array
    {
        return [
            ['text' => 'Buat Laporan', 'callback_data' => 'info:report:' . $asset->id],
            ['text' => 'Hirarki Alat', 'callback_data' => 'info:root'],
            $this->closeButton(),
        ];
    }
    // =========================================================
    // CALLBACK / ROUTER TOMBOL
    // =========================================================

    /**
     * Tangani callback dari tombol asisten (/info).
     *
     * Callback yang dikenali:
     *   info:root, info:close,
     *   info:co:<companyId>, info:dp:<departmentId>, info:ar:<areaId>,
     *   info:sa:<subAreaId>, info:na:<departmentId>,
     *   info:all:<scope>, info:sc:<scope>, info:tx:<scope>:<section>,
     *   info:eq:<scope>:<section>:<type>, info:up:<scope>,
     *   info:dev:<assetId>, info:report:<assetId> (ditangani PollTelegramUpdates).
     *
     * @param  string  $data  Data callback lengkap
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    public function handleCallback(string $data): array
    {
        $bagian = explode(':', $data);
        $aksi   = $bagian[1] ?? 'root';

        return match ($aksi) {
            'root'  => $this->plantLevel(),
            'co'    => $this->departmentLevel((int) ($bagian[2] ?? 0)),
            'dp'    => $this->areaLevel((int) ($bagian[2] ?? 0)),
            'ar'    => $this->subAreaLevel((int) ($bagian[2] ?? 0)),
            'sa'    => $this->nextLevel('s_' . (int) ($bagian[2] ?? 0)),
            'na'    => $this->nextLevel('n_' . (int) ($bagian[2] ?? 0)),
            'all'   => $this->nextLevel((string) ($bagian[2] ?? '')),
            'sc'    => $this->sectionLevel((string) ($bagian[2] ?? '')),
            'tx'    => $this->typeLevel((string) ($bagian[2] ?? ''), $this->kodeAtauNull($bagian[3] ?? null)),
            'eq'    => $this->equipmentLevel(
                (string) ($bagian[2] ?? ''),
                $this->kodeAtauNull($bagian[3] ?? null),
                $this->kodeAtauNull($bagian[4] ?? null)
            ),
            'up'    => $this->parentLevel((string) ($bagian[2] ?? '')),
            'dev'   => $this->detail((int) ($bagian[2] ?? 0)),
            'it'    => $this->detailItTanpaMirror((int) ($bagian[2] ?? 0)),
            'close' => $this->closedMessage(),
            default => $this->plantLevel(),
        };
    }

    /**
     * Detail perangkat IT yang belum punya baris cermin di tabel assets.
     *
     * @param  int  $itAssetId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function detailItTanpaMirror(int $itAssetId): array
    {
        $itAsset = ItAsset::find($itAssetId);

        if ($itAsset === null) {
            return [
                'message'  => 'Perangkat tidak ditemukan.',
                'keyboard' => [['text' => 'Hirarki Alat', 'callback_data' => 'info:root'], $this->closeButton()],
            ];
        }

        return $this->itDetailTanpaAset($itAsset);
    }

    /**
     * Naik satu level dari sebuah scope, mengikuti hirarki yang sama.
     *
     * @param  string  $scope
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function parentLevel(string $scope): array
    {
        $bagian = explode('_', $scope, 2);
        $jenis  = $bagian[0] ?? '';
        $id     = (int) ($bagian[1] ?? 0);

        if ($jenis === 'c') {
            return $this->plantLevel();
        }

        if ($jenis === 'd') {
            $companyId = (int) (Department::find($id)?->company_id ?? 0);

            return $companyId > 0 ? $this->departmentLevel($companyId) : $this->plantLevel();
        }

        if ($jenis === 'a') {
            $departmentId = (int) (Area::find($id)?->department_id ?? 0);

            return $departmentId > 0 ? $this->areaLevel($departmentId) : $this->plantLevel();
        }

        if ($jenis === 's') {
            $areaId = (int) (SubArea::find($id)?->area_id ?? 0);

            return $areaId > 0 ? $this->subAreaLevel($areaId) : $this->plantLevel();
        }

        if ($jenis === 'n') {
            return $this->areaLevel($id);
        }

        return $this->plantLevel();
    }

    /**
     * Pesan saat teknisi menekan tombol Tutup (keyboard otomatis dihapus oleh
     * TelegramSenderTrait::editMessageText karena tombolnya kosong).
     *
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function closedMessage(): array
    {
        return [
            'message'  => "Pencarian informasi ditutup.\nKetik /info kapan saja untuk membuka lagi.",
            'keyboard' => [],
        ];
    }

    /**
     * Ubah kode pada callback menjadi null bila kosong atau berupa tanda '-'.
     *
     * @param  string|null  $nilai
     * @return string|null
     */
    protected function kodeAtauNull(?string $nilai): ?string
    {
        $bersih = trim((string) $nilai);

        return ($bersih === '' || $bersih === '-') ? null : $bersih;
    }

    /**
     * Escape karakter khusus Markdown Telegram pada nilai dinamis.
     *
     * @param  string  $teks
     * @return string
     */
    protected function escape(string $teks): string
    {
        return str_replace(['_', '*', '`', '[', ']'], ['\\_', '\\*', '\\`', '\\[', '\\]'], $teks);
    }

    /**
     * Normalisasi teks: huruf besar, karakter selain huruf/angka menjadi spasi.
     *
     * @param  string  $teks
     * @return string
     */
    protected function normalize(string $teks): string
    {
        $bersih = preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper($teks)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $bersih) ?? '');
    }

    /**
     * Susun pesan daftar kandidat perangkat IT beserta tombol pilihannya.
     *
     * @param  Collection<int, ItAsset>  $assets   Daftar perangkat kandidat
     * @param  string                    $konteks  Keterangan singkat hasil pencarian
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function candidatesMessage(Collection $assets, string $konteks): array
    {
        $keyboard = [];

        foreach ($assets->take(self::MAX_KEYBOARD) as $asset) {
            $mirror = Asset::where('it_asset_id', $asset->id)->first();
            $label  = (string) ($asset->tag_name ?: $asset->displayName());

            if ($asset->location) {
                $label .= ' - ' . $asset->location;
            }

            if ($mirror !== null) {
                $keyboard[] = [
                    'text'          => mb_substr($label, 0, 60),
                    'callback_data' => 'info:dev:' . $mirror->id,
                ];

                continue;
            }

            $keyboard[] = [
                'text'          => mb_substr($label, 0, 60),
                'callback_data' => 'info:it:' . $asset->id,
            ];
        }

        $keyboard[] = ['text' => 'Hirarki Alat', 'callback_data' => 'info:root'];
        $keyboard[] = $this->closeButton();

        return [
            'message'  => 'Ditemukan *' . $assets->count() . '* perangkat untuk ' . $this->escape($konteks)
                . ". Pilih perangkat untuk melihat detail:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Susun pesan daftar kandidat aset (IT maupun industri) dari tabel assets.
     *
     * @param  Collection<int, Asset>  $assets
     * @param  string                  $konteks
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function candidatesMessageFromAssets(Collection $assets, string $konteks): array
    {
        $keyboard = [];

        foreach ($assets->take(self::MAX_KEYBOARD) as $asset) {
            $label = (string) ($asset->tech_ident_no ?: $asset->equipment_no ?: '-');
            $desc  = trim((string) ($asset->description ?? ''));

            if ($desc !== '') {
                $label .= ' - ' . $desc;
            }

            $keyboard[] = [
                'text'          => mb_substr($label, 0, 60),
                'callback_data' => 'info:dev:' . $asset->id,
            ];
        }

        $keyboard[] = ['text' => 'Hirarki Alat', 'callback_data' => 'info:root'];
        $keyboard[] = $this->closeButton();

        return [
            'message'  => 'Ditemukan *' . $assets->count() . '* alat untuk ' . $this->escape($konteks)
                . '. Pilih alat untuk melihat detail:',
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Ringkasan status ping perangkat IT.
     *
     * @param  ItAsset  $asset
     * @return string
     */
    protected function pingSummary(ItAsset $asset): string
    {
        if (! $asset->last_ping_status) {
            return 'belum pernah dicek';
        }

        $latency = $asset->last_ping_latency !== null
            ? ' (' . $asset->last_ping_latency . ' ms)'
            : '';

        return $this->escape((string) $asset->last_ping_status) . $latency;
    }

    /**
     * Baris tambahan khas tipe perangkat IT (CCTV, NVR, Switch/Access Point, PC).
     *
     * @param  ItAsset  $asset
     * @return array<int, string>
     */
    protected function itSpecificLines(ItAsset $asset): array
    {
        $baris = [];

        if ($asset->device_type === 'CCTV') {
            $nvr     = trim((string) ($asset->spec['show'] ?? ''));
            $channel = trim((string) ($asset->spec['channel'] ?? ''));

            if ($nvr !== '') {
                $baris[] = 'NVR: ' . $this->escape($nvr)
                    . ($channel !== '' ? ' | Channel: ' . $this->escape($channel) : '');
            }

            if (! empty($asset->spec['connect_to'])) {
                $baris[] = 'Connect To: ' . $this->escape((string) $asset->spec['connect_to']);
            }

            if (! empty($asset->spec['pointing'])) {
                $baris[] = 'Pointing: ' . $this->escape((string) $asset->spec['pointing']);
            }

            if ($asset->last_snapshot_at) {
                $baris[] = 'Snapshot terakhir: ' . $asset->last_snapshot_at->format('d/m/Y H:i');
            }
        }

        if ($asset->device_type === 'NVR') {
            if (! empty($asset->spec['channel'])) {
                $baris[] = 'Kapasitas channel: ' . $this->escape((string) $asset->spec['channel']);
            }

            $baris[] = 'Kamera memakai NVR ini: ' . ItAsset::where('device_type', 'CCTV')
                ->where('spec->show', (string) $asset->tag_name)
                ->count();

            if (! empty($asset->spec['connect_to'])) {
                $baris[] = 'Connect To: ' . $this->escape((string) $asset->spec['connect_to']);
            }
        }

        if (in_array($asset->device_type, ['SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED', 'ACCESS_POINT'], true)) {
            $baris[] = 'Kamera terhubung: ' . ItAsset::where('device_type', 'CCTV')
                ->where('spec->connect_to', (string) $asset->tag_name)
                ->count();

            if (! empty($asset->spec['connect_to'])) {
                $baris[] = 'Uplink ke: ' . $this->escape((string) $asset->spec['connect_to']);
            }
        }

        if ($asset->device_type === 'PC') {
            $ringkas = $asset->pcSpecSummary();

            if ($ringkas !== '') {
                $baris[] = 'Spesifikasi: ' . $this->escape($ringkas);
            }
        }

        return $baris;
    }

    /**
     * Deteksi nomor channel pada kata kunci, mis. "D13", "ch 4", "channel 7".
     *
     * @param  string  $kata
     * @return string|null  Kode channel format D<n>, null bila tidak disebut
     */
    protected function detectChannel(string $kata): ?string
    {
        if (preg_match('/\bD\s?(\d{1,2})\b/i', $kata, $cocok)) {
            return 'D' . (int) $cocok[1];
        }

        if (preg_match('/\bchan(?:nel)?\s*\.?\s*(\d{1,2})\b/i', $kata, $cocok)) {
            return 'D' . (int) $cocok[1];
        }

        return null;
    }

    /**
     * Cari kamera CCTV berdasarkan channel dan petunjuk nama NVR pada kata kunci.
     *
     * @param  string  $kata     Kata kunci lengkap
     * @param  string  $channel  Kode channel (mis. D13)
     * @return Collection<int, ItAsset>
     */
    protected function findCctvByChannel(string $kata, string $channel): Collection
    {
        $abaikan = ['CCTV', 'NVR', 'DVR', 'CHANNEL', 'CHANEL', 'CH', 'KAMERA', 'CAMERA', 'PADA', 'YANG', 'UNTUK', 'DI', 'KE', 'DAN'];
        $token   = array_values(array_filter(
            array_unique(explode(' ', $this->normalize($kata))),
            fn ($bagian) => strlen($bagian) >= 2
                && ! in_array($bagian, $abaikan, true)
                && $bagian !== strtoupper($channel)
                && ! ctype_digit($bagian)
        ));

        return ItAsset::with('functionalLocation')
            ->where('device_type', 'CCTV')
            ->get()
            ->filter(function (ItAsset $asset) use ($channel, $token) {
                if (strtoupper(trim((string) ($asset->spec['channel'] ?? ''))) !== $channel) {
                    return false;
                }

                $namaNvr = strtoupper(str_replace(' ', '', (string) ($asset->spec['show'] ?? '')));

                foreach ($token as $bagian) {
                    if (! str_contains($namaNvr, $bagian)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * Pencarian teks langsung pada data IT Assets: tag name, nama pemakai,
     * lokasi, plant/department, kode Functional Location, dan seluruh nilai spec
     * (pointing, NVR, channel, connect to).
     *
     * @param  string  $kata
     * @return Collection<int, ItAsset>  Kandidat dengan kecocokan terbanyak
     */
    protected function searchItAssets(string $kata): Collection
    {
        $token = array_values(array_filter(
            array_unique(explode(' ', $this->normalize($kata))),
            fn ($bagian) => strlen($bagian) >= 3
                && ! in_array($bagian, TechIdentSearchService::LOCATION_STOPWORDS, true)
                && ! in_array($bagian, TechIdentSearchService::NAME_TITLES, true)
        ));

        if ($token === []) {
            return collect();
        }

        $cocok = [];
        $tipe  = $this->detectType($kata);
        $plant = $this->detectPlant($kata);

        foreach (ItAsset::with('functionalLocation')->get() as $asset) {
            if ($tipe !== null && ! $this->tipeSama((string) $asset->device_type, $tipe)
                && ! $this->tipeSama($asset->equipmentTypeLabel(), $tipe)
                && ! $this->tipeSama($asset->deviceLabel(), $tipe)) {
                continue;
            }

            if ($plant !== null && ! empty($asset->plant) && strcasecmp((string) $asset->plant, $plant) !== 0) {
                continue;
            }

            $katakata = array_unique(array_filter(explode(' ', $this->assetHaystack($asset))));
            $jumlah   = 0;

            foreach ($token as $bagian) {
                if (in_array($bagian, $katakata, true) || $this->miripKata($bagian, $katakata)) {
                    $jumlah++;
                }
            }

            if ($jumlah > 0) {
                $cocok[] = ['asset' => $asset, 'jumlah' => $jumlah];
            }
        }

        if ($cocok === []) {
            return collect();
        }

        $maks = max(array_column($cocok, 'jumlah'));

        return collect(array_values(array_filter($cocok, fn ($baris) => $baris['jumlah'] === $maks)))
            ->map(fn ($baris) => $baris['asset'])
            ->values();
    }

    /**
     * Gabungkan seluruh kolom yang bisa dicari pada satu perangkat IT menjadi
     * satu teks ternormalisasi.
     *
     * @param  ItAsset  $asset
     * @return string
     */
    protected function assetHaystack(ItAsset $asset): string
    {
        $bagian = [
            (string) $asset->tag_name,
            (string) $asset->name,
            (string) $asset->brand,
            (string) $asset->model,
            (string) $asset->serial_number,
            (string) $asset->ip_address,
            (string) $asset->plant,
            (string) $asset->department,
            (string) $asset->location,
            (string) $asset->application,
            $asset->deviceLabel(),
            $asset->equipmentTypeLabel(),
        ];

        if ($asset->functionalLocation !== null) {
            $bagian[] = (string) $asset->functionalLocation->code;
            $bagian[] = (string) $asset->functionalLocation->name;
        }

        foreach (($asset->spec ?? []) as $nilai) {
            if (! is_array($nilai)) {
                $bagian[] = (string) $nilai;
            }
        }

        return $this->normalize(implode(' ', array_filter($bagian)));
    }

    /**
     * Kenali PT yang disebut pada kata kunci (EPE/NPA/CES) sebagai kata utuh.
     *
     * @param  string  $kata
     * @return string|null
     */
    protected function detectPlant(string $kata): ?string
    {
        $padat = ' ' . $this->normalize($kata) . ' ';

        foreach (['EPE', 'NPA', 'CES'] as $pt) {
            if (str_contains($padat, ' ' . $pt . ' ')) {
                return $pt;
            }
        }

        return null;
    }

    /**
     * Bandingkan dua nama tipe dengan mengabaikan spasi, garis bawah, dan besar
     * kecil huruf (mis. "SWITCH_MANAGED" sama dengan "Switch Managed").
     *
     * @param  string  $satu
     * @param  string  $dua
     * @return bool
     */
    protected function tipeSama(string $satu, string $dua): bool
    {
        $bersih = fn (string $teks) => strtoupper(str_replace([' ', '_', '-'], '', $teks));

        return $bersih($satu) === $bersih($dua);
    }

    /**
     * Kenali tipe perangkat IT yang disebut pada kata kunci (mis. "CCTV",
     * "Switch", "Laptop"), dipakai untuk mempersempit hasil pencarian.
     *
     * @param  string  $kata
     * @return string|null  Tipe perangkat, null bila tidak disebut
     */
    protected function detectType(string $kata): ?string
    {
        $padat    = ' ' . $this->normalize($kata) . ' ';
        $tipeData = ItAsset::query()->distinct()->pluck('device_type')->filter()->values();

        foreach ($tipeData as $tipe) {
            if (str_contains($padat, ' ' . $this->normalize((string) $tipe) . ' ')) {
                return (string) $tipe;
            }
        }

        $alias = TechIdentSearchService::TYPE_ALIASES;
        uksort($alias, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($alias as $tipe => $daftar) {
            foreach ($daftar as $kataAlias) {
                if (! str_contains($padat, ' ' . $this->normalize((string) $kataAlias) . ' ')) {
                    continue;
                }

                $adaDiData = $tipeData->first(fn ($nilai) => $this->tipeSama((string) $nilai, (string) $tipe));

                if ($adaDiData !== null) {
                    return (string) $adaDiData;
                }
            }
        }

        return null;
    }

    /**
     * Toleransi typo satu huruf, hanya untuk kata 7 huruf ke atas (mis.
     * "Uloading" vs "Unloading").
     *
     * @param  string              $token
     * @param  array<int, string>  $katakata
     * @return bool
     */
    protected function miripKata(string $token, array $katakata): bool
    {
        if (strlen($token) < 7) {
            return false;
        }

        foreach ($katakata as $kata) {
            if (abs(strlen($kata) - strlen($token)) <= 2 && levenshtein($token, $kata) <= 1) {
                return true;
            }
        }

        return false;
    }

}
