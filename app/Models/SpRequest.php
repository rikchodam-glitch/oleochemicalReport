<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Satu request spare part yang diajukan teknisi lewat bot Telegram.
 *
 * Alur status:
 * menunggu -> disetujui -> kejar_po -> kejar_delivery -> selesai.
 * Request dapat ditolak saat masih menunggu.
 */
class SpRequest extends Model
{
    protected $table = 'sp_requests';

    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_KEJAR_PO = 'kejar_po';
    public const STATUS_KEJAR_DELIVERY = 'kejar_delivery';
    public const STATUS_SELESAI = 'selesai';

    /**
     * Label status untuk tampilan.
     */
    public const STATUS = [
        self::STATUS_MENUNGGU       => 'Menunggu',
        self::STATUS_DISETUJUI      => 'Disetujui',
        self::STATUS_DITOLAK        => 'Ditolak',
        self::STATUS_KEJAR_PO       => 'Kejar PO',
        self::STATUS_KEJAR_DELIVERY => 'Kejar Delivery',
        self::STATUS_SELESAI        => 'Selesai',
    ];

    /**
     * Status request yang belum punya nomor PR sehingga boleh dihapus.
     */
    public const STATUS_TANPA_PR = [
        self::STATUS_MENUNGGU,
        self::STATUS_DISETUJUI,
        self::STATUS_DITOLAK,
    ];

    protected $fillable = [
        'kode',
        'technician_id',
        'keterangan',
        'deskripsi_ukuran',
        'jumlah',
        'satuan',
        'foto_path',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'alasan_tolak',
        'pr_no',
        'pr_by',
        'pr_at',
        'po_no',
        'po_by',
        'po_at',
        'received_by',
        'received_at',
        'catatan',
    ];

    /**
     * Tipe data kolom request.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jumlah'       => 'float',
            'approved_at'  => 'datetime',
            'rejected_at'  => 'datetime',
            'pr_at'        => 'datetime',
            'po_at'        => 'datetime',
            'received_at'  => 'datetime',
        ];
    }

    /**
     * Hapus foto request bersama barisnya.
     *
     * Foto request tidak dipakai baris lain. Kegagalan hapus berkas hanya
     * dicatat supaya penghapusan data tetap berhasil.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::deleting(function (SpRequest $request): void {
            if ($request->foto_path === null || $request->foto_path === '') {
                return;
            }

            try {
                Storage::disk(config('telegram.photo_disk', 'telegram_photos'))->delete($request->foto_path);
            } catch (\Throwable $galat) {
                Log::warning('SpRequest: gagal menghapus foto request', [
                    'id'    => $request->id,
                    'foto'  => $request->foto_path,
                    'error' => $galat->getMessage(),
                ]);
            }
        });
    }

    /**
     * Apakah request boleh dihapus.
     *
     * Hanya request yang belum punya PR (menunggu, disetujui, atau ditolak)
     * yang boleh dihapus. Request yang sudah masuk Kejar PO/Delivery/Selesai
     * harus dipertahankan sebagai riwayat.
     *
     * @return bool
     */
    public function bisaDihapus(): bool
    {
        return in_array($this->status, self::STATUS_TANPA_PR, true);
    }

    /**
     * Teknisi yang mengajukan request.
     *
     * @return BelongsTo<Technician, SpRequest>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Pengguna yang menyetujui request.
     *
     * @return BelongsTo<User, SpRequest>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Pengguna yang menolak request.
     *
     * @return BelongsTo<User, SpRequest>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Pengguna yang mengisi nomor PR.
     *
     * @return BelongsTo<User, SpRequest>
     */
    public function prBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pr_by');
    }

    /**
     * Pengguna yang mengisi nomor PO.
     *
     * @return BelongsTo<User, SpRequest>
     */
    public function poBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'po_by');
    }

    /**
     * Pengguna yang menandai barang sudah diterima.
     *
     * @return BelongsTo<User, SpRequest>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Label status request.
     *
     * @return string
     */
    public function labelStatus(): string
    {
        return self::STATUS[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * URL foto request bila ada.
     *
     * @return string|null
     */
    public function getFotoUrlAttribute(): ?string
    {
        if ($this->foto_path === null || $this->foto_path === '') {
            return null;
        }

        return Storage::disk(config('telegram.photo_disk', 'telegram_photos'))->url($this->foto_path);
    }

    /**
     * Filter berdasarkan satu status atau beberapa status.
     *
     * @param  Builder          $query
     * @param  string|array<int, string> $status
     * @return Builder
     */
    public function scopeStatus(Builder $query, string|array $status): Builder
    {
        return is_array($status)
            ? $query->whereIn('status', $status)
            : $query->where('status', $status);
    }

    /**
     * Filter pencarian kode, keterangan, nomor PR/PO, atau identitas teknisi.
     *
     * @param  Builder     $query
     * @param  string|null $cari
     * @return Builder
     */
    public function scopeCari(Builder $query, ?string $cari): Builder
    {
        $kata = trim((string) $cari);

        if ($kata === '') {
            return $query;
        }

        $pola = '%' . $kata . '%';

        return $query->where(function (Builder $sub) use ($pola): void {
            $sub->where('kode', 'like', $pola)
                ->orWhere('keterangan', 'like', $pola)
                ->orWhere('deskripsi_ukuran', 'like', $pola)
                ->orWhere('pr_no', 'like', $pola)
                ->orWhere('po_no', 'like', $pola)
                ->orWhereHas('technician', function (Builder $teknisi) use ($pola): void {
                    $teknisi->where('name', 'like', $pola)
                        ->orWhere('nik', 'like', $pola);
                });
        });
    }

    /**
     * Membuat kode request unik dengan format REQ-YYYYMMDD-XXXX.
     *
     * Nomor urut dihitung per hari dan hanya bagian berekor angka yang dipakai,
     * sama seperti pola generateReportCode() pada laporan.
     *
     * @return string
     */
    public static function generateKode(): string
    {
        $prefix        = 'REQ-' . now()->format('Ymd') . '-';
        $urutTerakhir  = 0;

        foreach (self::query()->where('kode', 'like', $prefix . '%')->pluck('kode') as $kodeLama) {
            $ekor = substr((string) $kodeLama, -4);

            if (ctype_digit($ekor)) {
                $urutTerakhir = max($urutTerakhir, (int) $ekor);
            }
        }

        $urut = $urutTerakhir + 1;

        do {
            $kode = $prefix . str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
            $urut++;
        } while (self::query()->where('kode', $kode)->exists());

        return $kode;
    }
}
