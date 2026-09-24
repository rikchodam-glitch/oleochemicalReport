<?php

namespace App\Services\Telegram;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use App\Services\TechIdentSearchService;
use Illuminate\Support\Collection;

/**
 * ItInfoService
 *
 * Asisten informasi perangkat untuk bot Telegram (perintah `/info`). Berbeda
 * dari wizard laporan, alur ini berdiri sendiri: tidak menyimpan state, tidak
 * membuat laporan, dan bisa dipakai kapan saja.
 *
 * Cara pencarian yang didukung:
 *   1. Alamat IP          -> /info 192.168.41.210
 *   2. Tag alat           -> /info CCTV-CES-3
 *   3. NVR + channel      -> /info CCTV NVR MO 3 Channel D13
 *   4. Nama pemakai       -> /info PC Hafidz
 *   5. Tipe + lokasi      -> /info CCTV Unloading EPE
 *   6. Tanpa kata kunci   -> /info  (membuka hirarki PT -> Departemen -> alat)
 *
 * Pesan dibalas dengan Markdown gaya Telegram, tanpa emoji.
 */
class ItInfoService
{
    /**
     * @param  TechIdentSearchService  $search  Mesin pencarian alat yang dipakai wizard
     */
    public function __construct(protected TechIdentSearchService $search)
    {
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
            return $this->hierarchyRoot();
        }

        // 1. Alamat IP.
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $kata, $cocokIp)) {
            $hasil = ItAsset::with('functionalLocation')->where('ip_address', $cocokIp[1])->get();

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
                    return $this->deviceDetail($hasil->first());
                }

                return $this->candidatesMessage($hasil, 'NVR/channel ' . $channel);
            }
        }

        // 3. Tag alat (tanda hubung dan spasi diabaikan saat mencocokkan).
        $kataPadat = strtoupper(str_replace(['-', ' '], '', $kata));
        $mirip     = ItAsset::with('functionalLocation')->get()->filter(
            fn (ItAsset $asset) => $kataPadat !== ''
                && str_contains(strtoupper(str_replace(['-', ' '], '', (string) $asset->tag_name)), $kataPadat)
        )->values();

        if ($mirip->count() === 1) {
            return $this->deviceDetail($mirip->first());
        }

        if ($mirip->count() > 1) {
            return $this->candidatesMessage($mirip, 'tag mengandung "' . $kata . '"');
        }

        // 4. Pencarian teks pada data IT: tag, nama pemakai, lokasi, dan nilai
        //    spec (pointing, NVR, channel, connect to).
        $hasilIt = $this->searchItAssets($kata);

        if ($hasilIt->count() === 1) {
            return $this->deviceDetail($hasilIt->first());
        }

        if ($hasilIt->count() > 1) {
            return $this->candidatesMessage($hasilIt, 'pencarian "' . $kata . '"');
        }

        // 5. Mesin pencarian wizard untuk aset industri. Hasilnya memakai
        //    assets.id, jadi dipetakan dulu ke it_assets lewat it_asset_id.
        $hasil  = $this->search->search($kata);
        $idAset = collect($hasil['candidates'] ?? [])->pluck('id')->filter()->values();
        $idIt   = Asset::whereIn('id', $idAset->all())->pluck('it_asset_id')->filter()->values();

        if ($idIt->isNotEmpty()) {
            $daftar = ItAsset::with('functionalLocation')->whereIn('id', $idIt->all())->get();

            if ($daftar->count() === 1) {
                return $this->deviceDetail($daftar->first());
            }

            return $this->candidatesMessage($daftar, 'pencarian "' . $kata . '"');
        }

        // 5b. Aset industri yang tidak punya pasangan di IT Assets.
        if ($idAset->isNotEmpty()) {
            return $this->industrialMessage($idAset->all(), $kata);
        }

        return [
            'message'  => "Tidak ada perangkat yang cocok dengan *" . $this->escape($kata) . "*.\n\n"
                . "Contoh pencarian:\n"
                . "- /info PC Hafidz\n"
                . "- /info CCTV-CES-3\n"
                . "- /info 192.168.41.210\n"
                . "- /info CCTV NVR MO 3 Channel D13\n"
                . "- /info CCTV Unloading EPE\n"
                . "- /info (tanpa kata kunci) untuk membuka hirarki alat",
            'keyboard' => $this->hierarchyButtons(),
        ];
    }

    /**
     * Susun pesan daftar kandidat beserta tombol pilihannya.
     *
     * @param  Collection  $assets   Daftar perangkat kandidat
     * @param  string      $konteks  Keterangan singkat hasil pencarian
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function candidatesMessage(Collection $assets, string $konteks): array
    {
        $keyboard = [];

        foreach ($assets->take(8) as $asset) {
            $label = (string) ($asset->tag_name ?: $asset->displayName());

            if ($asset->location) {
                $label .= ' - ' . $asset->location;
            }

            $keyboard[] = [
                'text'          => mb_substr($label, 0, 60),
                'callback_data' => 'info:dev:' . $asset->id,
            ];
        }

        $keyboard[] = ['text' => 'Hirarki Alat', 'callback_data' => 'info:root'];

        return [
            'message'  => 'Ditemukan *' . $assets->count() . '* perangkat untuk ' . $this->escape($konteks)
                . ". Pilih perangkat untuk melihat detail:",
            'keyboard' => $keyboard,
        ];
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
                && !in_array($bagian, $abaikan, true)
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
     * Susun pesan detail lengkap satu perangkat.
     *
     * @param  ItAsset  $asset
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    public function deviceDetail(ItAsset $asset): array
    {
        $asset->loadMissing('functionalLocation');

        $baris   = [];
        $baris[] = '*' . $this->escape((string) ($asset->tag_name ?: $asset->displayName())) . '*';
        $baris[] = 'Tipe: ' . $this->escape($asset->deviceLabel() . ' (' . $asset->equipmentTypeLabel() . ')');

        if ($asset->name) {
            $baris[] = 'Nama/Pemakai: ' . $this->escape($asset->name);
        }

        $merekModel = trim(implode(' ', array_filter([$asset->brand, $asset->model])));

        if ($merekModel !== '') {
            $baris[] = 'Merek/Model: ' . $this->escape($merekModel);
        }

        if ($asset->serial_number) {
            $baris[] = 'Serial: ' . $this->escape($asset->serial_number);
        }

        if ($asset->ip_address) {
            $baris[] = 'IP: ' . $this->escape($asset->ip_address);
        }

        $baris[] = 'Ping: ' . $this->pingSummary($asset);

        if ($asset->plant || $asset->department) {
            $baris[] = 'Plant/Dept: ' . $this->escape(trim(implode(' / ', array_filter([$asset->plant, $asset->department]))));
        }

        if ($asset->location) {
            $baris[] = 'Lokasi: ' . $this->escape($asset->location);
        }

        if ($asset->functionalLocation) {
            $baris[] = 'FuncLoc: ' . $this->escape(
                $asset->functionalLocation->code . ' - ' . $asset->functionalLocation->name
            );
        }

        foreach ($this->deviceSpecificLines($asset) as $tambahan) {
            $baris[] = $tambahan;
        }

        $baris[] = '';
        $baris[] = 'Detail web: ' . rtrim((string) config('app.url'), '/') . '/it-assets/' . $asset->id;

        return [
            'message'  => implode("\n", $baris),
            'keyboard' => [
                ['text' => 'Hirarki Alat', 'callback_data' => 'info:root'],
            ],
        ];
    }

    /**
     * Ringkasan status ping perangkat.
     *
     * @param  ItAsset  $asset
     * @return string
     */
    protected function pingSummary(ItAsset $asset): string
    {
        if (! $asset->last_ping_status) {
            return 'belum pernah dicek';
        }

        $latency = $asset->last_ping_latency !== null ? ' (' . $asset->last_ping_latency . ' ms)' : '';
        $waktu   = $asset->last_ping_at ? $asset->last_ping_at->format('d/m/Y H:i') : '';

        return $this->escape(ucfirst((string) $asset->last_ping_status) . $latency) . ($waktu !== '' ? ' - ' . $waktu : '');
    }

    /**
     * Escape karakter khusus Markdown Telegram pada nilai dinamis.
     *
     * @param  string  $teks
     * @return string
     */
    protected function escape(string $teks): string
    {
        return str_replace(['_', '*', '`', '[', ']'], ['\_', '\*', '\`', '\[', '\]'], $teks);
    }

    /**
     * Baris tambahan khas tipe perangkat (CCTV, NVR, Switch/Access Point, PC).
     *
     * @param  ItAsset  $asset
     * @return array<int, string>
     */
    protected function deviceSpecificLines(ItAsset $asset): array
    {
        $baris = [];

        if ($asset->device_type === 'CCTV') {
            $nvr     = trim((string) ($asset->spec['show'] ?? ''));
            $channel = trim((string) ($asset->spec['channel'] ?? ''));

            if ($nvr !== '') {
                $baris[] = 'NVR: ' . $this->escape($nvr) . ($channel !== '' ? ' | Channel: ' . $this->escape($channel) : '');
            }

            if (!empty($asset->spec['connect_to'])) {
                $baris[] = 'Connect To: ' . $this->escape((string) $asset->spec['connect_to']);
            }

            if (!empty($asset->spec['pointing'])) {
                $baris[] = 'Pointing: ' . $this->escape((string) $asset->spec['pointing']);
            }

            if ($asset->last_snapshot_at) {
                $baris[] = 'Snapshot terakhir: ' . $asset->last_snapshot_at->format('d/m/Y H:i');
            }
        }

        if ($asset->device_type === 'NVR') {
            if (!empty($asset->spec['channel'])) {
                $baris[] = 'Kapasitas channel: ' . $this->escape((string) $asset->spec['channel']);
            }

            $baris[] = 'Kamera memakai NVR ini: ' . ItAsset::where('device_type', 'CCTV')
                ->where('spec->show', (string) $asset->tag_name)
                ->count();

            if (!empty($asset->spec['connect_to'])) {
                $baris[] = 'Connect To: ' . $this->escape((string) $asset->spec['connect_to']);
            }
        }

        if (in_array($asset->device_type, ['SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED', 'ACCESS_POINT'], true)) {
            $baris[] = 'Kamera terhubung: ' . ItAsset::where('device_type', 'CCTV')
                ->where('spec->connect_to', (string) $asset->tag_name)
                ->count();

            if (!empty($asset->spec['connect_to'])) {
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
     * Tombol hirarki tingkat PT untuk pesan pembuka /info.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    protected function hierarchyButtons(): array
    {
        $tombol = [];

        foreach (['EPE', 'NPA', 'CES'] as $kode) {
            $jumlah = ItAsset::whereHas(
                'functionalLocation',
                fn ($query) => $query->where('code', 'like', $kode . '-%')
            )->count();

            $tombol[] = ['text' => $kode . ' (' . $jumlah . ' alat)', 'callback_data' => 'info:pt:' . $kode];
        }

        return $tombol;
    }

    /**
     * Pesan pembuka hirarki alat (perintah /info tanpa kata kunci).
     *
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function hierarchyRoot(): array
    {
        return [
            'message'  => "*Asisten Perangkat IT*\n\n"
                . "Kirim /info diikuti kata kunci, atau pilih PT di bawah untuk menelusuri hirarki alat:\n"
                . "- /info PC Hafidz\n"
                . "- /info CCTV-CES-3\n"
                . "- /info 192.168.41.210\n"
                . "- /info CCTV NVR MO 3 Channel D13\n"
                . "- /info CCTV Unloading EPE",
            'keyboard' => $this->hierarchyButtons(),
        ];
    }

    /**
     * Daftar departemen (FuncLoc level 1) pada satu PT beserta jumlah alat.
     *
     * @param  string  $pt  Kode PT (EPE/NPA/CES)
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function byPlant(string $pt): array
    {
        $departemen = FunctionalLocation::where('level', FunctionalLocation::LEVEL_DEPARTMENT)
            ->where('code', 'like', $pt . '-%')
            ->orderBy('code')
            ->get();

        $tombol = [];

        foreach ($departemen as $node) {
            $jumlah = ItAsset::whereHas(
                'functionalLocation',
                fn ($query) => $query->where('code', 'like', $node->code . '%')
            )->count();

            $tombol[] = [
                'text'          => $node->code . ' (' . $jumlah . ' alat)',
                'callback_data' => 'info:loc:' . $node->id,
            ];
        }

        $tombol[] = ['text' => 'Kembali ke PT', 'callback_data' => 'info:root'];

        return [
            'message'  => '*' . $this->escape($pt) . '* - pilih departemen:',
            'keyboard' => $tombol,
        ];
    }

    /**
     * Daftar alat di bawah satu node Functional Location (termasuk anaknya).
     *
     * @param  int  $funclocId
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function byFuncloc(int $funclocId): array
    {
        $node = FunctionalLocation::find($funclocId);

        if ($node === null) {
            return ['message' => 'Lokasi tidak ditemukan.', 'keyboard' => $this->hierarchyButtons()];
        }

        $daftar = ItAsset::with('functionalLocation')
            ->whereIn('funcloc_id', $node->getSubtreeIds())
            ->orderBy('device_type')
            ->orderBy('tag_name')
            ->limit(12)
            ->get();

        $tombol = [];

        foreach ($daftar as $asset) {
            $tombol[] = [
                'text'          => mb_substr($asset->deviceLabel() . ': ' . (string) $asset->tag_name, 0, 60),
                'callback_data' => 'info:dev:' . $asset->id,
            ];
        }

        $pt       = explode('-', (string) $node->code)[0] ?? 'EPE';
        $tombol[] = ['text' => 'Kembali ke ' . $pt, 'callback_data' => 'info:pt:' . $pt];

        $pesan = $daftar->isEmpty()
            ? 'Belum ada alat terdaftar di ' . $this->escape((string) $node->code) . '.'
            : 'Alat di *' . $this->escape((string) $node->code) . '* (' . $daftar->count() . ' dari maksimal 12):';

        return ['message' => $pesan, 'keyboard' => $tombol];
    }

    /**
     * Tangani callback dari tombol asisten (/info).
     *
     * Callback yang dikenali: info:root, info:pt:EPE,
     * info:loc:<functional_location_id>, info:dev:<it_asset_id>.
     *
     * @param  string  $data  Data callback lengkap
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    public function handleCallback(string $data): array
    {
        $bagian = explode(':', $data);
        $aksi   = $bagian[1] ?? 'root';
        $nilai  = $bagian[2] ?? '';

        if ($aksi === 'pt') {
            return $this->byPlant(strtoupper($nilai));
        }

        if ($aksi === 'loc') {
            return $this->byFuncloc((int) $nilai);
        }

        if ($aksi === 'dev') {
            $asset = ItAsset::with('functionalLocation')->find((int) $nilai);

            if ($asset === null) {
                return ['message' => 'Perangkat tidak ditemukan.', 'keyboard' => $this->hierarchyButtons()];
            }

            return $this->deviceDetail($asset);
        }

        return $this->hierarchyRoot();
    }

    /**
     * Ringkasan singkat untuk aset industri (bukan perangkat IT) yang muncul
     * dari pencarian, karena aset ini tidak punya halaman detail IT.
     *
     * @param  array<int, int>  $assetIds
     * @param  string           $kata
     * @return array{message: string, keyboard: array<int, array{text: string, callback_data: string}>}
     */
    protected function industrialMessage(array $assetIds, string $kata): array
    {
        $aset = Asset::with('functionalLocation')->whereIn('id', $assetIds)->take(5)->get();

        $baris = ['Hasil pencarian "' . $this->escape($kata) . '" (aset industri):', ''];

        foreach ($aset as $item) {
            $baris[] = '* ' . $this->escape((string) ($item->tech_ident_no ?? '-'))
                . ' - ' . $this->escape((string) ($item->description ?? '-'));
            $baris[] = '    FuncLoc: ' . $this->escape((string) ($item->functionalLocation?->code ?? '-'));

            if ($item->equipment_type) {
                $baris[] = '    Tipe: ' . $this->escape((string) $item->equipment_type);
            }
        }

        $baris[] = '';
        $baris[] = 'Detail web: ' . rtrim((string) config('app.url'), '/') . '/assets/' . ($aset->first()?->id ?? 0);

        return [
            'message'  => implode("\n", $baris),
            'keyboard' => [['text' => 'Hirarki Alat', 'callback_data' => 'info:root']],
        ];
    }

    /**
     * Pencarian teks langsung pada data IT Assets: tag name, nama pemakai,
     * lokasi, plant/department, kode Functional Location, dan SELURUH nilai
     * spec (termasuk "pointing" seperti "Sekitar Jalan CES", NVR, channel,
     * connect to). Dipakai sebelum mesin pencari aset industri.
     *
     * @param  string  $kata  Kata kunci dari teknisi
     * @return Collection<int, ItAsset>  Kandidat dengan kecocokan terbanyak
     */
    protected function searchItAssets(string $kata): Collection
    {
        $token = array_values(array_filter(
            array_unique(explode(' ', $this->normalize($kata))),
            fn ($bagian) => strlen($bagian) >= 3
                && !in_array($bagian, TechIdentSearchService::LOCATION_STOPWORDS, true)
                && !in_array($bagian, TechIdentSearchService::NAME_TITLES, true)
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

            if ($plant !== null && !empty($asset->plant) && strcasecmp((string) $asset->plant, $plant) !== 0) {
                continue;
            }

            $kolom    = $this->assetHaystack($asset);
            $katakata = array_unique(array_filter(explode(' ', $kolom)));
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
            if (!is_array($nilai)) {
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
     * Kenali tipe perangkat yang disebut pada kata kunci (mis. "CCTV",
     * "Switch", "Laptop"), dipakai untuk mempersempit hasil pencarian.
     *
     * @param  string  $kata
     * @return string|null  Label tipe (mis. "CCTV"), null bila tidak disebut
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
