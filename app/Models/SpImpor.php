<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat satu kali import berkas Spare Part.
 *
 * Berisi berkas apa saja yang dipakai, jumlah barisnya, dan analisa hasilnya
 * (material baru/diubah, perubahan status, material di luar daftar pantau).
 * Analisa inilah yang ditampilkan pada halaman Riwayat Import.
 */
class SpImpor extends Model
{
    protected $table = 'sp_impor';

    /**
     * Nilai status saat import sedang berjalan.
     */
    public const STATUS_PROSES = 'proses';

    /**
     * Nilai status saat import selesai tanpa galat.
     */
    public const STATUS_SELESAI = 'selesai';

    /**
     * Nilai status saat import dibatalkan karena galat.
     */
    public const STATUS_GAGAL = 'gagal';

    protected $fillable = [
        'label',
        'berkas',
        'analisa',
        'status',
        'jumlah_pantau',
        'jumlah_stok',
        'jumlah_pr',
        'jumlah_po',
        'jumlah_keadaan',
        'pesan',
        'oleh',
        'selesai_at',
    ];

    /**
     * Tipe data kolom riwayat import.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'berkas'         => 'array',
            'analisa'        => 'array',
            'jumlah_pantau'  => 'integer',
            'jumlah_stok'    => 'integer',
            'jumlah_pr'      => 'integer',
            'jumlah_po'      => 'integer',
            'jumlah_keadaan' => 'integer',
            'selesai_at'     => 'datetime',
        ];
    }

    /**
     * Pengguna yang menjalankan import.
     *
     * @return BelongsTo<User, SpImpor>
     */
    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh');
    }

    /**
     * Label status import dalam Bahasa Indonesia.
     *
     * @return string  Label status (Diproses, Selesai, atau Gagal)
     */
    public function getLabelStatusAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SELESAI => 'Selesai',
            self::STATUS_GAGAL   => 'Gagal',
            default              => 'Diproses',
        };
    }
}
