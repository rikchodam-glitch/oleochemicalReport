<?php

namespace App\Services\Telegram\Traits;

use App\Models\FunctionalLocation;
use Illuminate\Support\Facades\Log;

/**
 * WizardFuncLocPickerTrait
 *
 * Menangani pemilihan Functional Location (FuncLoc) hierarki oleh teknisi
 * saat jenis pekerjaan "area/section" dipilih di wizard laporan.
 *
 * Alur pemilihan:
 *   1. Tampil keyboard L1 (Departemen) setelah work_type:area dipilih
 *   2. Setelah L1 dipilih: tampil keyboard L2 (Area) + tombol "Konfirmasi di sini"
 *   3. Setelah L2 dipilih: tampil keyboard L3 (Section) + tombol "Konfirmasi di sini"
 *   4. Setelah L3 dipilih ATAU tombol konfirmasi ditekan:
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
 * Setiap kali teknisi memilih node di L1 atau L2 (turun ke level berikutnya),
 * node tersebut di-push ke 'funcloc_path' lewat handleFuncLocPick(). Tombol
 * "Naik Satu Level" (callback_data 'funcloc_back') men-pop entri terakhir
 * dan membangun ulang keyboard level tersebut memakai parent hasil pop
 * (atau kembali ke root Departemen jika stack sudah kosong). Mekanisme ini
 * terpisah dari 'wizard:back' (step_history) milik WizardCallbackHandlerTrait
 * karena sifatnya naik-turun DI DALAM Step 2/3, bukan berpindah antar step
 * utama wizard — sama seperti ClarificationService yang juga punya
 * back internal sendiri (action==='back') terpisah dari 'wizard:back'.
 * Tombol Batalkan Laporan (callback_data 'wizard:cancel_wizard') tetap
 * disertakan konsisten di setiap layar level.
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - saveState(string $chatId, array $state): void
 *   - advanceToWorkDuration(string $chatId, array $state): array
 *   - errorResponse(string $message): array
 */
trait WizardFuncLocPickerTrait
{
    // =========================================================
    // ENTRY POINT — dipanggil dari handleWorkTypeCallback
    // =========================================================

    /**
     * Tampilkan keyboard L1 (Departemen) sebagai titik masuk FuncLoc picker.
     * Dipanggil setelah teknisi memilih work_type:area.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons dengan keyboard L1
     */
    protected function startFuncLocPicker(string $chatId, array $state): array
    {
        $state['is_area_work']         = true;
        $state['funcloc_picker_active'] = true;
        $state['funcloc_path']          = [];
        $this->saveState($chatId, $state);

        return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_DEPARTMENT, null, $state);
    }

    // =========================================================
    // HANDLER CALLBACK — pick, confirm, back
    // =========================================================

    /**
     * Proses callback funcloc_pick:{level}:{id}.
     * Setelah L1 atau L2 dipilih, node yang dipilih disimpan ke funcloc_path
     * (untuk kebutuhan naik level nanti) lalu level berikutnya ditampilkan.
     * Setelah L3 dipilih, langsung kunci FuncLoc dan maju ke Step 4.
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

        // L3 dipilih: kunci langsung tanpa perlu confirm
        if ($level === FunctionalLocation::LEVEL_SECTION) {
            return $this->lockFuncLocAndAdvance($chatId, $node, $state);
        }

        // L1 atau L2 dipilih: simpan node ke funcloc_path sebagai riwayat
        // naik-turun level, lalu tampilkan level berikutnya.
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
     * sedang berada di level Departemen (root) — layar root ditampilkan ulang.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleFuncLocBack(string $chatId, array $state): array
    {
        $path = $state['funcloc_path'] ?? [];

        if (empty($path)) {
            return $this->buildFuncLocScreen($chatId, FunctionalLocation::LEVEL_DEPARTMENT, null, $state);
        }

        array_pop($path);
        $state['funcloc_path'] = $path;
        $this->saveState($chatId, $state);

        $parentEntry = empty($path) ? null : end($path);
        $level       = $parentEntry ? $parentEntry['level'] + 1 : FunctionalLocation::LEVEL_DEPARTMENT;

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
     *   - Jika ini root (Departemen) dan kosong, kembalikan error konfigurasi.
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
        $options  = $this->buildFuncLocLevelKeyboard($level, $parentId);

        if (empty($options)) {
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
            $nextLabel    = $this->funcLocLevelLabel($level);
            $message      = "*{$currentLabel} dipilih:* {$parentEntry['code']} — {$parentEntry['name']}\n\n" .
                            "Pilih {$nextLabel} (opsional) atau konfirmasi sekarang:";
        } else {
            $message = "*Step 2* — Pilih Departemen\n\nPilih departemen tempat pekerjaan dilakukan:";
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
            $state['funcloc_path']
        );

        // Kunci FuncLoc yang dipilih
        $state['funcloc_id']   = $node->id;
        $state['funcloc_code'] = $node->code;
        $state['funcloc_name'] = $node->name;

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
     * @param  int|null $parentId ID parent — null untuk L1 (root level Departemen)
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
            FunctionalLocation::LEVEL_SITE       => 'Site',
            FunctionalLocation::LEVEL_DEPARTMENT => 'Departemen',
            FunctionalLocation::LEVEL_AREA       => 'Area',
            FunctionalLocation::LEVEL_SECTION    => 'Section',
            default                              => 'Lokasi',
        };
    }
}
