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
 *   - AiProviderCallerTrait : pemilihan provider, pemanggilan Groq API, logging,
 *                              pre-filter asset/lokasi, searchEquipmentWithAi(),
 *                              dan searchLocationWithAi()
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

    /**
     * Pencarian equipment via AI — lapis kedua setelah regex (TechIdentSearchService)
     * mengembalikan no_match. Method ini adalah public API yang akan dipanggil
     * oleh ReportWizardService dari processEquipmentSearch() dan handleEquipmentRetype().
     *
     * Mendelegasikan ke searchEquipmentWithAi() di AiProviderCallerTrait.
     * Mengirim data equipment yang sudah di-pre-filter berdasarkan keyword dari teks,
     * sehingga token yang terpakai jauh lebih hemat dibanding dump seluruh tabel.
     *
     * Format hasil konsisten dengan TechIdentSearchService agar bisa langsung
     * digunakan oleh buildCandidateKeyboard() dan flow konfirmasi.
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu jika sudah diketahui
     * @return array|null Hasil pencarian (status, pass, confidence, candidates) atau null
     *                    jika AI tidak tersedia, pre-filter tidak menghasilkan kandidat,
     *                    atau confidence AI terlalu rendah (< 60).
     */
    public function searchEquipment(string $text, ?int $areaId = null): ?array
    {
        return $this->searchEquipmentWithAi($text, $areaId);
    }

    /**
     * Pencarian lokasi/area kerja via AI — lapis ketiga setelah regex
     * (TechIdentSearchService) DAN pencarian equipment via AI (searchEquipment())
     * sama-sama tidak menemukan hasil. Method ini adalah public API yang akan
     * dipanggil oleh ReportWizardService dari processEquipmentSearch() dan
     * handleEquipmentRetype(), setelah tryAiEquipmentSearch() gagal.
     *
     * Mendelegasikan ke searchLocationWithAi() di AiProviderCallerTrait.
     * Mengirim data functional_locations yang sudah di-pre-filter berdasarkan
     * keyword dari teks (hanya level Area dan Section), sehingga token yang
     * terpakai tetap hemat.
     *
     * Dipakai untuk mendeteksi laporan pekerjaan area/lokasi (mis. "penggantian
     * lantai lab") yang tidak merujuk ke alat tertentu.
     *
     * @param  string   $text   Teks laporan dari teknisi
     * @param  int|null $areaId Batasi pencarian ke area tertentu jika sudah diketahui
     * @return array|null Hasil pencarian (status, pass, confidence, candidates) atau null
     *                    jika AI tidak tersedia, pre-filter tidak menghasilkan kandidat,
     *                    atau confidence AI terlalu rendah (< 60).
     */
    public function searchLocation(string $text, ?int $areaId = null): ?array
    {
        return $this->searchLocationWithAi($text, $areaId);
    }
}
