<?php

namespace App\Services\Telegram\Traits;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * WizardFuncLocPickerTrait
 *
 * Menangani pemilihan Functional Location (FuncLoc) hierarki oleh teknisi
 * saat jenis pekerjaan "area/section" dipilih di wizard laporan.
 *
 * Alur pemilihan (diperluas Sesi 4 agar mulai dari PT, bukan langsung
 * Departemen — mengikuti hierarki penuh FunctionalLocation di database):
 *   1. Tampil keyboard PT (LEVEL_SITE) setelah work_type:area dipilih
 *   2. Setelah PT dipilih: tampil keyboard Departemen (LEVEL_DEPARTMENT) + tombol "Konfirmasi di sini"
 *   3. Setelah Departemen dipilih: tampil keyboard Area (LEVEL_AREA) + tombol "Konfirmasi di sini"
 *   4. Setelah Area dipilih: tampil keyboard Sub Area/Section (LEVEL_SECTION) + tombol "Konfirmasi di sini"
 *   5. Setelah Sub Area dipilih ATAU tombol konfirmasi ditekan di level manapun:
 *      state['funcloc_id'] dan state['funcloc_code'] dikunci, lanjut ke Step 4
 *
 * Callback data yang digunakan:
 *   - funcloc_pick:{level}:{id}   : Teknisi memilih node pada level tertentu
 *   - funcloc_confirm:{id}        : Teknisi mengonfirmasi node saat ini tanpa turun lebih dalam
 *   - funcloc_back                : Teknisi naik satu level hierarki (Sesi 5)
 *
 * NAVIGASI NAIK SATU LEVEL (funcloc_back, ditambahkan Sesi 5):
 * State wizard menyimpan 'funcloc_path' — stack berisi node yang pernah
 * dipilih (level, id, code, name) sepanjang perjalanan turun hierarki.
 * Setiap kali teknisi memilih node di PT, Departemen, atau Area (turun ke
 * level berikutnya), node tersebut di-push ke 'funcloc_path' lewat
 * handleFuncLocPick(). Tombol "Naik Satu Level" (callback_data
 * 'funcloc_back') men-pop entri terakhir dan membangun ulang keyboard
 * level tersebut memakai parent hasil pop (atau kembali ke root PT jika
 * stack sudah kosong). Mekanisme ini
 * terpisah dari 'wizard:back' (step_history) milik WizardCallbackHandlerTrait
 * karena sifatnya naik-turun DI DALAM Step 2/3, bukan berpindah antar step
 * utama wizard — sama seperti ClarificationService yang juga punya
 * back internal sendiri (action==='back') terpisah dari 'wizard:back'.
 * Tombol Batalkan Laporan (callback_data 'wizard:cancel_wizard') tetap
 * disertakan konsisten di setiap layar level.
 *
 * PENCARIAN TEKS (ditambahkan Sesi 5):
 * Selama funcloc_picker_active bernilai true, teks apa pun yang diketik
 * teknisi dirutekan oleh ReportWizardService::handleTextInput() ke
 * handleFuncLocTextSearch() alih-alih step switch biasa. Pencarian bersifat
 * global ke seluruh level (PT..Sub Area), sebagai pelengkap navigasi
 * tombol — bukan pengganti. Hasil pencarian memakai callback_data
 * funcloc_pick yang sama dengan tombol level normal, jadi tetap diproses
 * lewat handleFuncLocPick() tanpa kontrak callback baru.
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - saveState(string $chatId, array $state): void
 *   - advanceToWorkDuration(string $chatId, array $state): array
 *   - errorResponse(string $message): array
 */
trait WizardFuncLocPickerTrait
{
    /** Jumlah equipment dalam satu lokasi sebelum dikelompokkan per tipe alat. */
    protected const EQUIPMENT_GROUP_THRESHOLD = 10;

    // =========================================================
    // ENTRY POINT — dipanggil dari handleWorkTypeCallback
    // =========================================================

    /**
     * Tampilkan keyboard PT (LEVEL_SITE) sebagai titik masuk FuncLoc picker.
     * Dipanggil setelah teknisi memilih work_type:area.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons dengan keyboard PT
     */
    protected function startFuncLocPicker(string $chatId, array $state, string $mode = 'area'): array
    {
        $state['is_area_work']          = ($mode === 'area');
        $state['funcloc_picker_active'] = true;
        $state['funcloc_picker_mode']   = $mode;
        $state['funcloc_path']          = [];
        $this->saveState($chatId, $state);

        return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_SITE, null, $state);
    }

    // =========================================================
    // PENCARIAN TEKS (Sesi 5) — pelengkap navigasi tombol
    // =========================================================

    /**
     * Cari FuncLoc berdasarkan teks yang diketik teknisi selagi picker
     * lokasi aktif. Pencarian bersifat global ke seluruh level (PT sampai
     * Sub Area) memakai FunctionalLocation::scopeSearch() yang sama dengan
     * pencarian di form web, supaya teknisi tidak harus tap berjenjang dari
     * PT jika sudah hafal nama/kode Area atau Sub Area yang dituju.
     *
     * Tiap hasil dipakai sebagai tombol dengan callback_data funcloc_pick,
     * sama seperti tombol level normal — jadi tetap diproses lewat
     * handleFuncLocPick() (auto-lock jika Sub Area, atau turun ke level
     * berikutnya jika bukan). Kontrak callback lama tidak berubah.
     *
     * Catatan: karena node bisa langsung "dilompati" dari root lewat
     * pencarian, funcloc_path hasil pilihan ini hanya berisi node tersebut
     * (bukan seluruh leluhurnya) — tombol "Naik Satu Level" akan kembali
     * langsung ke root PT setelahnya, bukan berhenti di leluhur antara.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Kata kunci yang diketik teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons dengan daftar hasil sebagai keyboard
     */
    protected function handleFuncLocTextSearch(string $chatId, string $text, array $state): array
    {
        $term = trim($text);

        if ($term === '') {
            return $this->errorResponse('Ketik nama atau kode lokasi yang ingin dicari.');
        }

        // Dibatasi 8 hasil (lebih kecil dari batas 20 di endpoint web) agar
        // keyboard inline Telegram tetap muat tanpa perlu di-scroll panjang
        // di layar HP.
        $nodes = FunctionalLocation::active()
            ->search($term)
            ->orderBy('code')
            ->limit(8)
            ->get(['id', 'code', 'name', 'level']);

        if ($nodes->isEmpty()) {
            return [
                'message'  => "Lokasi \"{$term}\" tidak ditemukan.\n\nCoba kata kunci lain, atau lanjutkan lewat tombol yang sudah tampil sebelumnya.",
                'keyboard' => [
                    ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        $keyboard = $nodes->map(fn (FunctionalLocation $node) => [
            'text'          => $this->funcLocSearchResultLabel($node),
            'callback_data' => "funcloc_pick:{$node->level}:{$node->id}",
        ])->all();

        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "Hasil pencarian \"{$term}\":\n\nPilih lokasi yang sesuai, atau ketik kata kunci lain untuk mencari ulang.",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun label tombol untuk satu hasil pencarian FuncLoc, dipotong jika
     * terlalu panjang agar tetap sesuai batas tombol inline keyboard Telegram.
     *
     * @param  FunctionalLocation $node
     * @return string
     */
    protected function funcLocSearchResultLabel(FunctionalLocation $node): string
    {
        $label = $node->code;

        if ($node->name) {
            $label .= ' — ' . $node->name;
        }

        $label .= ' (' . $node->level_label . ')';

        if (strlen($label) > 64) {
            $label = substr($label, 0, 61) . '...';
        }

        return $label;
    }

    // =========================================================
    // HANDLER CALLBACK — pick, confirm, back
    // =========================================================

    /**
     * Proses callback funcloc_pick:{level}:{id}.
     * Setelah PT, Departemen, atau Area dipilih, node yang dipilih disimpan
     * ke funcloc_path (untuk kebutuhan naik level nanti) lalu level
     * berikutnya ditampilkan. Setelah Sub Area (LEVEL_SECTION) dipilih,
     * langsung kunci FuncLoc dan maju ke Step 4.
     *
     * @param  string $chatId     Chat ID Telegram
     * @param  string $callbackData Data callback penuh, mis. "funcloc_pick:1:5"
     * @param  array  $state      State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocPick(string $chatId, string $callbackData, array $state): array
    {
        // Format: funcloc_pick:{level}:{id}
        $parts     = explode(':', $callbackData);
        $level     = isset($parts[1]) ? (int) $parts[1] : null;
        $funclocId = isset($parts[2]) ? (int) $parts[2] : null;

        if ($level === null || !$funclocId) {
            return $this->errorResponse('Format callback FuncLoc tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);

        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        // Section (LEVEL_SECTION) dipilih:
        // - Mode area: kunci langsung (Section = level terdalam).
        // - Mode equipment: lanjut ke layar Section yang menampilkan daftar
        //   TechIdentNo/equipment di bawahnya (FuncLoc -> TechIdentNo berkolaborasi).
        if ($level === FunctionalLocation::LEVEL_SECTION
            && ($state['funcloc_picker_mode'] ?? 'area') !== 'equipment') {
            return $this->lockFuncLocAndAdvance($chatId, $node, $state);
        }

        // PT, Departemen, atau Area dipilih: simpan node ke funcloc_path
        // sebagai riwayat naik-turun level, lalu tampilkan level berikutnya.
        $state['funcloc_path'][] = [
            'level' => $node->level,
            'id'    => $node->id,
            'code'  => $node->code,
            'name'  => $node->name,
        ];
        $this->saveState($chatId, $state);

        $parentEntry = [
            'level' => $node->level,
            'id'    => $node->id,
            'code'  => $node->code,
            'name'  => $node->name,
        ];

        return $this->buildFuncLocScreen($chatId, $level + 1, $parentEntry, $state);
    }

    /**
     * Proses callback funcloc_confirm:{id}.
     * Teknisi menekan tombol "Konfirmasi" tanpa turun ke level berikutnya.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh, mis. "funcloc_confirm:5"
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocConfirm(string $chatId, string $callbackData, array $state): array
    {
        // Format: funcloc_confirm:{id}
        $parts     = explode(':', $callbackData);
        $funclocId = isset($parts[1]) ? (int) $parts[1] : null;

        if (!$funclocId) {
            return $this->errorResponse('ID Functional Location tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);

        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        return $this->lockFuncLocAndAdvance($chatId, $node, $state);
    }

    /**
     * Proses callback funcloc_back.
     * Men-pop entri terakhir dari funcloc_path lalu membangun ulang keyboard
     * level tersebut memakai parent hasil pop (satu level di atas node yang
     * baru saja "dilupakan"). Jika funcloc_path sudah kosong, teknisi memang
     * sedang berada di level PT (root) — layar root ditampilkan ulang.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocBack(string $chatId, array $state): array
    {
        $path = $state['funcloc_path'] ?? [];

        if (empty($path)) {
            return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_SITE, null, $state);
        }

        array_pop($path);
        $state['funcloc_path'] = $path;
        $this->saveState($chatId, $state);

        $parentEntry = empty($path) ? null : end($path);
        $level       = $parentEntry ? $parentEntry['level'] + 1 : FunctionalLocation::LEVEL_SITE;

        return $this->buildFuncLocScreen($chatId, $level, $parentEntry, $state);
    }

    // =========================================================
    // HELPER INTERNAL
    // =========================================================

    /**
     * Bangun satu layar FuncLoc picker untuk level tertentu, dipakai bersama
     * oleh startFuncLocPicker(), handleFuncLocPick() (turun level), dan
     * handleFuncLocBack() (naik level) agar tidak ada logic keyboard yang
     * terduplikasi antara arah turun dan arah naik.
     *
     * Jika level ini tidak punya anak sama sekali:
     *   - Jika ada $parentEntry (bukan root), kunci parent tersebut langsung
     *     sebagai FuncLoc final (perilaku sama seperti sebelum Sesi 5).
     *   - Jika ini root (PT) dan kosong, kembalikan error konfigurasi.
     *
     * @param  string     $chatId     Chat ID Telegram
     * @param  int        $level      Level FuncLoc yang akan ditampilkan (konstanta LEVEL_*)
     * @param  array|null $parentEntry Node parent terpilih (['level','id','code','name']), null jika root
     * @param  array      $state      State wizard saat ini
     * @return array      Respons dengan message dan keyboard
     */
    protected function buildFuncLocScreen(string $chatId, int $level, ?array $parentEntry, array $state): array
    {
        $parentId = $parentEntry['id'] ?? null;
        $mode     = $state['funcloc_picker_mode'] ?? 'area';
        $options  = $this->buildFuncLocLevelKeyboard($level, $parentId);

        // Equipment langsung di bawah node yang sedang dilihat (hanya mode equipment).
        $equipmentCount = 0;
        if ($mode === 'equipment' && $parentId) {
            $equipmentCount = $this->countAssetsUnderNode($parentId);
        }

        if (empty($options) && $equipmentCount === 0) {
            if ($parentEntry) {
                $node = FunctionalLocation::active()->find($parentEntry['id']);
                if ($node) {
                    Log::info("WizardFuncLocPicker: Tidak ada anak L{$level} untuk node {$node->code}, langsung lock.", [
                        'chat_id'    => $chatId,
                        'funcloc_id' => $node->id,
                    ]);

                    return $this->lockFuncLocAndAdvance($chatId, $node, $state);
                }
            }

            Log::warning("WizardFuncLocPicker: Tidak ada FuncLoc aktif di level {$level} untuk chat {$chatId}");

            return $this->errorResponse(
                "Tidak ada data lokasi yang tersedia di level ini.\n" .
                "Hubungi admin untuk menambahkan data Functional Location."
            );
        }

        $keyboard = [];

        // Mode equipment: tawarkan pemilihan TechIdentNo di lokasi ini bila ada.
        if ($equipmentCount > 0) {
            $keyboard[] = [
                'text'          => "Pilih Equipment di sini ({$equipmentCount})",
                'callback_data' => "funcloc_assets:{$parentId}",
            ];
        }

        // Tombol konfirmasi node yang sedang dilihat (skip turun lebih dalam)
        if ($parentEntry) {
            $keyboard[] = [
                'text'          => "Konfirmasi: {$parentEntry['code']}",
                'callback_data' => "funcloc_confirm:{$parentEntry['id']}",
            ];
        }

        foreach ($options as $option) {
            $keyboard[] = $option;
        }

        // Naik satu level — hanya relevan jika sudah pernah turun (path tidak kosong)
        if (!empty($state['funcloc_path'])) {
            $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        }

        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        if ($parentEntry) {
            $currentLabel = $this->funcLocLevelLabel($parentEntry['level']);
            if ($mode === 'equipment' && $equipmentCount > 0) {
                $message = "*{$currentLabel} dipilih:* {$parentEntry['code']} — {$parentEntry['name']}\n\n" .
                           "Pilih equipment di lokasi ini, atau konfirmasi lokasi saja:";
            } else {
                $nextLabel = $this->funcLocLevelLabel($level);
                $message   = "*{$currentLabel} dipilih:* {$parentEntry['code']} — {$parentEntry['name']}\n\n" .
                             "Pilih {$nextLabel} (opsional) atau konfirmasi sekarang:";
            }
        } else {
            $rootLabel = $this->funcLocLevelLabel($level);
            $message   = "*Step 2* — Pilih {$rootLabel}\n\nPilih {$rootLabel} tempat pekerjaan dilakukan:";
        }

        return [
            'message'  => $message,
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Kunci FuncLoc yang dipilih ke state dan maju ke Step 4 (Waktu Pengerjaan).
     * Membersihkan state sementara picker (termasuk riwayat funcloc_path)
     * sebelum maju.
     *
     * @param  string              $chatId Chat ID Telegram
     * @param  FunctionalLocation  $node   Node FuncLoc yang dikunci
     * @param  array               $state  State wizard saat ini
     * @return array               Respons dari advanceToWorkDuration()
     */
    protected function lockFuncLocAndAdvance(string $chatId, FunctionalLocation $node, array $state): array
    {
        // Bersihkan state sementara picker
        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path']
        );

        // Kunci FuncLoc sebagai pekerjaan area (tanpa equipment spesifik).
        // is_area_work dipaksa true karena konfirmasi lokasi = laporan area,
        // apapun mode picker-nya (area maupun equipment).
        $state['is_area_work']    = true;
        $state['equipment_id']    = null;
        $state['equipment_ident'] = null;
        $state['funcloc_id']      = $node->id;
        $state['funcloc_code']    = $node->code;
        $state['funcloc_name']    = $node->name;

        // Sinkronisasi area_id dari relasi FuncLoc jika ada
        if ($node->area_id) {
            $state['area_id'] = $node->area_id;
        }

        Log::info("WizardFuncLocPicker: FuncLoc dikunci untuk chat {$chatId}", [
            'funcloc_id'   => $node->id,
            'funcloc_code' => $node->code,
            'level'        => $node->level,
        ]);

        return $this->advanceToWorkDuration($chatId, $state);
    }

    /**
     * Bangun inline keyboard untuk satu level FuncLoc tertentu.
     * Hanya node aktif yang dimuat. Diurutkan berdasarkan code secara ascending.
     *
     * @param  int      $level    Level FuncLoc (konstanta LEVEL_* dari FunctionalLocation)
     * @param  int|null $parentId ID parent — null untuk root level PT (LEVEL_SITE)
     * @return array    Array tombol inline keyboard Telegram
     */
    protected function buildFuncLocLevelKeyboard(int $level, ?int $parentId): array
    {
        $query = FunctionalLocation::active()
            ->ofLevel($level)
            ->orderBy('code');

        if ($parentId !== null) {
            $query->underParent($parentId);
        }

        $nodes = $query->get(['id', 'code', 'name', 'level']);

        $keyboard = [];
        foreach ($nodes as $node) {
            // Label tombol: code — name, dipotong jika terlalu panjang
            $label = $node->code;
            if ($node->name) {
                $label .= ' — ' . $node->name;
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }

            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_pick:{$node->level}:{$node->id}",
            ];
        }

        return $keyboard;
    }

    /**
     * Kembalikan label level FuncLoc dalam Bahasa Indonesia.
     *
     * @param  int    $level Nilai level (0–3)
     * @return string Label level
     */
    protected function funcLocLevelLabel(int $level): string
    {
        return match ($level) {
            FunctionalLocation::LEVEL_SITE       => 'PT',
            FunctionalLocation::LEVEL_DEPARTMENT => 'Departemen',
            FunctionalLocation::LEVEL_AREA       => 'Area',
            FunctionalLocation::LEVEL_SECTION    => 'Section',
            default                              => 'Lokasi',
        };
    }

    // =========================================================
    // MODE EQUIPMENT — PEMILIHAN TECHIDENTNO DI BAWAH FUNCLOC
    // =========================================================

    /**
     * Hitung jumlah asset yang terdaftar langsung di bawah node FuncLoc.
     *
     * @param  int $funclocId ID FunctionalLocation
     * @return int
     */
    protected function countAssetsUnderNode(int $funclocId): int
    {
        return Asset::where('funcloc_id', $funclocId)
            ->whereNotNull('tech_ident_no')
            ->count();
    }

    /**
     * Ambil koleksi asset di bawah node FuncLoc, diurutkan berdasarkan TechIdentNo.
     *
     * @param  int        $funclocId ID FunctionalLocation
     * @return Collection
     */
    protected function assetsUnderNode(int $funclocId): Collection
    {
        return Asset::where('funcloc_id', $funclocId)
            ->whereNotNull('tech_ident_no')
            ->orderBy('tech_ident_no')
            ->get(['id', 'tech_ident_no', 'description']);
    }

    /**
     * Proses callback funcloc_assets:{funcloc_id} — tampilkan daftar equipment
     * di bawah node. Jika jumlahnya melebihi EQUIPMENT_GROUP_THRESHOLD,
     * kelompokkan dulu per tipe alat.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssets(string $chatId, string $callbackData, array $state): array
    {
        $funclocId = (int) (explode(':', $callbackData)[1] ?? 0);

        if (!$funclocId) {
            return $this->errorResponse('ID lokasi tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);
        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        $assets = $this->assetsUnderNode($funclocId);

        if ($assets->isEmpty()) {
            return [
                'message'  => "Tidak ada equipment terdaftar di {$node->code} — {$node->name}.",
                'keyboard' => [
                    ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'],
                    ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        if ($assets->count() > self::EQUIPMENT_GROUP_THRESHOLD) {
            return $this->buildAssetTypeKeyboard($node, $assets, $state);
        }

        return $this->buildAssetListKeyboard($node, $assets, null, $state);
    }

    /**
     * Proses callback funcloc_assets_type:{funcloc_id}:{type} — tampilkan
     * equipment dengan tipe tertentu di bawah node.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssetsType(string $chatId, string $callbackData, array $state): array
    {
        $parts     = explode(':', $callbackData);
        $funclocId = (int) ($parts[1] ?? 0);
        $type      = $parts[2] ?? '';

        if (!$funclocId || $type === '') {
            return $this->errorResponse('Data tipe equipment tidak valid.');
        }

        $node = FunctionalLocation::active()->find($funclocId);
        if (!$node) {
            return $this->errorResponse('Lokasi tidak ditemukan atau sudah tidak aktif.');
        }

        $assets = $this->assetsUnderNode($funclocId)
            ->filter(fn (Asset $asset) => $this->equipmentTypeOf($asset) === $type)
            ->values();

        if ($assets->isEmpty()) {
            return $this->errorResponse('Tidak ada equipment dengan tipe tersebut di lokasi ini.');
        }

        return $this->buildAssetListKeyboard($node, $assets, $type, $state);
    }

    /**
     * Proses callback funcloc_asset_pick:{asset_id} — kunci equipment yang
     * dipilih dan lanjut ke Step 4.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback penuh
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocAssetPick(string $chatId, string $callbackData, array $state): array
    {
        $assetId = (int) (explode(':', $callbackData)[1] ?? 0);

        $asset = $assetId ? Asset::find($assetId) : null;
        if (!$asset) {
            return $this->errorResponse('Equipment tidak ditemukan. Silakan pilih ulang.');
        }

        // Sinkronkan funcloc dari asset agar laporan menyimpan lokasi numeriknya.
        $state['funcloc_id']   = $asset->funcloc_id ?? null;
        $state['funcloc_code'] = $asset->functional_loc ?? null;

        // Bersihkan state sementara picker sebelum lanjut.
        unset(
            $state['funcloc_picker_active'],
            $state['funcloc_picker_mode'],
            $state['funcloc_path']
        );

        return $this->lockEquipmentAndAdvance($chatId, $asset, $state);
    }

    /**
     * Bangun keyboard pilihan tipe alat saat equipment di satu lokasi terlalu
     * banyak untuk ditampilkan langsung.
     *
     * @param  FunctionalLocation $node   Node lokasi terpilih
     * @param  Collection         $assets Koleksi asset di bawah node
     * @param  array              $state  State wizard saat ini
     * @return array Respons dengan keyboard tipe alat
     */
    protected function buildAssetTypeKeyboard(FunctionalLocation $node, Collection $assets, array $state): array
    {
        $grouped = [];

        foreach ($assets as $asset) {
            $type             = $this->equipmentTypeOf($asset);
            $grouped[$type][] = $asset;
        }

        ksort($grouped);

        $keyboard = [];
        foreach ($grouped as $type => $list) {
            $label = $this->equipmentTypeLabel($type) . " ({$type}) — " . count($list) . ' alat';
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_assets_type:{$node->id}:{$type}",
            ];
        }

        $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "Equipment di {$node->code} — {$node->name} cukup banyak. Pilih tipe alat dulu:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun keyboard daftar equipment (TechIdentNo) untuk dipilih teknisi.
     *
     * @param  FunctionalLocation $node   Node lokasi terpilih
     * @param  Collection         $assets Koleksi asset yang akan ditampilkan
     * @param  string|null        $type   Tipe alat (jika ini hasil filter tipe)
     * @param  array              $state  State wizard saat ini
     * @return array Respons dengan keyboard daftar equipment
     */
    protected function buildAssetListKeyboard(FunctionalLocation $node, Collection $assets, ?string $type, array $state): array
    {
        $keyboard = [];
        foreach ($assets as $asset) {
            $label = $asset->tech_ident_no;
            if ($asset->description) {
                $label .= ' — ' . $asset->description;
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => "funcloc_asset_pick:{$asset->id}",
            ];
        }

        if ($type !== null) {
            $keyboard[] = ['text' => 'Kembali', 'callback_data' => "funcloc_assets:{$node->id}"];
        } else {
            $keyboard[] = ['text' => 'Naik Satu Level', 'callback_data' => 'funcloc_back'];
        }
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "Pilih equipment di {$node->code} — {$node->name}:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Ekstrak kode tipe alat dari TechIdentNo, fallback 'Lainnya' jika tidak
     * terdeteksi. Mendukung dua format:
     *   - Prefix huruf leading (mis. "P-6163A1" -> P, "BV-T201" -> BV).
     *   - Kode section 4-digit diikuti huruf tipe (mis. "6163C1" -> C).
     *
     * @param  Asset  $asset Model asset
     * @return string Kode tipe alat
     */
    protected function equipmentTypeOf(Asset $asset): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim((string) ($asset->tech_ident_no ?? ''))));

        if ($clean === '') {
            return 'Lainnya';
        }

        // Prefix huruf leading (1-4 huruf)
        if (preg_match('/^([A-Z]{1,4})/', $clean, $m)) {
            return $m[1];
        }

        // Kode section 4-digit diikuti huruf tipe (1-3 huruf)
        if (preg_match('/^\d{4}([A-Z]{1,3})/', $clean, $m)) {
            return $m[1];
        }

        return 'Lainnya';
    }

    /**
     * Label ramah-pengguna untuk kode tipe alat.
     *
     * @param  string $type Kode tipe alat
     * @return string
     */
    protected function equipmentTypeLabel(string $type): string
    {
        return $this->funcLocParser->typeLabel($type);
    }
}
