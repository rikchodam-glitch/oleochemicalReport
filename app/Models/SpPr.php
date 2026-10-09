<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris data mentah permintaan pembelian (EXPORT PR) satu baris dokumen.
 */
class SpPr extends Model
{
    protected $table = 'sp_pr';

    protected $fillable = [
        'batch_id',
        'kode_material',
        'no_pr',
        'processing_status',
        'deletion_indicator',
        'batal',
        'qty_requested',
        'tanggal_kirim',
        'no_po',
        'requisitioner',
        'purchasing_group',
    ];

    /**
     * Tipe data kolom PR.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batal'         => 'boolean',
            'qty_requested' => 'float',
            'tanggal_kirim' => 'date',
        ];
    }

    /**
     * Batch import pemilik baris ini.
     *
     * @return BelongsTo<SpBatch, SpPr>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SpBatch::class, 'batch_id');
    }

    /**
     * Label status PR dalam Bahasa Indonesia.
     *
     * @return string  Label status (Batal, Sudah PO, Terbuka, atau Tidak diketahui)
     */
    public function getLabelStatusAttribute(): string
    {
        if ($this->batal) {
            return 'Batal';
        }

        if (filled($this->no_po)) {
            return 'Sudah PO';
        }

        return match ($this->processing_status) {
            '1'  => 'Terbuka',
            '2'  => 'Terproses',
            '3'  => 'Disetujui',
            '4'  => 'Sebagian',
            '5'  => 'Selesai',
            default => 'Tidak diketahui',
        };
    }
}
