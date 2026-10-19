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
        'start_time',
        'end_time',
        'root_cause',
        // Temuan/kejadian di lapangan yang dicatat teknisi SEBELUM root cause
        // (opsional, bisa dilewati lewat tombol "Lewati Temuan").
        'finding',
        'photo_documentation',
        'photo_hygiene_clearance',
        'wizard_started_at',
        'submitted_at',
        'area_id',
        'funcloc_id',
        'asset_id',
        'report_type',
        // Klasifikasi pekerjaan dari wizard Telegram (kode ZPM)
        'work_classification',
        // Klasifikasi modul CM/dCM/PM (proyek teman)
        'jenis_pekerjaan',
        'linked_finding_id',
        'sesuai_rencana',
        'tanggal_kejadian',
        'equipment_tag',
        'ai_analyzed',
        'ai_confidence',
        'ai_suggestion_json',
        'status',
        'continued_from_id',
        'multi_of',
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
            'tanggal_kejadian'        => 'date:Y-m-d',
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
    // RELASI — CM / MAINTENANCE
    // =========================================================

    /**
     * Finding CM yang menjadi asal-usul pekerjaan ini (jika ada).
     *
     * @return BelongsTo<CmFinding, Report>
     */
    public function linkedFinding(): BelongsTo
    {
        return $this->belongsTo(CmFinding::class, 'linked_finding_id');
    }

    /**
     * Equipment CM yang terkait dengan laporan ini.
     * Cocokkan berdasarkan equipment_tag.
     *
     * @return BelongsTo<CmEquipment, Report>
     */
    public function cmEquipment(): BelongsTo
    {
        return $this->belongsTo(CmEquipment::class, 'equipment_tag', 'equipment_tag');
    }

    /**
     * Laporan sebelumnya yang dilanjutkan oleh laporan ini (status 'continue').
     *
     * @return BelongsTo<Report, Report>
     */
    public function continuedFrom(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'continued_from_id');
    }

    /**
     * Laporan lanjutan yang menunjuk balik ke laporan ini (bisa lebih dari satu
     * bila pekerjaan sama berlanjut beberapa hari).
     *
     * @return HasMany<Report>
     */
    public function continuedBy(): HasMany
    {
        return $this->hasMany(Report::class, 'continued_from_id');
    }

    /**
     * Laporan yang masih terbuka untuk dilanjutkan (status 'continue' dan belum
     * pernah dilanjutkan laporan lain).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Report>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Report>
     */
    public function scopeBelumDilanjutkan($query)
    {
        return $query->where('status', 'continue')->whereDoesntHave('continuedBy');
    }

    /**
     * Laporan induk dari baris salinan pekerjaan multi equipment/lokasi.
     *
     * @return BelongsTo<Report, Report>
     */
    public function multiOf(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'multi_of');
    }

    /**
     * Baris salinan per alat/lokasi tambahan pada pekerjaan ini.
     *
     * @return HasMany<Report>
     */
    public function multiItems(): HasMany
    {
        return $this->hasMany(Report::class, 'multi_of');
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
     * Accessor: label durasi pengerjaan dalam format "X Jam Y Menit".
     * Mengembalikan null jika durasi tidak tersedia.
     */
    public function getDurationLabelAttribute(): ?string
    {
        $minutes = $this->work_duration_minutes;

        if (!$minutes) {
            return null;
        }

        $jam   = intdiv($minutes, 60);
        $menit = $minutes % 60;

        $parts = [];
        if ($jam > 0) {
            $parts[] = $jam . ' Jam';
        }
        if ($menit > 0) {
            $parts[] = $menit . ' Menit';
        }

        return $parts ? implode(' ', $parts) : null;
    }

    /**
     * Accessor: rentang waktu pengerjaan "HH:MM - HH:MM".
     * Mengembalikan null jika wizard_started_at atau submitted_at kosong.
     */
    public function getTimeRangeAttribute(): ?string
    {
        if (!$this->start_time || !$this->end_time) {
            return null;
        }

        return $this->start_time . ' - ' . $this->end_time;
    }

    /**
     * Accessor: label alat/lokasi tambahan pekerjaan multi item, dipakai daftar
     * item pada halaman edit/detail laporan.
     *
     * Contoh: "ALCY01 - Pompa transfer" untuk item berupa alat, atau
     * "EPE-PROD-BD02-6153 - Oil Pre-treatment" untuk item berupa lokasi.
     * Dipakai BERSAMA oleh tampilan server (blade) dan balasan JSON halaman
     * edit supaya labelnya tidak pernah berbeda antara keduanya.
     */
    public function getMultiItemLabelAttribute(): string
    {
        if ($this->asset !== null) {
            $kode = $this->asset->tech_ident_no ?? $this->asset->equipment_no ?? ('#' . $this->asset->id);

            return trim($kode . ' - ' . ($this->asset->description ?? ''));
        }

        if ($this->functionalLocation !== null) {
            return trim($this->functionalLocation->code . ' - ' . $this->functionalLocation->name);
        }

        return 'Item #' . $this->id;
    }

    /**
     * Accessor: nama teknisi PEMBUAT laporan (orang yang menginput pekerjaan).
     *
     * Pada baris salinan kolaborasi pemilik barisnya adalah kolaborator,
     * sehingga pembuat aslinya dibaca dari creator_id. Untuk data lama yang
     * creator_id-nya kosong, nama diambil dari laporan induk (creator, lalu
     * teknisi pemilik induk) supaya lencana tidak pernah salah menunjuk
     * kolaborator sebagai pembuat.
     *
     * @return string|null Nama pembuat, null bila tidak bisa ditelusuri
     */
    public function getNamaPembuatAttribute(): ?string
    {
        $pembuat = $this->creator?->name;

        if ($pembuat !== null) {
            return $pembuat;
        }

        if ($this->collaborator_of !== null) {
            $induk = $this->parentReport;

            return $induk?->creator?->name ?? $induk?->technician?->name;
        }

        // Laporan biasa: pemilik laporan sekaligus pembuat pekerjaannya.
        return $this->technician?->name;
    }

    /**
     * Perawatan saat laporan dihapus:
     *   1. baris salinan milik laporan ini (kolaborasi & multi alat) ikut
     *      dihapus supaya tidak ada baris yatim,
     *   2. berkas foto di Storage baru dihapus bila TIDAK dipakai laporan lain.
     *
     * Baris salinan kolaborasi dan salinan multi alat memakai PATH FOTO YANG
     * SAMA dengan laporan induk. Tanpa pemeriksaan pemakaian, menghapus satu
     * baris salinan ikut menghapus berkas foto milik induk (data hilang).
     * Sebaliknya kolom multi_of memakai aturan nullOnDelete di database sehingga
     * menghapus laporan INDUK meninggalkan baris salinannya sebagai laporan
     * biasa — jamnya lalu terhitung dua kali di man hour — karena itu salinan
     * dihapus lebih dulu di sini.
     */
    protected static function boot()
    {
        parent::boot();

        /*
         * Lengkapi equipment_tag dari asset yang dipilih.
         *
         * Sejak 19/10/2026 laporan yang dibuat wizard Telegram / form web hanya
         * mengisi asset_id. Akibatnya halaman "Laporan Teknisi" pada detail
         * equipment CM (yang mencocokkan reports.equipment_tag dengan
         * cm_equipment.equipment_tag) tidak pernah menemukan laporan tersebut.
         *
         * Kunci yang benar adalah assets.tech_ident_no (bukan equipment_no):
         * CM memakai Tech Ident No sebagai equipment tag. Nilai equipment_tag
         * yang sudah diisi manual TIDAK ditimpa.
         */
        static::saving(function (Report $report): void {
            if (filled($report->equipment_tag) || $report->asset_id === null) {
                return;
            }

            $tag = Asset::query()->whereKey($report->asset_id)->value('tech_ident_no');

            if (filled($tag)) {
                $report->equipment_tag = trim((string) $tag);
            }
        });

        static::deleting(function (Report $report) {
            // 1. Baris salinan (kolaborasi & multi alat) milik laporan ini.
            foreach ([$report->collaboratorReports(), $report->multiItems()] as $relasi) {
                foreach ($relasi->get() as $salinan) {
                    $salinan->delete();
                }
            }

            // 2. Berkas foto: hanya yang tidak dipakai laporan lain.
            $paths = array_values(array_filter(array_merge(
                $report->photo_documentation ?? [],
                $report->photo_hygiene_clearance ?? []
            )));

            if ($paths === []) {
                return;
            }

            $dipakaiLain = self::pathFotoLaporanLain((int) $report->getKey());
            $hapus       = array_values(array_filter(
                $paths,
                fn (string $path): bool => ! isset($dipakaiLain[$path])
            ));

            if ($hapus !== []) {
                Storage::disk(config('telegram.photo_disk', 'public'))->delete($hapus);
            }
        });
    }

    /**
     * Kumpulan path foto yang masih dipakai laporan LAIN (selain laporan yang
     * sedang dihapus).
     *
     * Dibaca dalam SATU query lintas seluruh laporan supaya penghapusan tidak
     * memicu banyak pencarian, dan sengaja TIDAK memakai fungsi JSON khusus
     * MySQL/SQLite agar perilakunya sama di kedua database.
     *
     * @param  int $idDikecualikan ID laporan yang sedang dihapus
     * @return array<string, bool> Peta path => true
     */
    public static function pathFotoLaporanLain(int $idDikecualikan): array
    {
        $dipakai = [];

        $barisLain = self::query()
            ->whereKeyNot($idDikecualikan)
            ->where(function ($query): void {
                $query->whereNotNull('photo_documentation')
                    ->orWhereNotNull('photo_hygiene_clearance');
            })
            ->get(['id', 'photo_documentation', 'photo_hygiene_clearance']);

        foreach ($barisLain as $baris) {
            $paths = array_merge(
                $baris->photo_documentation ?? [],
                $baris->photo_hygiene_clearance ?? []
            );

            foreach ($paths as $path) {
                if (is_string($path) && $path !== '') {
                    $dipakai[$path] = true;
                }
            }
        }

        return $dipakai;
    }

    // =========================================================
    // WAKTU KERJA (jam mulai - jam selesai)
    // =========================================================

    /**
     * Penanda "akhir hari" untuk jam selesai (tengah malam berikutnya).
     *
     * Dipakai 18 laporan PROD yang tersimpan dengan end_time = 24:00
     * (diperiksa 12/10/2026 pada oleochemical.reports), sehingga form edit
     * harus bisa membuka dan menyimpan ulang nilai itu apa adanya. Nilai ini
     * TIDAK berlaku untuk jam MULAI (lihat durasiDariJam()).
     */
    public const JAM_AKHIR_HARI = '24:00';

    /**
     * Jumlah menit dalam satu hari penuh (24 x 60). Dasar perhitungan rentang
     * yang melewati tengah malam, dipakai juga oleh pemanggil di luar model.
     */
    public const MENIT_SEHARI = 1440;

    /**
     * Hitung durasi pengerjaan (menit) dari rentang jam "HH:MM".
     *
     * Aturan TUNGGAL jam kerja laporan (dipakai form edit, wizard Telegram,
     * parser AI, dan ManHourService — semua jalur lain mendelegasikan ke sini):
     *   - Jam selesai LEBIH KECIL dari jam mulai dianggap pekerjaan yang
     *     melewati tengah malam (mis. 22:00 - 06:00 = 8 jam = 480 menit),
     *     sehingga perhitungannya ditambah 24 jam.
     *   - Jam selesai "24:00" diterima sebagai akhir hari (mis. 23:00 - 24:00
     *     = 60 menit) karena data laporan lama memakai nilai itu.
     *   - Jam selesai SAMA dengan jam mulai BUKAN rentang: hasilnya null
     *     (ditolak) supaya salah ketik tidak tersimpan sebagai durasi 0 menit.
     *   - Jam mulai "24:00" juga null karena tidak wajar sebagai awal kerja.
     *   - Salah satu kosong atau formatnya tidak dikenali -> null, supaya
     *     durasi yang sudah tersimpan tidak ikut terhapus.
     *
     * @param  string|null  $mulai    Jam mulai kerja format HH:MM
     * @param  string|null  $selesai  Jam selesai kerja format HH:MM (boleh 24:00)
     * @return int|null               Durasi dalam menit, null bila tidak bisa dihitung
     */
    public static function durasiDariJam(?string $mulai, ?string $selesai): ?int
    {
        $menitMulai   = self::menitDariJam($mulai);
        $menitSelesai = self::menitDariJam($selesai);

        if ($menitMulai === null || $menitSelesai === null) {
            return null;
        }

        // Jam mulai tidak boleh penanda akhir hari.
        if ($menitMulai >= self::MENIT_SEHARI) {
            return null;
        }

        // Jam sama bukan rentang: ditolak supaya salah ketik tidak menjadi
        // 0 menit (man hour hilang) atau 24 jam (man hour meledak).
        if ($menitMulai === $menitSelesai) {
            return null;
        }

        if ($menitSelesai < $menitMulai) {
            $menitSelesai += self::MENIT_SEHARI;
        }

        return $menitSelesai - $menitMulai;
    }

    /**
     * Apakah rentang jam melewati tengah malam (jam selesai lebih kecil dari
     * jam mulai, mis. 22:00 - 06:00). Dipakai form edit laporan untuk
     * menampilkan peringatan agar admin sadar durasinya dihitung +24 jam.
     *
     * @param  string|null  $mulai    Jam mulai kerja format HH:MM
     * @param  string|null  $selesai  Jam selesai kerja format HH:MM
     * @return bool
     */
    public static function lintasTengahMalam(?string $mulai, ?string $selesai): bool
    {
        $menitMulai   = self::menitDariJam($mulai);
        $menitSelesai = self::menitDariJam($selesai);

        if ($menitMulai === null || $menitSelesai === null) {
            return false;
        }

        return $menitSelesai < $menitMulai;
    }

    /**
     * Ubah jam "HH:MM" menjadi jumlah menit sejak tengah malam.
     *
     * Inilah SATU-SATUNYA pengubah jam menjadi menit di aplikasi: wizard
     * Telegram (WizardUtilityTrait), parser AI (AiKeywordParserTrait), dan
     * ManHourService mendelegasikan ke method ini supaya aturan jam tidak
     * berbeda antar jalur.
     *
     * Nilai "24:00" dikenali sebagai 1440 menit karena data laporan lama
     * memakai nilai itu untuk jam selesai; pemakaian yang lebih ketat dijaga
     * durasiDariJam() (jam MULAI tidak boleh 24:00).
     *
     * @param  string|null  $jam  Jam format HH:MM (boleh satu digit jam, mis. 8:05)
     * @return int|null           Menit sejak 00:00 (0-1440), null bila format tidak dikenali
     */
    public static function menitDariJam(?string $jam): ?int
    {
        $teks = trim((string) $jam);

        if ($teks === '') {
            return null;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $teks, $cocok)) {
            return null;
        }

        $jamKe   = (int) $cocok[1];
        $menitKe = (int) $cocok[2];

        if ($menitKe > 59) {
            return null;
        }

        // Akhir hari hanya boleh persis 24:00, bukan 24:30.
        if ($jamKe === 24 && $menitKe !== 0) {
            return null;
        }

        if ($jamKe > 24) {
            return null;
        }

        return ($jamKe * 60) + $menitKe;
    }

    // =========================================================
    // SCOPES
    // =========================================================

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeNeedsReview($query)
    {
        return $query->where('status', 'needs_review');
    }

    /**
     * Scope: filter berdasarkan jenis pekerjaan (CM/dCM/PM).
     */
    public function scopeJenis($query, $jenis)
    {
        return $query->where('jenis_pekerjaan', $jenis);
    }

    /**
     * Scope: filter berdasarkan rentang tanggal kejadian.
     */
    public function scopeTanggalKejadian($query, $dari, $sampai)
    {
        return $query->whereBetween('tanggal_kejadian', [$dari, $sampai]);
    }

    /**
     * Scope: filter berdasarkan equipment_tag.
     */
    public function scopeEquipment($query, $tag)
    {
        return $query->where('equipment_tag', $tag);
    }

    /**
     * Generate kode laporan unik dengan format RPT-YYYYMMDD-XXXX.
     * XXXX adalah nomor urut harian 4 digit (mulai 0001). Method ini dipakai
     * SEMUA jalur pembuatan laporan (wizard Telegram, form web, dan baris
     * salinan kolaborasi/multi alat) supaya format kodenya seragam.
     *
     * Kode yang berakhiran BUKAN angka (mis. RPT-20261007-Q5UW dari generator
     * lama) sengaja DIABAIKAN saat menghitung nomor urut. Bila ikut dihitung,
     * substr(-4) menghasilkan 0 sehingga nomor urut kembali ke 0001 dan seluruh
     * penyimpanan gagal dengan galat duplikat (insiden 07/10/2026).
     *
     * @return string Kode laporan baru yang belum dipakai
     */
    public static function generateReportCode(): string
    {
        $prefix = 'RPT-' . now()->format('Ymd') . '-';

        // Nomor urut terakhir hari ini, dibaca sekali lewat satu query.
        $urutTerakhir = 0;

        foreach (self::where('report_code', 'like', $prefix . '%')->pluck('report_code') as $kodeLama) {
            $ekor = substr((string) $kodeLama, -4);

            if (ctype_digit($ekor)) {
                $urutTerakhir = max($urutTerakhir, (int) $ekor);
            }
        }

        $urut = $urutTerakhir + 1;

        if ($urut <= 9999) {
            return $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
        }

        // Seluruh 9999 nomor hari ini terpakai (praktis tidak terjadi):
        // pakai akhiran acak sebagai jalan terakhir.
        do {
            $kode = $prefix . strtoupper(Str::random(4));
        } while (self::where('report_code', $kode)->exists());

        return $kode;
    }
}
