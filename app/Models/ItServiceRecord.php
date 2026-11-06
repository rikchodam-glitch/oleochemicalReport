<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kasus Service IT untuk satu perangkat IT.
 *
 * Kasus dapat berasal dari laporan (dibuka laporan 'continue' dan ditutup
 * laporan 'completed') atau dibuat manual dari halaman Service IT. Catatan
 * bebas pada setiap baris boleh kosong.
 */
class ItServiceRecord extends Model
{
    public const STATUS_PROSES = 'proses';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_RUSAK = 'rusak';

    public const SOURCE_REPORT = 'report';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'it_asset_id',
        'report_id',
        'closed_by_report_id',
        'source',
        'entry_date',
        'finish_date',
        'status',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'  => 'date',
            'finish_date' => 'date',
        ];
    }

    /**
     * Perangkat IT yang menjalani kasus service ini.
     *
     * @return BelongsTo<ItAsset, ItServiceRecord>
     */
    public function itAsset(): BelongsTo
    {
        return $this->belongsTo(ItAsset::class, 'it_asset_id');
    }

    /**
     * Laporan continue yang membuka kasus ini (null untuk kasus manual).
     *
     * @return BelongsTo<Report, ItServiceRecord>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    /**
     * Laporan completed yang menutup kasus ini.
     *
     * @return BelongsTo<Report, ItServiceRecord>
     */
    public function closedByReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'closed_by_report_id');
    }

    /**
     * Akun yang membuat kasus manual.
     *
     * @return BelongsTo<User, ItServiceRecord>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope kasus yang belum punya tanggal selesai.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ItServiceRecord>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ItServiceRecord>
     */
    public function scopeTerbuka($query)
    {
        return $query->whereNull('finish_date');
    }

    /**
     * Scope kasus yang ditampilkan pada filter "aktif" (proses dan rusak).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ItServiceRecord>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ItServiceRecord>
     */
    public function scopeAktif($query)
    {
        return $query->whereIn('status', [self::STATUS_PROSES, self::STATUS_RUSAK]);
    }

    /**
     * Label status untuk tampilan.
     *
     * @return string
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SELESAI => 'Selesai',
            self::STATUS_RUSAK   => 'Rusak',
            default              => 'Proses',
        };
    }

    /**
     * Label sumber kasus untuk tampilan.
     *
     * @return string
     */
    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_REPORT ? 'Laporan' : 'Manual';
    }
}
