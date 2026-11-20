<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu transaksi peminjaman unit alat.
 *
 * Peminjam teknisi memakai relasi technician; peminjam non-teknisi menyimpan
 * nama bebas dan departemen pilihan pada snapshot baris ini.
 */
class ToolLoan extends Model
{
    public const BORROWER_TEKNISI     = 'teknisi';
    public const BORROWER_NON_TEKNISI = 'non_teknisi';

    public const BORROWER = [
        self::BORROWER_TEKNISI     => 'Teknisi',
        self::BORROWER_NON_TEKNISI => 'Non-Teknisi',
    ];

    public const HANDLED_KEEPER = 'keeper';
    public const HANDLED_SELF   = 'self';
    public const HANDLED_ADMIN  = 'admin';

    public const HANDLED = [
        self::HANDLED_KEEPER => 'Tool Keeper',
        self::HANDLED_SELF   => 'Mandiri (Telegram)',
        self::HANDLED_ADMIN  => 'Administrator',
    ];

    protected $fillable = [
        'kode',
        'tool_id',
        'tool_name',
        'tool_code',
        'borrower_type',
        'technician_id',
        'borrower_name',
        'borrower_department_id',
        'borrower_department_nama',
        'borrowed_at',
        'returned_at',
        'handled_by_type',
        'handled_by_technician_id',
        'handled_by_user_id',
        'returned_by_type',
        'returned_by_technician_id',
        'returned_by_user_id',
        'catatan',
    ];

    /**
     * Tipe data kolom pinjaman.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'borrowed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * Unit alat yang dipinjam (bisa kosong bila unit sudah dihapus).
     */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /**
     * Teknisi peminjam (bila borrower_type = teknisi).
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Departemen peminjam non-teknisi.
     */
    public function borrowerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'borrower_department_id');
    }

    /**
     * Teknisi yang menyerahkan alat (keeper).
     */
    public function handlerTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'handled_by_technician_id');
    }

    /**
     * User yang menyerahkan alat (administrator web).
     */
    public function handlerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /**
     * Teknisi yang menerima kembali alat.
     */
    public function returnerTechnician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'returned_by_technician_id');
    }

    /**
     * User yang menerima kembali alat.
     */
    public function returnerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }

    /**
     * Pinjaman yang belum dikembalikan.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('returned_at');
    }

    /**
     * Pinjaman yang sudah dikembalikan.
     */
    public function scopeSelesai(Builder $query): Builder
    {
        return $query->whereNotNull('returned_at');
    }

    /**
     * Apakah pinjaman masih berjalan.
     */
    public function masihDipinjam(): bool
    {
        return $this->returned_at === null;
    }

    /**
     * Label status pinjaman.
     */
    public function statusLabel(): string
    {
        return $this->masihDipinjam() ? 'Dipinjam' : 'Dikembalikan';
    }

    /**
     * Nama peminjam untuk tampilan.
     */
    public function peminjamLabel(): string
    {
        if ($this->borrower_type === self::BORROWER_TEKNISI) {
            return $this->technician?->name ?? $this->borrower_name;
        }

        $departemen = $this->borrower_department_nama ?? $this->borrowerDepartment?->name;

        return $departemen ? $this->borrower_name . ' (' . $departemen . ')' : $this->borrower_name;
    }

    /**
     * Lama peminjaman dalam menit.
     *
     * Pinjaman aktif dihitung sampai waktu sekarang. Selisih negatif (mis.
     * jam server mundur) dianggap nol.
     */
    public function durasiMenit(): int
    {
        if ($this->borrowed_at === null) {
            return 0;
        }

        $selesai = $this->returned_at ?? now();

        return max(0, (int) floor($this->borrowed_at->diffInMinutes($selesai, true)));
    }

    /**
     * Lama peminjaman dalam teks ringkas, mis. "2 hari 3 jam".
     */
    public function durasiTeks(): string
    {
        $menit = $this->durasiMenit();

        if ($menit < 1) {
            $teks = 'kurang dari 1 menit';
        } elseif ($menit < 60) {
            $teks = $menit . ' menit';
        } else {
            $hari = intdiv($menit, 1440);
            $sisa = $menit % 1440;
            $jam  = intdiv($sisa, 60);
            $sisaMenit = $sisa % 60;
            $bagian = [];

            if ($hari > 0) {
                $bagian[] = $hari . ' hari';
            }

            if ($jam > 0) {
                $bagian[] = $jam . ' jam';
            }

            if ($sisaMenit > 0 || $bagian === []) {
                $bagian[] = $sisaMenit . ' menit';
            }

            $teks = implode(' ', $bagian);
        }

        return $this->masihDipinjam() ? $teks . ' (masih dipinjam)' : $teks;
    }

    /**
     * Buat kode pinjaman harian: PJM-YYYYMMDD-XXXX.
     */
    public static function generateKode(): string
    {
        $prefix       = 'PJM-' . now()->format('Ymd') . '-';
        $urutTerakhir = 0;

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
