<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Report extends Model
{
    protected $fillable = [
        'technician_id',
        'creator_id',
        'collaborator_of',
        'is_manually_edited',
        'report_date',
        'report_code',
        'work_description',
        'work_duration_minutes',
        'root_cause',
        'photo_documentation',
        'photo_hygiene_clearance',
        'wizard_started_at',
        'submitted_at',
        'area_id',
        'funcloc_id',
        'asset_id',
        'report_type',
        'ai_analyzed',
        'ai_confidence',
        'ai_suggestion_json',
        'status',
        'completed_at',
        'telegram_message_id',
    ];

    protected function casts(): array
    {
        return [
            'report_date'             => 'date',
            'work_duration_minutes'   => 'integer',
            'photo_documentation'     => 'array',
            'photo_hygiene_clearance' => 'array',
            'wizard_started_at'       => 'datetime',
            'submitted_at'            => 'datetime',
            'ai_analyzed'             => 'boolean',
            'ai_confidence'           => 'float',
            'ai_suggestion_json'      => 'array',
            'completed_at'            => 'datetime',
            'is_manually_edited'      => 'boolean',
        ];
    }

    // =========================================================
    // RELASI YANG SUDAH ADA
    // =========================================================

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * FuncLoc yang menjadi lokasi pekerjaan laporan ini.
     * Bisa menunjuk ke level mana pun (L1–L3) sesuai pilihan teknisi.
     */
    public function functionalLocation(): BelongsTo
    {
        return $this->belongsTo(FunctionalLocation::class, 'funcloc_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(ReportAiSuggestion::class);
    }

    // =========================================================
    // RELASI BARU — KOLABORASI
    // =========================================================

    /**
     * Teknisi yang menginput laporan ini melalui wizard bot.
     * Berbeda dari technician() jika laporan ini adalah salinan untuk kolaborator.
     *
     * @return BelongsTo<Technician, Report>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'creator_id');
    }

    /**
     * Laporan induk dari laporan kolaborasi ini.
     * Null jika laporan ini bukan salinan kolaborasi.
     *
     * @return BelongsTo<Report, Report>
     */
    public function parentReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'collaborator_of');
    }

    /**
     * Daftar laporan kolaborator yang dibuat dari laporan ini.
     * Hanya terisi pada laporan induk (pengirim asli).
     *
     * @return HasMany<Report>
     */
    public function collaboratorReports(): HasMany
    {
        return $this->hasMany(Report::class, 'collaborator_of');
    }

    // =========================================================
    // FOTO — ACCESSOR & CLEANUP
    // =========================================================

    /**
     * Accessor: array URL publik foto dokumentasi.
     * Disk diambil dari config('telegram.photo_disk') agar selalu sinkron
     * dengan disk yang dipakai PhotoStorageService saat menyimpan foto.
     */
    public function getPhotoDocumentationUrlsAttribute(): array
    {
        $disk  = config('telegram.photo_disk', 'public');
        $paths = $this->photo_documentation ?? [];

        return array_map(
            fn ($path) => Storage::disk($disk)->url($path),
            array_filter($paths)
        );
    }

    /**
     * Accessor: array URL publik foto hygiene clearance.
     */
    public function getPhotoHygieneUrlsAttribute(): array
    {
        $disk  = config('telegram.photo_disk', 'public');
        $paths = $this->photo_hygiene_clearance ?? [];

        return array_map(
            fn ($path) => Storage::disk($disk)->url($path),
            array_filter($paths)
        );
    }

    /**
     * Cek apakah laporan punya foto dokumentasi atau hygiene clearance.
     */
    public function hasPhotos(): bool
    {
        return !empty($this->photo_documentation) || !empty($this->photo_hygiene_clearance);
    }

    /**
     * Hapus semua file foto dari Storage saat laporan dihapus supaya tidak
     * ada file orphan tertinggal di disk.
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Report $report) {
            $paths = array_merge(
                $report->photo_documentation ?? [],
                $report->photo_hygiene_clearance ?? []
            );
            $paths = array_filter($paths);

            if (!empty($paths)) {
                Storage::disk(config('telegram.photo_disk', 'public'))->delete($paths);
            }
        });
    }

    // =========================================================
    // SCOPES
    // =========================================================

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeNeedsReview($query)
    {
        return $query->where('status', 'needs_review');
    }

    /**
     * Generate kode laporan unik dengan format RPT-YYYYMMDD-XXXX.
     * Dipakai oleh ReportWizardService pada Step 8 (Konfirmasi & Simpan).
     */
    public static function generateReportCode(): string
    {
        $prefix = 'RPT-' . now()->format('Ymd') . '-';

        do {
            $code = $prefix . strtoupper(Str::random(4));
        } while (self::where('report_code', $code)->exists());

        return $code;
    }
}
