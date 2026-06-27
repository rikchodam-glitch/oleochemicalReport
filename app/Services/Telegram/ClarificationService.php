<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Department;
use App\Models\SubArea;
use App\Services\FuncLocParser;
use App\Services\Telegram\Traits\ClarificationKeyboardBuilderTrait;
use App\Services\Telegram\Traits\ClarificationMessageBuilderTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ClarificationService
 *
 * Menangani klarifikasi equipment multi-level via Telegram inline keyboard.
 *
 * Alur navigasi hierarki:
 *   1. Jika AI mendeteksi AREA   -> langsung ke level subarea/section/equipment
 *   2. Jika tidak, coba akselerasi FuncLoc (kode Dept/Area yang disebut di teks)
 *      -> lompat ke level yang paling dalam yang bisa dideteksi
 *   3. Jika sama sekali tidak dikenal -> mulai dari company_selection (paling atas)
 *
 * Hierarki level penuh:
 *   company_selection -> department_selection -> area_selection
 *   -> subarea_selection (opsional) -> [section_selection -> type_selection] (Sub-alur C)
 *   -> equipment_selection -> done
 *
 * Tanggung jawab class ini:
 *   - Membuat dan mengelola sesi klarifikasi di Cache (per chat_id)
 *   - Memproses pilihan teknisi dari callback keyboard (processSelection)
 *   - Akselerasi FuncLoc: lompat level berdasarkan teks laporan awal
 *   - Mendelegasikan builder keyboard ke ClarificationKeyboardBuilderTrait
 *   - Mendelegasikan router level ke ClarificationMessageBuilderTrait
 *
 * Yang BUKAN tanggung jawab class ini:
 *   - Matching/pencarian TechIdentNo presisi (TechIdentSearchService)
 *   - State wizard utama (ReportWizardService)
 *   - Pengiriman pesan ke Telegram API (PollTelegramUpdates)
 *
 * Trait yang digunakan:
 *   - ClarificationKeyboardBuilderTrait : semua builder keyboard per level hierarki
 *   - ClarificationMessageBuilderTrait  : buildCurrentMessage() + buildDoneMessage()
 */
class ClarificationService
{
    use ClarificationKeyboardBuilderTrait;
    use ClarificationMessageBuilderTrait;

    const CACHE_PREFIX = 'clarify_session:';
    const CACHE_TTL    = 1800; // 30 menit

    /** Jumlah maksimum opsi yang ditampilkan dalam satu layar keyboard. */
    protected const MAX_KEYBOARD_OPTIONS = 10;

    protected FuncLocParser $funcLocParser;

    public function __construct(FuncLocParser $funcLocParser)
    {
        $this->funcLocParser = $funcLocParser;
    }

    // =========================================================
    // SESI — BUAT ATAU LANJUTKAN
    // =========================================================

    /**
     * Mulai atau lanjutkan sesi klarifikasi.
     *
     * Jika ada sesi aktif dan teks baru memiliki confidence tinggi (>= 60),
     * sesi lama dihapus dan sesi baru dibuat — artinya teknisi mengirim
     * laporan baru yang cukup jelas, bukan melanjutkan laporan yang ambigu.
     *
     * Jika confidence rendah, sesi lama dilanjutkan tanpa reset.
     *
     * @param  string $chatId   Chat ID Telegram
     * @param  string $text     Teks laporan awal dari teknisi
     * @param  array  $analysis Hasil analisis AI dari AiService
     * @return array  Sesi klarifikasi (baru atau yang sudah ada)
     */
    public function getOrCreateSession(string $chatId, string $text, array $analysis): array
    {
        $session = $this->getSession($chatId);

        if ($session && $session['status'] === 'waiting') {
            $confidence = $analysis['confidence'] ?? 0;

            // Teks baru cukup jelas — reset sesi lama agar tidak campur aduk
            if ($confidence >= 60) {
                $this->destroySession($chatId);
                $session = null;
                Log::info("Clarification: Session destroyed for chat {$chatId} because new message has confidence={$confidence}");
            } else {
                // Teks masih ambigu — lanjutkan sesi yang ada
                return $session;
            }
        }

        // Buat sesi baru dengan semua field diinisialisasi
        $session = [
            'chat_id'                => $chatId,
            'text'                   => $text,
            'analysis'               => $analysis,
            'level'                  => null,
            'status'                 => 'waiting',
            'selected_company_id'    => null,
            'selected_department_id' => null,
            'selected_area_id'       => null,
            'selected_area_code'     => null,
            'selected_sub_area_id'   => null,
            'selected_section_code'  => null,
            'selected_type_code'     => null,
            'selected_asset_id'      => null,
            'created_at'             => now()->toIso8601String(),
        ];

        // Jika AI mendeteksi equipment langsung, simpan sebagai saran saja —
        // konfirmasi dilakukan oleh ReportWizardService via keyboard Ya/Tidak.
        // Jangan set level=done agar teknisi selalu bisa mengoreksi.
        $detectedEquipmentId = $analysis['detected_equipment_id'] ?? null;
        if ($detectedEquipmentId) {
            $asset = Asset::find($detectedEquipmentId);
            if ($asset) {
                $session['suggested_asset_id']    = (int) $detectedEquipmentId;
                $session['suggested_asset_ident'] = $asset->tech_ident_no;

                // Isi lokasi hierarki sebagai konteks navigasi (bukan untuk set done)
                if ($asset->area_id) {
                    $session['selected_area_id']       = $asset->area_id;
                    $session['selected_area_code']     = $asset->area?->code;
                    $session['selected_department_id'] = $asset->area?->department_id;
                    $session['selected_company_id']    = $asset->area?->department?->company_id;
                }
                if ($asset->sub_area_id) {
                    $session['selected_sub_area_id'] = $asset->sub_area_id;
                }
            }
        }

        // Jika AI mendeteksi kode area, isi langsung dan tentukan level awal
        $detectedAreaCode = $analysis['detected_area'] ?? null;
        if ($detectedAreaCode) {
            $session = $this->applyDetectedArea($session, $detectedAreaCode);
        }

        // Akselerasi FuncLoc: cari kode Dept/Area yang disebut di teks laporan
        // agar teknisi tidak perlu klik company -> department satu per satu.
        // Hanya dijalankan jika level belum ditentukan oleh deteksi AI di atas.
        if (!$session['level']) {
            $session = $this->applyFuncLocAcceleration($session, $text);
        }

        // Jika masih belum ada level, mulai dari company (paling atas)
        if (!$session['level']) {
            $session['level'] = 'company_selection';
        }

        $this->saveSession($chatId, $session);
        return $session;
    }

    // =========================================================
    // PROSES PILIHAN KEYBOARD
    // =========================================================

    /**
     * Proses pilihan teknisi dari inline keyboard (callback_data).
     * Format callback: level:action:id (mis. "area:select:5", "equipment:skip").
     *
     * Catatan BUG 3 FIX: izinkan status 'completed' + level 'done' agar
     * auto-select loop di ReportWizardService bisa membaca msgData['done']
     * dengan benar meski session baru saja selesai pada callback yang sama.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback dari Telegram
     * @return array  ['success' => bool, 'session' => array|null, 'error' => string|null]
     */
    public function processSelection(string $chatId, string $callbackData): array
    {
        $session = $this->getSession($chatId);

        if (!$session) {
            return ['success' => false, 'error' => 'Tidak ada sesi aktif'];
        }

        $isJustCompleted = ($session['status'] === 'completed' && $session['level'] === 'done');

        if ($session['status'] !== 'waiting' && !$isJustCompleted) {
            return ['success' => false, 'error' => 'Sesi sudah selesai atau tidak aktif'];
        }

        // Sesi baru saja done — kembalikan langsung tanpa proses aksi apapun
        if ($isJustCompleted) {
            return ['success' => true, 'session' => $session];
        }

        // Parse callback: level:action:id
        $parts  = explode(':', $callbackData);
        $level  = $parts[0] ?? '';
        $action = $parts[1] ?? '';
        $id     = $parts[2] ?? null;

        switch ($level) {
            case 'company':
                if ($action === 'select' && $id) {
                    $session['selected_company_id'] = (int) $id;
                    $session['level']               = 'department_selection';
                } elseif ($action === 'back') {
                    $session['level'] = 'company_selection';
                }
                break;

            case 'department':
                if ($action === 'select' && $id) {
                    $session['selected_department_id'] = (int) $id;
                    $session['level']                  = 'area_selection';
                } elseif ($action === 'back') {
                    $session['level']               = 'company_selection';
                    $session['selected_company_id'] = null;
                }
                break;

            case 'area':
                if ($action === 'select' && $id) {
                    $session['selected_area_id'] = (int) $id;
                    $area                        = Area::find((int) $id);
                    $session['selected_area_code'] = $area ? $area->code : null;

                    $subAreas         = SubArea::where('area_id', (int) $id)->count();
                    $session['level'] = ($subAreas > 0)
                        ? 'subarea_selection'
                        : $this->nextLevelAfterAreaOrSubArea((int) $id, null);
                } elseif ($action === 'back') {
                    $session['level']                  = 'department_selection';
                    $session['selected_department_id'] = null;
                    $session['selected_area_id']       = null;
                    $session['selected_area_code']     = null;
                }
                break;

            case 'subarea':
                if ($action === 'select' && $id) {
                    $session['selected_sub_area_id'] = (int) $id;
                    $session['level']                = $this->nextLevelAfterAreaOrSubArea(
                        $session['selected_area_id'],
                        (int) $id
                    );
                } elseif ($action === 'skip') {
                    // Lewati sub area -> langsung ke equipment (atau section dulu)
                    $session['level'] = $this->nextLevelAfterAreaOrSubArea(
                        $session['selected_area_id'],
                        null
                    );
                } elseif ($action === 'back') {
                    $session['level']                = 'area_selection';
                    $session['selected_area_id']     = null;
                    $session['selected_area_code']   = null;
                    $session['selected_sub_area_id'] = null;
                }
                break;

            case 'section':
                if ($action === 'select' && $id) {
                    $session['selected_section_code'] = $id;
                    $session['level']                 = 'type_selection';
                } elseif ($action === 'back') {
                    $session['level'] = $session['selected_sub_area_id']
                        ? 'subarea_selection'
                        : 'area_selection';
                    $session['selected_section_code'] = null;
                }
                break;

            case 'type':
                if ($action === 'select' && $id) {
                    $session['selected_type_code'] = $id;
                    $session['level']              = 'equipment_selection';
                } elseif ($action === 'back') {
                    $session['level']              = 'section_selection';
                    $session['selected_type_code'] = null;
                }
                break;

            case 'equipment':
                if ($action === 'select' && $id) {
                    $session['selected_asset_id'] = (int) $id;
                    $session['level']             = 'done';
                    $session['status']            = 'completed';
                } elseif ($action === 'skip') {
                    // Skip equipment -> anggap pekerjaan area
                    $session['level']        = 'done';
                    $session['status']       = 'completed';
                    $session['is_area_work'] = true;
                } elseif ($action === 'back') {
                    if (!empty($session['selected_type_code'])) {
                        $session['level'] = 'type_selection';
                    } elseif (!empty($session['selected_section_code'])) {
                        $session['level'] = 'section_selection';
                    } elseif ($session['selected_sub_area_id']) {
                        $session['level']             = 'subarea_selection';
                        $session['selected_asset_id'] = null;
                    } else {
                        $session['level']             = 'area_selection';
                        $session['selected_asset_id'] = null;
                    }
                }
                break;

            default:
                return ['success' => false, 'error' => 'Aksi tidak dikenal'];
        }

        $this->saveSession($chatId, $session);
        return ['success' => true, 'session' => $session];
    }

    // =========================================================
    // AKSELERASI FUNCLOC (INTERNAL)
    // =========================================================

    /**
     * Terapkan kode area yang dideteksi AI ke dalam sesi.
     * Mengisi selected_area_id, department_id, company_id, dan menentukan level awal.
     * Mendukung area code tanpa leading zero (mis. "BD1" untuk "BD01").
     *
     * @param  array  $session          Sesi yang sedang dibangun
     * @param  string $detectedAreaCode Kode area dari hasil analisis AI
     * @return array  Sesi dengan data area yang sudah diisi
     */
    private function applyDetectedArea(array $session, string $detectedAreaCode): array
    {
        $searchCode = strtoupper($detectedAreaCode);
        $area       = Area::where('code', $searchCode)->first();

        if (!$area) {
            // Coba dengan leading zero jika belum ditemukan
            if (preg_match('/^([A-Z]+)(\d)$/', $searchCode, $m)) {
                $area = Area::where('code', $m[1] . '0' . $m[2])->first();
            }
        }

        if (!$area) {
            return $session;
        }

        $session['selected_area_id']   = $area->id;
        $session['selected_area_code'] = $area->code;

        if ($area->department) {
            $session['selected_department_id'] = $area->department->id;
            if ($area->department->company) {
                $session['selected_company_id'] = $area->department->company->id;
            }
        }

        $subAreas         = SubArea::where('area_id', $area->id)->count();
        $session['level'] = ($subAreas > 0)
            ? 'subarea_selection'
            : $this->nextLevelAfterAreaOrSubArea($area->id, null);

        return $session;
    }

    /**
     * Terapkan akselerasi FuncLoc berdasarkan kode Dept/Area yang disebut
     * langsung di teks laporan teknisi.
     * Dijalankan hanya jika level belum ditentukan oleh deteksi AI.
     *
     * @param  array  $session Sesi yang sedang dibangun
     * @param  string $text    Teks laporan awal dari teknisi
     * @return array  Sesi dengan data akselerasi yang sudah diisi (jika terdeteksi)
     */
    private function applyFuncLocAcceleration(array $session, string $text): array
    {
        $accel = $this->funcLocParser->detectAccelerationCodes(
            $text,
            Department::all(['id', 'code', 'company_id']),
            Area::all(['id', 'code', 'department_id'])
        );

        if ($accel['area_id']) {
            $session['selected_area_id']       = $accel['area_id'];
            $session['selected_area_code']     = $accel['area_code'];
            $session['selected_department_id'] = $accel['department_id'];
            $session['selected_company_id']    = $accel['company_id'];

            $subAreas         = SubArea::where('area_id', $accel['area_id'])->count();
            $session['level'] = ($subAreas > 0)
                ? 'subarea_selection'
                : $this->nextLevelAfterAreaOrSubArea($accel['area_id'], null);
        } elseif ($accel['department_id']) {
            $session['selected_department_id'] = $accel['department_id'];
            $session['selected_company_id']    = $accel['company_id'];
            $session['level']                  = 'area_selection';
        }

        return $session;
    }

    // =========================================================
    // SESSION MANAGEMENT
    // =========================================================

    /**
     * Ambil sesi klarifikasi dari cache.
     *
     * @param  string     $chatId Chat ID Telegram
     * @return array|null Sesi aktif atau null jika tidak ada
     */
    public function getSession(string $chatId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $chatId);
    }

    /**
     * Simpan sesi klarifikasi ke cache.
     *
     * @param  string $chatId  Chat ID Telegram
     * @param  array  $session Data sesi yang akan disimpan
     * @return void
     */
    public function saveSession(string $chatId, array $session): void
    {
        Cache::put(self::CACHE_PREFIX . $chatId, $session, self::CACHE_TTL);
    }

    /**
     * Hapus sesi klarifikasi dari cache.
     *
     * @param  string $chatId Chat ID Telegram
     * @return void
     */
    public function destroySession(string $chatId): void
    {
        Cache::forget(self::CACHE_PREFIX . $chatId);
    }
}
