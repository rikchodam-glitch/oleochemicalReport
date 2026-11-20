<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token form Management Tools sekali pakai atau multi-pakai.
 *
 * Token asli tidak pernah disimpan; hanya hash SHA-256-nya.
 */
class ToolToken extends Model
{
    public const PURPOSE_KEEPER_PINJAM     = 'keeper_borrow';
    public const PURPOSE_KEEPER_KEMBALI    = 'keeper_return';
    public const PURPOSE_TEKNISI_PINJAM    = 'technician_borrow';
    public const PURPOSE_TEKNISI_KEMBALI   = 'technician_return';

    /**
     * Purpose yang hanya boleh dipakai satu transaksi.
     */
    public const SEKALI_PAKAI = [
        self::PURPOSE_TEKNISI_PINJAM,
        self::PURPOSE_TEKNISI_KEMBALI,
    ];

    protected $fillable = [
        'purpose',
        'technician_id',
        'token_hash',
        'prefill_tool_id',
        'expires_at',
        'opened_at',
        'used_at',
    ];

    /**
     * Tipe data kolom token.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'opened_at'  => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    /**
     * Teknisi pemilik token.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Alat yang dipilih lebih dulu lewat /pakai (bila ada).
     */
    public function prefillTool(): BelongsTo
    {
        return $this->belongsTo(Tool::class, 'prefill_tool_id');
    }

    /**
     * Apakah token masih berlaku.
     */
    public function masihBerlaku(): bool
    {
        if ($this->expires_at === null || $this->expires_at->isPast()) {
            return false;
        }

        if (in_array($this->purpose, self::SEKALI_PAKAI, true)) {
            return $this->used_at === null;
        }

        return true;
    }

    /**
     * Apakah token hanya boleh dipakai sekali.
     */
    public function sekaliPakai(): bool
    {
        return in_array($this->purpose, self::SEKALI_PAKAI, true);
    }

    /**
     * Apakah token milik keeper (pinjam atau kembali).
     */
    public function milikKeeper(): bool
    {
        return in_array($this->purpose, [
            self::PURPOSE_KEEPER_PINJAM,
            self::PURPOSE_KEEPER_KEMBALI,
        ], true);
    }
}
