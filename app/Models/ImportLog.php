<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    protected $table = 'import_logs';

    protected $fillable = [
        'nama_file',
        'tipe_import',
        'total_baris',
        'total_dibaca',
        'insert_baru',
        'update_existing',
        'gagal',
        'unregistered',
        'total_skipped_duplikat',
        'total_skipped_kosong',
        'detail_unregistered',
        'detail_error',
        'detail_skipped',
        'created_reading_ids',
        'created_equipment_ids',
        'created_finding_ids',
        'status',
        'uploaded_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_baris'            => 'integer',
            'total_dibaca'           => 'integer',
            'insert_baru'            => 'integer',
            'update_existing'        => 'integer',
            'gagal'                  => 'integer',
            'unregistered'           => 'integer',
            'total_skipped_duplikat'  => 'integer',
            'total_skipped_kosong'    => 'integer',
            'detail_unregistered'    => 'json',
            'detail_error'           => 'json',
            'detail_skipped'         => 'json',
            'created_reading_ids'    => 'json',
            'created_equipment_ids'  => 'json',
            'created_finding_ids'    => 'json',
            'completed_at'           => 'datetime',
        ];
    }

    /**
     * Relasi ke user yang mengupload.
     *
     * @return BelongsTo<User, ImportLog>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
