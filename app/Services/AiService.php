<?php

namespace App\Services;

use App\Services\Traits\AiKeywordParserTrait;
use App\Services\Traits\AiProviderCallerTrait;

/**
 * AiService
 *
 * Orkestrator analisis teks laporan maintenance.
 * Mencoba analisis via AI provider (Groq/LLM) terlebih dahulu;
 * jika tidak tersedia, jatuh ke fallback keyword matching.
 *
 * Parsing durasi pekerjaan dan root cause selalu dijalankan terpisah
 * dari alur deteksi area/equipment, sehingga tetap berfungsi meskipun
 * semua provider sedang exhausted atau down.
 *
 * Logika detail tersimpan di trait:
 *   - AiKeywordParserTrait  : analisis keyword, deteksi area/asset, parsing teks
 *   - AiProviderCallerTrait : pemilihan provider, pemanggilan Groq API, logging
 */
class AiService
{
    use AiKeywordParserTrait;
    use AiProviderCallerTrait;

    /**
     * Analisis teks laporan maintenance untuk mendeteksi area, equipment, dan tipe laporan.
     *
     * Alur:
     *   1. Parsing durasi dan root cause (selalu dijalankan, tidak bergantung provider)
     *   2. Coba analisis via AI provider (Groq)
     *   3. Fallback ke keyword matching jika provider tidak tersedia atau gagal
     *
     * @param string $text Teks laporan dari teknisi
     * @return array {
     *   report_type: string,
     *   suggested_area: string|null,
     *   suggested_equipment: string|null,
     *   detected_area: string|null,
     *   detected_equipment: string|null,
     *   detected_equipment_id: int|null,
     *   confidence: int,
     *   message: string,
     *   needs_clarification: bool,
     *   clarification_questions: array,
     *   parsed_duration_minutes: int|null,
     *   parsed_root_cause: string|null,
     * }
     */
    public function analyzeReportText(string $text): array
    {
        $result = [
            'report_type'             => 'general',
            'suggested_area'          => null,
            'suggested_equipment'     => null,
            'detected_area'           => null,
            'detected_equipment'      => null,
            'detected_equipment_id'   => null,
            'confidence'              => 0,
            'message'                 => '',
            'needs_clarification'     => false,
            'clarification_questions' => [],
            'parsed_duration_minutes' => null,
            'parsed_root_cause'       => null,
        ];

        // Parsing durasi pekerjaan & root cause dari teks awal (Step 4 & Step 5 wizard).
        // Dijalankan terpisah dari deteksi area/equipment di bawah, dan tidak bergantung
        // pada AI provider, supaya tetap berfungsi walau provider sedang exhausted/down.
        $result['parsed_duration_minutes'] = $this->parseWorkDurationMinutes($text);
        $result['parsed_root_cause']       = $this->parseRootCauseHint($text);

        // 1. Coba AI provider (Groq/LLM)
        $aiResult = $this->analyzeWithAi($text);
        if ($aiResult !== null) {
            return array_merge($result, $aiResult);
        }

        // 2. Fallback: keyword matching
        $kwResult = $this->analyzeWithKeywords($text);
        return array_merge($result, $kwResult);
    }
}
