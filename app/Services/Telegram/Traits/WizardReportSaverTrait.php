<?php

namespace App\Services\Telegram\Traits;

use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\ReportAiSuggestion;
use App\Models\Technician;
use App\Services\CollaborationService;
use App\Services\ReportContinuationMatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WizardReportSaverTrait
 *
 * Menangani penyimpanan laporan ke database dan helper terkait foto:
 *   - saveReport()                  : Simpan laporan ke DB setelah konfirmasi Step 8,
 *                                     termasuk pembuatan laporan kolaborator secara bulk
 *   - buildConfirmationSummary()    : Bangun pesan ringkasan Step 8 (termasuk nama kolaborator)
 *   - generateReportCode()          : Generate kode RPT-YYYYMMDD-XXXX unik per hari
 *   - isValidLocalPhotoPath()       : Validasi apakah nilai adalah path lokal foto
 *   - filterValidLocalPhotoPaths()  : Filter array foto, buang yang bukan path lokal
 *   - resolveCollaboratorNames()    : Ambil nama teknisi dari state collaborator_names atau fallback DB
 *   - persistAiSuggestions()        : Simpan antrean state ai_suggestions_log (Sesi 4) ke tabel
 *                                     report_ai_suggestions setelah laporan utama berhasil dibuat
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - equipmentLabel(array $state): string
 *   - classificationLabel(array $state): string
 *     (dari WizardUtilityTrait — label klasifikasi pekerjaan CM/PM/dCM/Fabrikasi/Sipil)
 *   - formatDuration(int $minutes): string
 *   - formatIndonesianDate(string $dateString): string
 *   - destroyWizard(string $chatId): void
 *   - errorResponse(string $message): array
 *   - navigationButtons(array $state, bool $includeBack = true): array
 *     (dari WizardUtilityTrait — dipakai buildConfirmationSummary untuk tombol Kembali di Step 8)
 */
trait WizardReportSaverTrait
{
    // =========================================================
    // STEP 8 — KONFIRMASI & SIMPAN
    // =========================================================

    /**
     * Bangun pesan ringkasan konfirmasi untuk Step 8.
     * Menampilkan semua data yang akan disimpan agar teknisi bisa verifikasi.
     * Kolaborator ditampilkan dengan format "Nama (NIK)" jika nama tersedia
     * di state collaborator_names, atau hanya NIK jika tidak.
     *
     * @param  array $state State wizard
     * @return array Respons dengan pesan ringkasan dan keyboard Ya/Batalkan
     */
    protected function buildConfirmationSummary(array $state): array
    {
        $equipmentLabel = $this->equipmentLabel($state);
        $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);
        $rootCause      = $state['root_cause'] ?? '-';
        $finding        = $state['finding'] ?? '-';
        $photoDocCount  = count($state['photo_documentation'] ?? []);
        $photoHygCount  = count($state['photo_hygiene_clearance'] ?? []);
        $collabNiks     = $state['collaborator_niks'] ?? [];

        // Tanggal laporan: pakai hasil deteksi dari Step 1, fallback ke hari ini
        $reportDate    = $state['report_date'] ?? now()->toDateString();
        $formattedDate = $this->formatIndonesianDate($reportDate);

        $msg  = "*Step 8/8* — Konfirmasi Laporan\n\n";
        $msg .= "Periksa ringkasan berikut sebelum disimpan:\n\n";
        $msg .= "*Tanggal*   : {$formattedDate}\n";
        $msg .= "*Klasifikasi*: {$this->classificationLabel($state)}\n";
        $msg .= "*Jenis*     : " . ($state['jenis_pekerjaan'] ?? '-') . "\n";

        // Tampilkan FuncLoc jika laporan area, atau Equipment jika laporan alat
        if (!empty($state['funcloc_code'])) {
            $msg .= "*Lokasi*    : {$state['funcloc_code']}";
            if (!empty($state['funcloc_name'])) {
                $msg .= " — {$state['funcloc_name']}";
            }
            $msg .= "\n";
        } else {
            $msg .= "*Equipment* : {$equipmentLabel}\n";
        }

        $msg .= "*Durasi*    : {$duration}\n";
        $msg .= "*Root Cause*: {$rootCause}\n";
        $msg .= "*Temuan*    : {$finding}\n";
        $msg .= "*Foto Dok*  : {$photoDocCount} foto\n";
        $msg .= "*Foto HC*   : {$photoHygCount} foto\n";

        // Tampilkan daftar kolaborator dengan nama jika tersedia
        if (!empty($collabNiks)) {
            $collabNames = $state['collaborator_names'] ?? [];
            $labelList   = $this->buildCollaboratorLabelList($collabNiks, $collabNames);
            $jumlah      = count($collabNiks);
            $msg .= "*Kolaborator*: {$labelList}\n";
            $msg .= "\n_Laporan akan dibuat untuk {$jumlah} kolaborator secara otomatis._\n";
        }

        // Daftar alat/lokasi tambahan pada pekerjaan multi equipment/lokasi.
        $multiItems = $state['multi_items'] ?? [];

        if ($multiItems !== []) {
            $labelMulti = collect($multiItems)
                ->map(fn (array $item) => $item['label'] ?? '-')
                ->implode(', ');

            $msg .= "*Alat Tambahan*: {$labelMulti}\n";
            $msg .= "\n_Laporan induk menyimpan detail & foto; " . count($multiItems)
                . " alat/lokasi tambahan mendapat baris riwayatnya sendiri._\n";
        }

        // Info bila laporan ini merupakan lanjutan dari laporan 'continue'
        // sebelumnya, supaya teknisi tahu deskripsi/lokasi diwarisi dari sana.
        if (!empty($state['lanjutan_dari_id'])) {
            $msg .= "\n*Lanjutan dari*: " . ($state['lanjutan_kode'] ?? ('#' . $state['lanjutan_dari_id'])) . "\n";
        }

        // Tawarkan penautan otomatis: bila ada laporan 'continue' yang mirip
        // (deskripsi/root cause/lokasi) dan teknisi belum menolaknya, tampilkan
        // tombol konfirmasi — penautan TIDAK dilakukan tanpa persetujuan.
        $kandidat = null;

        if (empty($state['lanjutan_dari_id']) && empty($state['lanjutan_ditolak'])) {
            $kandidat = app(ReportContinuationMatcher::class)->cariKandidat($state);

            if ($kandidat !== null) {
                $msg .= "\n*Kemungkinan lanjutan* dari `" . $kandidat->report_code . "`"
                    . " (" . ($kandidat->report_date?->format('d/m/Y') ?? '-') . ")\n"
                    . "Deskripsi/root cause/lokasi pekerjaan ini mirip dengan laporan itu. "
                    . "Ketuk *Ya, Lanjutan* bila benar.\n";
            }
        }

        $msg .= "\nSimpan laporan ini?";

        $tombolUtama = [
            ['text' => 'Done (Selesai)',          'callback_data' => 'wizard:confirm:save_done'],
            ['text' => 'Continue (Lanjut besok)', 'callback_data' => 'wizard:confirm:save_continue'],
            ['text' => 'Batalkan',                'callback_data' => 'wizard:confirm:cancel_report'],
        ];

        if ($kandidat !== null) {
            $tombolUtama[] = ['text' => 'Ya, Lanjutan dari ' . $kandidat->report_code, 'callback_data' => 'wizard:confirm:lanjutan_ya'];
            $tombolUtama[] = ['text' => 'Bukan lanjutan',                            'callback_data' => 'wizard:confirm:lanjutan_tidak'];
        }

        return [
            'message'  => $msg,
            'keyboard' => array_merge($tombolUtama, $this->navigationButtons($state)),
        ];
    }

    /**
     * Buat baris salinan laporan untuk setiap alat/lokasi tambahan pekerjaan ini.
     *
     * Mengikuti pola kolaborasi: baris salinan memakai detail pekerjaan yang sama
     * (deskripsi, root cause, durasi, klasifikasi/jenis, status, penautan lanjutan)
     * tetapi TIDAK menyimpan foto — foto hanya ada di laporan induk sesuai
     * keputusan bisnis — dan menunjuk balik ke induk lewat kolom multi_of.
     * Area baris salinan dihitung ulang dari alat/lokasinya masing-masing.
     *
     * @param  Report      $induk        Laporan induk yang baru dibuat
     * @param  array       $dataLaporan  Data laporan induk (dibagi ke baris salinan)
     * @param  Technician  $technician   Teknisi pembuat laporan
     * @param  array       $state        State wizard
     * @return int  Jumlah baris salinan yang dibuat
     */
    protected function buatSalinanMultiEquipment(
        Report $induk,
        array $dataLaporan,
        Technician $technician,
        array $state
    ): int {
        $jumlah = 0;

        foreach ($state['multi_items'] ?? [] as $item) {
            $asetId    = $item['asset_id'] ?? null;
            $funclocId = $item['funcloc_id'] ?? null;

            // Area dihitung dari alat/lokasi tambahan; fallback ke area induk.
            $areaId = null;

            if ($asetId !== null) {
                $asetItem = Asset::find($asetId);
                $areaId   = $asetItem?->area_id ?? $this->areaIdDariKodeFuncLoc($asetItem?->functional_loc);
            }

            if ($funclocId !== null) {
                $nodeItem = FunctionalLocation::find($funclocId);
                $areaId   = $areaId ?? $nodeItem?->area_id ?? $this->areaIdDariKodeFuncLoc($nodeItem?->code);
            }

            if ($areaId === null) {
                $areaId = $dataLaporan['area_id'] ?? null;
            }

            Report::create(array_merge($dataLaporan, [
                'report_code'             => $this->generateReportCode(),
                'technician_id'           => $technician->id,
                'creator_id'              => $technician->id,
                'asset_id'                => $asetId,
                'funcloc_id'              => $funclocId,
                'area_id'                 => $areaId,
                'report_type'             => $asetId !== null ? 'equipment_repair' : 'area_work',
                'multi_of'                => $induk->id,
                // Dokumentasi & HC disamakan dengan laporan induk: pekerjaan pada
                // beberapa alat/lokasi memakai bukti foto yang sama. Path fotonya
                // sama persis, jadi tidak ada file foto duplikat di penyimpanan.
                'photo_documentation'     => $induk->photo_documentation ?? [],
                'photo_hygiene_clearance' => $induk->photo_hygiene_clearance ?? [],
            ]));

            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Simpan laporan ke DB (pendekatan "Create at End").
     * Hanya dipanggil setelah teknisi mengonfirmasi di Step 8.
     * Foto yang bukan path lokal (belum diproses PhotoStorageService) dibuang.
     *
     * Jika state mengandung collaborator_niks, laporan tambahan dibuat secara bulk
     * untuk setiap kolaborator. Jika NIK tidak ditemukan di DB namun nama tersedia
     * di collaborator_names, dicoba fallback pencarian by nama (exact match pertama).
     * Kolom creator_id di semua laporan diisi dengan ID teknisi pengirim, dan
     * collaborator_of di laporan kolaborator menunjuk ke ID laporan utama.
     *
     * Respons sukses membawa dua pesan terpisah agar tidak terjadi pesan ganda
     * di Telegram:
     *   - edit_message    : pesan pendek yang dipakai untuk menimpa pesan
     *                       konfirmasi Step 8 (via editMessageText)
     *   - success_message : pesan detail lengkap yang dikirim sebagai pesan
     *                       baru (via sendMessage)
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons sukses (dengan edit_message & success_message) atau error
     */
    protected function saveReport(string $chatId, array $state): array
    {
        try {
            $technician = Technician::where('telegram_id', $chatId)->first();
            if (!$technician) {
                return $this->errorResponse(
                    "Akun teknisi tidak ditemukan untuk chat ini.\n" .
                    "Hubungi admin untuk mendaftarkan Telegram ID kamu."
                );
            }

            $reportType = 'general';
            if (!empty($state['is_area_work'])) {
                $reportType = 'area_work';
            } elseif (!empty($state['equipment_id'])) {
                $reportType = 'equipment_repair';
            }

            // Jaring pengaman: bila laporan punya alat tapi kolom lokasinya kosong
            // (mis. laporan dari jalur lain/sesi lama), lengkapi dari relasi aset
            // supaya halaman laporan tidak menampilkan lokasi kosong.
            if (!empty($state['equipment_id']) && (empty($state['funcloc_id']) || empty($state['area_id']))) {
                $asetLaporan = Asset::with('functionalLocation')->find($state['equipment_id']);

                if ($asetLaporan !== null) {
                    if (empty($state['funcloc_id']) && $asetLaporan->funcloc_id !== null) {
                        $state['funcloc_id'] = $asetLaporan->funcloc_id;
                    }

                    if (empty($state['area_id'])) {
                        $state['area_id'] = $asetLaporan->area_id
                            ?? $this->areaIdDariKodeFuncLoc($asetLaporan->functional_loc);
                    }
                }
            }

            $reportCode = $this->generateReportCode();

            // Tanggal laporan: pakai hasil deteksi parseDateFromText() dari Step 1,
            // fallback ke hari ini jika tidak terdeteksi/tidak valid.
            $reportDate = $state['report_date'] ?? now()->toDateString();

            // Filter foto: hanya path lokal hasil PhotoStorageService->store() yang masuk DB.
            // Path lokal selalu mengandung tanda "/" (format reports/YYYY/MM/DD/{chat_id}/{file}.jpg).
            // File ID Telegram mentah tanpa "/" dibuang dan dicatat ke log.
            $photoDocumentation    = $this->filterValidLocalPhotoPaths($state['photo_documentation'] ?? []);
            $photoHygieneClearance = $this->filterValidLocalPhotoPaths($state['photo_hygiene_clearance'] ?? []);

            // Data laporan yang dipakai bersama oleh laporan utama dan laporan kolaborator
            $dataLaporan = [
                'report_date'             => $reportDate,
                'work_description'        => $state['text'],
                'asset_id'                => $state['equipment_id'] ?? null,
                'area_id'                 => $state['area_id'] ?? null,
                'funcloc_id'              => $state['funcloc_id'] ?? null,
                'report_type'             => $reportType,
                'work_classification'     => $state['work_classification'] ?? null,
                'jenis_pekerjaan'         => $state['jenis_pekerjaan'] ?? null,
                'ai_analyzed'             => !empty($state['ai_analysis']),
                'ai_confidence'           => $state['ai_analysis']['confidence'] ?? null,
                'work_duration_minutes'   => $state['work_duration_minutes'] ?? null,
                'start_time'              => $state['start_time'] ?? null,
                'end_time'                => $state['end_time'] ?? null,
                'root_cause'              => $state['root_cause'] ?? null,
                'finding'                 => $state['finding'] ?? null,
                'photo_documentation'     => $photoDocumentation,
                'photo_hygiene_clearance' => $photoHygieneClearance,
                // Status akhir dipilih teknisi di Step 8: 'completed' (Done) atau
                // 'continue' (pekerjaan belum tuntas, akan dilanjutkan hari lain).
                // Fallback 'completed' karena status 'draft' tidak dipakai lagi.
                'status'                  => $state['final_status'] ?? 'completed',
                'continued_from_id'       => $state['lanjutan_dari_id'] ?? null,
                'completed_at'            => ($state['final_status'] ?? 'completed') === 'completed' ? now() : null,
                'wizard_started_at'       => $state['created_at'] ?? null,
                'submitted_at'            => now(),
            ];

            // Simpan laporan utama atas nama teknisi pengirim
            $report = Report::create(array_merge($dataLaporan, [
                'report_code'   => $reportCode,
                'technician_id' => $technician->id,
                'creator_id'    => $technician->id,
            ]));

            // Simpan sugesti AI (equipment/area) yang tercatat sepanjang wizard
            // ke report_ai_suggestions, sekarang setelah report_id tersedia.
            $this->persistAiSuggestions($report->id, $state);

            // Buat laporan kolaborator: gabungan pilihan manual teknisi dengan
            // rekan dari kelompok kolaborasi (mis. satu Grub shift). Penggabungan
            // dijalankan lagi di sini sebagai jaring pengaman supaya rekan
            // kelompok tetap ikut andil walau state belum sempat terisi.
            // Nama yang sengaja dihapus teknisi (menu Sesuaikan atau tombol
            // Lewati) tercatat di collaborator_dikecualikan sehingga tidak
            // dimasukkan kembali saat laporan disimpan.
            $gabunganKolaborator = app(CollaborationService::class)->gabungKolaborator(
                $technician,
                $state['collaborator_niks'] ?? [],
                $state['collaborator_names'] ?? [],
                $state['collaborator_dikecualikan'] ?? []
            );

            $collabNiks          = $gabunganKolaborator['niks'];
            $collabNames         = $gabunganKolaborator['names'];
            $jumlahKolaborator   = 0;
            $kolaboratorBerhasil = [];

            foreach ($collabNiks as $index => $nik) {
                // Coba cari teknisi by NIK terlebih dahulu
                $kolaborator = Technician::where('nik', $nik)->first();

                // Fallback: cari by nama jika NIK tidak ditemukan namun nama tersedia
                if (!$kolaborator && isset($collabNames[$index]) && $collabNames[$index] !== '') {
                    $namaFallback = $collabNames[$index];
                    $kolaborator  = Technician::where('name', $namaFallback)->first();

                    if ($kolaborator) {
                        Log::info("WizardService: Kolaborator ditemukan via fallback nama", [
                            'nik_asal'  => $nik,
                            'nama'      => $namaFallback,
                            'technician_id' => $kolaborator->id,
                            'report_id' => $report->id,
                        ]);
                    }
                }

                if (!$kolaborator) {
                    Log::warning("WizardService: NIK kolaborator tidak ditemukan saat saveReport", [
                        'nik'        => $nik,
                        'nama_index' => $collabNames[$index] ?? null,
                        'report_id'  => $report->id,
                    ]);
                    continue;
                }

                Report::create(array_merge($dataLaporan, [
                    'report_code'     => $this->generateReportCode(),
                    'technician_id'   => $kolaborator->id,
                    'creator_id'      => $technician->id,
                    'collaborator_of' => $report->id,
                ]));

                $jumlahKolaborator++;
                $kolaboratorBerhasil[] = $kolaborator->name . ' (' . $nik . ')';
            }

            // Baris salinan per alat/lokasi tambahan (pekerjaan multi equipment).
            // Laporan INDUK menyimpan detail pekerjaan + foto; setiap baris salinan
            // hanya memuat identitas alat/lokasi (agar riwayat alat & halaman lokasi
            // ikut menampilkan pekerjaan ini) dan menunjuk balik lewat multi_of.
            $jumlahMulti = $this->buatSalinanMultiEquipment($report, $dataLaporan, $technician, $state);

            $this->destroyWizard($chatId);

            Log::info("WizardService: Report saved for chat {$chatId}", [
                'report_id'          => $report->id,
                'report_code'        => $reportCode,
                'report_date'        => $reportDate,
                'funcloc_id'         => $state['funcloc_id'] ?? null,
                'funcloc_code'       => $state['funcloc_code'] ?? null,
                'photo_doc_count'    => count($photoDocumentation),
                'photo_hyg_count'    => count($photoHygieneClearance),
                'jumlah_kolaborator' => $jumlahKolaborator,
                'ai_suggestions_logged' => count($state['ai_suggestions_log'] ?? []),
            ]);

            $equipmentLabel = $this->equipmentLabel($state);
            $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);
            $formattedDate  = $this->formatIndonesianDate($reportDate);
            $rootCauseShort = Str::limit($state['root_cause'] ?? '-', 80);
            $findingShort   = Str::limit($state['finding'] ?? '-', 80);
            $submittedAt    = $report->submitted_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i');
            $photoDocCount  = count($photoDocumentation);
            $photoHygCount  = count($photoHygieneClearance);

            // Total laporan yang berhasil dibuat (utama + kolaborator)
            $totalLaporan = 1 + $jumlahKolaborator;

            // Pesan pendek untuk menimpa pesan konfirmasi Step 8 (edit in-place)
            $editMsg  = "*Laporan Tersimpan*\n\n";
            $editMsg .= "Kode: `{$reportCode}`\n";
            if ($jumlahKolaborator > 0) {
                $editMsg .= "Total laporan dibuat: {$totalLaporan} (termasuk {$jumlahKolaborator} kolaborator).\n";
            }
            $editMsg .= "Detail laporan dikirim di pesan berikutnya.";

            // Pesan detail lengkap, dikirim sebagai pesan baru
            $successMsg  = "*Laporan Berhasil Disimpan!*\n\n";
            $successMsg .= "Kode Laporan : `{$reportCode}`\n";
            $successMsg .= "Teknisi      : {$technician->name}\n";
            $successMsg .= "Tanggal      : {$formattedDate}\n";
            $successMsg .= "Klasifikasi  : {$this->classificationLabel($state)}\n";

            // Tampilkan Lokasi FuncLoc jika laporan area, atau Equipment jika laporan alat
            if (!empty($state['funcloc_code'])) {
                $funclocLabel = $state['funcloc_code'];
                if (!empty($state['funcloc_name'])) {
                    $funclocLabel .= ' — ' . $state['funcloc_name'];
                }
                $successMsg .= "Lokasi       : {$funclocLabel}\n";
            } else {
                $successMsg .= "Equipment    : {$equipmentLabel}\n";
            }

            $successMsg .= "Durasi       : {$duration}\n";
            $successMsg .= "Foto Dok     : {$photoDocCount} foto\n";
            $successMsg .= "Foto HC      : {$photoHygCount} foto\n";
            $successMsg .= "Root Cause   : {$rootCauseShort}\n";
            $successMsg .= "Temuan       : {$findingShort}\n";
            $successMsg .= "Waktu Submit : {$submittedAt}\n";

            // Informasi kolaborator dengan nama jika ada
            if ($jumlahKolaborator > 0) {
                $labelList   = implode(', ', $kolaboratorBerhasil);
                $successMsg .= "\n*Kolaborator* : {$labelList}\n";
                $successMsg .= "_Laporan terpisah sudah dibuat untuk {$jumlahKolaborator} kolaborator._\n";
            }

            $successMsg .= "\n";

            if ($photoHygCount === 0) {
                // Foto HC belum ada — sertakan instruksi cara menambahkannya nanti.
                // Sistem deteksi caption di detectPhotoTypeFromCaption() sudah mengenali
                // kata "hygiene" untuk menentukan tipe foto yang ditambahkan.
                $successMsg .= "_Foto HC belum ada. Untuk menambahkan foto Hygiene Clearance ";
                $successMsg .= "nanti, kirim foto dengan caption:_\n";
                $successMsg .= "`{$reportCode} hygiene`";
            } else {
                $successMsg .= "Gunakan kode di atas untuk menambah foto ke laporan ini di kemudian hari.";
            }

            return [
                'edit_message'    => $editMsg,
                'success_message' => $successMsg,
                'keyboard'        => [],
                'report_code'     => $reportCode,
                'report_id'       => $report->id,
                'saved'           => true,
            ];
        } catch (\Throwable $e) {
            Log::error("WizardService: Failed to save report for chat {$chatId}", [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                "Terjadi kesalahan saat menyimpan laporan. Silakan coba lagi atau hubungi admin.\n" .
                "Error: " . $e->getMessage()
            );
        }
    }

    // =========================================================
    // SESI 4 — PENCATATAN SUGESTI AI
    // =========================================================

    /**
     * Simpan seluruh sugesti AI (equipment maupun area) yang tercatat di
     * state['ai_suggestions_log'] selama wizard berjalan, ke tabel
     * report_ai_suggestions, setelah laporan utama berhasil dibuat.
     *
     * Antrean ini diisi oleh WizardCallbackHandlerTrait::logAiSuggestionDecision(),
     * dipanggil setiap kali teknisi merespons (Ya/Tidak) sebuah sugesti AI —
     * baik yang akhirnya diterima maupun ditolak. Penyimpanan ke DB baru
     * dilakukan di sini (bukan langsung saat teknisi merespons) karena pada
     * titik itu laporan belum ada (pendekatan "Create at End" — lihat
     * saveReport()); report_id baru tersedia setelah laporan utama dibuat.
     *
     * Jika wizard dibatalkan sebelum Step 8, antrean ini hilang bersama state
     * (Cache::forget di destroyWizard()) dan tidak pernah tercatat ke DB —
     * ini sudah sesuai tujuan (audit/pembelajaran AI), karena tidak ada
     * laporan yang dihasilkan untuk dikaitkan.
     *
     * @param  int   $reportId ID laporan utama yang baru dibuat
     * @param  array $state    State wizard saat ini
     * @return void
     */
    protected function persistAiSuggestions(int $reportId, array $state): void
    {
        $suggestions = $state['ai_suggestions_log'] ?? [];

        if (empty($suggestions)) {
            return;
        }

        foreach ($suggestions as $suggestion) {
            ReportAiSuggestion::create([
                'report_id'          => $reportId,
                'suggestion_type'    => $suggestion['suggestion_type'] ?? null,
                'suggested_asset_id' => $suggestion['suggested_asset_id'] ?? null,
                'suggested_area_id'  => $suggestion['suggested_area_id'] ?? null,
                'confidence'         => $suggestion['confidence'] ?? null,
                'reasoning'          => $suggestion['reasoning'] ?? null,
                'accepted'           => $suggestion['accepted'] ?? false,
            ]);
        }
    }

    // =========================================================
    // HELPER KODE LAPORAN & VALIDASI FOTO
    // =========================================================

    /**
     * Generate kode laporan RPT-YYYYMMDD-XXXX.
     * XXXX adalah sequence 4-digit unik per hari, dimulai dari 0001.
     *
     * @return string Kode laporan baru
     */
    protected function generateReportCode(): string
    {
        $date     = now()->format('Ymd');
        $prefix   = "RPT-{$date}-";
        $lastCode = Report::where('report_code', 'like', $prefix . '%')
            ->orderByDesc('report_code')
            ->value('report_code');

        if ($lastCode) {
            $lastSeq = (int) substr($lastCode, -4);
            $seq     = $lastSeq + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cek apakah sebuah nilai foto adalah path lokal hasil PhotoStorageService->store().
     * Path lokal selalu mengandung tanda "/" (format: reports/YYYY/MM/DD/{chat_id}/{filename}.jpg).
     * File ID Telegram asli tidak pernah mengandung "/".
     *
     * @param  mixed $value Nilai yang akan dicek
     * @return bool
     */
    protected function isValidLocalPhotoPath(mixed $value): bool
    {
        return is_string($value) && $value !== '' && str_contains($value, '/');
    }

    /**
     * Filter array foto agar hanya berisi path lokal yang valid.
     * Entri yang bukan path lokal (misal file_id Telegram mentah yang lolos
     * tanpa diproses PhotoStorageService) dibuang dan dicatat ke log.
     *
     * @param  array $photos Array path atau file_id foto
     * @return array Array yang hanya berisi path lokal valid
     */
    protected function filterValidLocalPhotoPaths(array $photos): array
    {
        $valid   = [];
        $invalid = [];

        foreach ($photos as $photo) {
            if ($this->isValidLocalPhotoPath($photo)) {
                $valid[] = $photo;
            } else {
                $invalid[] = $photo;
            }
        }

        if (!empty($invalid)) {
            Log::warning('WizardService: Foto tidak valid dibuang saat saveReport (bukan path lokal)', [
                'invalid_count' => count($invalid),
                'invalid_items' => $invalid,
            ]);
        }

        return $valid;
    }

    // =========================================================
    // HELPER KOLABORATOR
    // =========================================================

    /**
     * Bangun string label daftar kolaborator untuk pesan ringkasan.
     * Format: "Nama (NIK), Nama (NIK)" jika nama tersedia,
     * atau "NIK, NIK" jika collaborator_names tidak ada.
     *
     * @param  array $niks  Array NIK kolaborator
     * @param  array $names Array nama kolaborator (indeks bersesuaian dengan $niks)
     * @return string       String label kolaborator
     */
    private function buildCollaboratorLabelList(array $niks, array $names): string
    {
        $labels = [];

        foreach ($niks as $index => $nik) {
            $nama = $names[$index] ?? '';
            if ($nama !== '') {
                $labels[] = "{$nama} ({$nik})";
            } else {
                $labels[] = $nik;
            }
        }

        return implode(', ', $labels);
    }
}
