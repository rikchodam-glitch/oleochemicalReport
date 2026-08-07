<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    use HasUuids;

    protected $table = 'work_orders';

    protected $fillable = [
        'equipment_tag',
        'tanggal_kejadian',
        'tanggal_eksekusi_selesai',
        'jenis_pekerjaan',
        'linked_finding_id',
        'deskripsi',
        'root_cause',
        'sesuai_rencana',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_kejadian'          => 'date:Y-m-d',
            'tanggal_eksekusi_selesai'  => 'date:Y-m-d',
        ];
    }

    /**
     * Relasi ke equipment CM.
     */
    public function cmEquipment(): BelongsTo
    {
        return $this->belongsTo(CmEquipment::class, 'equipment_tag', 'equipment_tag');
    }

    /**
     * Relasi ke finding yang menjadi asal-usul.
     */
    public function linkedFinding(): BelongsTo
    {
        return $this->belongsTo(CmFinding::class, 'linked_finding_id');
    }

    /**
     * Relasi ke user pembuat.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: filter berdasarkan jenis pekerjaan.
     */
    public function scopeJenis($query, $jenis)
    {
        return $query->where('jenis_pekerjaan', $jenis);
    }

    /**
     * Scope: filter berdasarkan rentang tanggal kejadian.
     */
    public function scopeTanggal($query, $dari, $sampai)
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
}
