<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'name',
        'nik',
        'department_id',
        'area_ids',
        'group',
        'section',
        'status',
        'approved_by',
        'approved_at',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'area_ids'       => 'array',
            'approved_at'    => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public const GROUPS = [
        'reguler'       => 'Reguler',
        'reguler_staff' => 'Reguler Staff',
        'grub_a'        => 'Grub A',
        'grub_b'        => 'Grub B',
        'grub_c'        => 'Grub C',
        'grub_d'        => 'Grub D',
        'grub_a_staff'  => 'Staff Grub A',
        'grub_b_staff'  => 'Staff Grub B',
        'grub_c_staff'  => 'Staff Grub C',
        'grub_d_staff'  => 'Staff Grub D',
    ];

    /**
     * Group pekerja shift (dipakai untuk tampilan Shift, pola rotasi, dan
     * penentuan target man hour berbasis shift). Jangan memakai
     * "GROUPS kecuali reguler" karena group non-shift ada dua.
     */
    public const GRUP_SHIFT = ['grub_a', 'grub_b', 'grub_c', 'grub_d'];

    /**
     * Group non-shift: reguler (Senin-Sabtu) dan reguler staff (Senin-Jumat).
     * Laporan kedua group ini masuk ke tampilan "Reguler" di halaman Laporan.
     */
    public const GRUP_NON_SHIFT = ['reguler', 'reguler_staff'];

    /**
     * Group STAFF yang menaungi satu grub shift. Staff Grub A-D bukan pekerja
     * shift (tidak punya pola rotasi); ia hanya berperan sebagai pengelola
     * laporan grub yang dinaunginya.
     */
    public const GRUP_STAFF = ['grub_a_staff', 'grub_b_staff', 'grub_c_staff', 'grub_d_staff'];

    /**
     * Peta Staff Grub -> grub yang dinaunginya. Dipakai untuk menyatukan
     * laporan Staff Grub ke tampilan Shift dan untuk membatasi laporan yang
     * boleh dikelolanya (lihat User::bolehKelolaLaporan()).
     */
    public const GRUP_STAFF_INDUK = [
        'grub_a_staff' => 'grub_a',
        'grub_b_staff' => 'grub_b',
        'grub_c_staff' => 'grub_c',
        'grub_d_staff' => 'grub_d',
    ];

    /**
     * Apakah group teknisi termasuk pekerja shift (Grub A-D).
     *
     * Staff Grub A-D BUKAN pekerja shift (tidak ikut pola rotasi), jadi tidak
     * masuk ke daftar ini walau laporannya tampil di tampilan Shift.
     *
     * @param  string|null $grup Group teknisi
     * @return bool               True bila pekerja shift
     */
    public static function grupShift(?string $grup): bool
    {
        return in_array((string) $grup, self::GRUP_SHIFT, true);
    }

    /**
     * Kelompok group yang tampil pada satu penyaringan tampilan Shift.
     *
     * @param  string|null $grub Kunci grub terpilih (Grub A-D), null = semua
     * @return array<int, string> Daftar kode group
     */
    public static function grupTampilanShift(?string $grub = null): array
    {
        if ($grub === null || $grub === '') {
            return array_merge(self::GRUP_SHIFT, self::GRUP_STAFF);
        }

        $kelompok = [$grub];

        foreach (self::GRUP_STAFF_INDUK as $staff => $induk) {
            if ($induk === $grub) {
                $kelompok[] = $staff;
            }
        }

        return $kelompok;
    }

    /**
     * Kelompok group yang laporannya boleh dikelola oleh group ini.
     *
     * Hanya group STAFF yang punya cakupan terbatas: Reguler Staff menaungi
     * Reguler + Reguler Staff, sedangkan Staff Grub A-D menaungi grub-nya
     * masing-masing. Group lain mengembalikan daftar kosong (bukan pengelola
     * terbatas, perilakunya diatur penjaga lain).
     *
     * @param  string|null $grup Kode group teknisi
     * @return array<int, string> Daftar kode group yang dinaungi
     */
    public static function grupYangDikelola(?string $grup): array
    {
        if ($grup === null || $grup === '') {
            return [];
        }

        if ($grup === 'reguler_staff') {
            return ['reguler', 'reguler_staff'];
        }

        if (array_key_exists($grup, self::GRUP_STAFF_INDUK)) {
            return [$grup, self::GRUP_STAFF_INDUK[$grup]];
        }

        return [];
    }

    public const SECTIONS = [
        'mekanik'   => 'Mekanik',
        'ei'        => 'EI',
        'it'        => 'IT',
        'sipil'     => 'Sipil',
        'fabrikasi' => 'Fabrikasi',
        'general'   => 'General',
        'lainnya'   => 'Lainnya',
        'cm_pm'     => 'CM/PM',
    ];

    // =========================================================
    // RELASI YANG SUDAH ADA
    // =========================================================

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Semua laporan milik teknisi ini (sebagai pemilik/penerima laporan).
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function assignedAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_technician')
            ->withPivot(['note', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Kelompok kolaborasi yang diikuti teknisi ini.
     *
     * Dipakai CollaborationService untuk menentukan rekan yang otomatis ikut
     * andil ketika teknisi ini membuat laporan.
     *
     * @return BelongsToMany<CollaborationGroup>
     */
    public function collaborationGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            CollaborationGroup::class,
            'collaboration_group_members',
            'technician_id',
            'collaboration_group_id'
        )->withPivot(['sort_order'])->withTimestamps();
    }

    // =========================================================
    // RELASI BARU — KOLABORASI
    // =========================================================

    /**
     * Laporan-laporan yang dibuat oleh teknisi ini untuk rekan kolaborator.
     * Ini adalah laporan di mana teknisi ini bertindak sebagai pengirim (creator),
     * bukan sebagai pemilik laporan (technician_id).
     *
     * @return HasMany<Report>
     */
    public function createdReports(): HasMany
    {
        return $this->hasMany(Report::class, 'creator_id');
    }

    /**
     * Jadwal shift teknisi ini (hasil import jadwal shift).
     *
     * @return HasMany<TechnicianShift>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TechnicianShift::class);
    }

    /**
     * Ruang panel yang menjadi tanggung jawab teknisi ini (PIC panel).
     *
     * Dipakai pengiriman pengingat harian lewat Telegram: tiap penanggung
     * jawab menerima ringkasan kondisi ruangannya pada awal jam masuk kerja.
     *
     * @return BelongsToMany<PanelRuangan>
     */
    public function panelYangDitangani(): BelongsToMany
    {
        return $this->belongsToMany(PanelRuangan::class, 'panel_penanggung_jawab')
            ->withTimestamps();
    }

    // =========================================================
    // SCOPES
    // =========================================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
