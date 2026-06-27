<?php

namespace App\Services\Telegram\Traits;

/**
 * WizardUtilityTrait
 *
 * Kumpulan utilitas umum yang dipakai di seluruh wizard:
 *   - parseDurationToMinutes() : Parse teks durasi ke integer menit
 *   - formatDuration()         : Format menit ke string ramah-pengguna
 *   - equipmentLabel()         : Label ringkas equipment/area dari state
 *   - createInitialState()     : Buat state awal wizard baru
 *   - errorResponse()          : Bangun struktur respons error standar
 *
 * Trait ini tidak bergantung pada method atau properti lain dari kelas pemakai
 * (self-contained), sehingga aman di-use di kelas manapun dalam wizard.
 */
trait WizardUtilityTrait
{
    // =========================================================
    // FORMAT & PARSING DURASI
    // =========================================================

    /**
     * Parse teks durasi menjadi menit.
     * Format yang didukung:
     *   - "2 jam 30 menit"
     *   - "2 jam" atau "1.5 jam" atau "1,5 jam"
     *   - "30 menit"
     *   - "90" (angka saja, dianggap menit)
     *
     * @param  string   $text Input teks durasi dari teknisi
     * @return int|null       Durasi dalam menit, null jika format tidak dikenali
     */
    public function parseDurationToMinutes(string $text): ?int
    {
        $text = strtolower(trim($text));

        // Format: "X jam Y menit"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam\s*(\d+)\s*menit/', $text, $m)) {
            $hours   = (float) str_replace(',', '.', $m[1]);
            $minutes = (int) $m[2];
            return (int) round($hours * 60) + $minutes;
        }

        // Format: "X jam"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam/', $text, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]) * 60);
        }

        // Format: "X menit"
        if (preg_match('/(\d+)\s*menit/', $text, $m)) {
            return (int) $m[1];
        }

        // Format: angka saja (anggap menit)
        if (preg_match('/^\d+$/', $text)) {
            return (int) $text;
        }

        return null;
    }

    /**
     * Format menit ke string ramah-pengguna.
     * Contoh: 90 -> "1 jam 30 menit", 60 -> "1 jam", 45 -> "45 menit".
     *
     * @param  int    $minutes Durasi dalam menit
     * @return string String durasi yang mudah dibaca
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '-';
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours} jam {$mins} menit";
        }

        if ($hours > 0) {
            return "{$hours} jam";
        }

        return "{$minutes} menit";
    }

    // =========================================================
    // LABEL EQUIPMENT
    // =========================================================

    /**
     * Bangun label ringkas untuk equipment atau area yang terkunci di state.
     * Dipakai di pesan konfirmasi, prompt step, dan ringkasan laporan.
     *
     * @param  array  $state State wizard
     * @return string Label equipment atau area
     */
    protected function equipmentLabel(array $state): string
    {
        if (!empty($state['is_area_work'])) {
            $areaCode = $state['area_code'] ?? 'Area';
            return "Pekerjaan Area ({$areaCode})";
        }

        $label = $state['equipment_ident'] ?? 'Equipment';
        if (!empty($state['equipment_funcloc'])) {
            $label .= ' (' . $state['equipment_funcloc'] . ')';
        }

        return $label;
    }

    // =========================================================
    // STATE AWAL & RESPONS ERROR
    // =========================================================

    /**
     * Buat state awal wizard untuk sesi laporan baru.
     * Semua field diinisialisasi ke nilai default yang aman.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Teks laporan awal dari teknisi
     * @return array  State awal wizard
     */
    protected function createInitialState(string $chatId, string $text): array
    {
        return [
            'chat_id'                     => $chatId,
            'text'                        => $text,
            'step'                        => self::STEP_EQUIPMENT_SEARCH,
            'ai_analysis'                 => [],
            'equipment_id'                => null,
            'equipment_ident'             => null,
            'equipment_funcloc'           => null,
            'is_area_work'                => false,
            'area_id'                     => null,
            'area_code'                   => null,
            'search_confidence'           => null,
            'retype_attempts'             => 0,
            'using_clarification_service' => false,
            'work_duration_minutes'       => null,
            'root_cause'                  => null,
            'initial_photo_file_id'       => null,
            'photo_documentation'         => [],
            'photo_hygiene_clearance'     => [],
            'created_at'                  => now()->toIso8601String(),
        ];
    }

    /**
     * Bangun struktur respons error standar.
     * Flag 'error' => true digunakan oleh PollTelegramUpdates untuk
     * memutuskan apakah perlu logging tambahan.
     *
     * @param  string $message Pesan error yang ditampilkan ke teknisi
     * @return array  Struktur respons error
     */
    protected function errorResponse(string $message): array
    {
        return [
            'message'  => $message,
            'keyboard' => [],
            'error'    => true,
        ];
    }
}
