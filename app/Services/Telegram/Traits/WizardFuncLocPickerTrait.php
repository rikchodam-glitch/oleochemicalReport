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
        $this->saveState($chatId, $state);

        $keyboard = $this->buildFuncLocLevelKeyboard(FunctionalLocation::LEVEL_DEPARTMENT, null);

        if (empty($keyboard)) {
            Log::warning("WizardFuncLocPicker: Tidak ada FuncLoc L1 aktif untuk chat {$chatId}");

            return $this->errorResponse(
                "Tidak ada data Departemen yang tersedia.\n" .
                "Hubungi admin untuk menambahkan data Functional Location."
            );
        }

        return [
            'message'  => "*Step 2* — Pilih Departemen\n\nPilih departemen tempat pekerjaan dilakukan:",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // HANDLER CALLBACK — pick & confirm
    // =========================================================

    /**
     * Proses callback funcloc_pick:{level}:{id}.
     * Setelah L1 atau L2 dipilih, tampilkan level berikutnya.
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

        // L1 atau L2 dipilih: simpan pilihan sementara, tampilkan level berikutnya
        $state['funcloc_pending_id']   = $node->id;
        $state['funcloc_pending_code'] = $node->code;
        $state['funcloc_pending_name'] = $node->name;
        $this->saveState($chatId, $state);

        $nextLevel = $level + 1;
        $keyboard  = $this->buildFuncLocLevelKeyboard($nextLevel, $node->id);

        // Siapkan label level berikutnya
        $nextLevelLabel = $this->funcLocLevelLabel($nextLevel);
        $currentLabel   = $this->funcLocLevelLabel($level);

        // Tombol konfirmasi di level saat ini (skip turun lebih dalam)
        $confirmButton = [
            'text'          => "Konfirmasi: {$node->code}",
            'callback_data' => "funcloc_confirm:{$node->id}",
        ];

        if (empty($keyboard)) {
            // Tidak ada anak di level berikutnya: langsung konfirmasi
            Log::info("WizardFuncLocPicker: Tidak ada anak L{$nextLevel} untuk node {$node->code}, langsung lock.", [
                'chat_id'    => $chatId,
                'funcloc_id' => $node->id,
            ]);

            return $this->lockFuncLocAndAdvance($chatId, $node, $state);
        }

        // Tambahkan tombol konfirmasi di level saat ini sebelum daftar level berikutnya
        array_unshift($keyboard, $confirmButton);

        return [
            'message'  => "*{$currentLabel} dipilih:* {$node->code} — {$node->name}\n\n" .
                          "Pilih {$nextLevelLabel} (opsional) atau konfirmasi sekarang:",
            'keyboard' => $keyboard,
        ];
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

    // =========================================================
    // HELPER INTERNAL
    // =========================================================

    /**
     * Kunci FuncLoc yang dipilih ke state dan maju ke Step 4 (Waktu Pengerjaan).
     * Membersihkan state sementara picker sebelum maju.
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
            $state['funcloc_pending_id'],
            $state['funcloc_pending_code'],
            $state['funcloc_pending_name'],
            $state['funcloc_picker_active']
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
