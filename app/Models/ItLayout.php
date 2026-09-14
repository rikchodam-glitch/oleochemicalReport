<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Storage;

class ItLayout extends Model
{
    /**
     * Kolom yang boleh diisi massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'label',
        'description',
        'file_path',
        'file_type',
        'page',
        'width',
        'height',
        'sort_order',
    ];

    /**
     * Casting tipe data.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width'  => 'float',
            'height' => 'float',
            'page'   => 'integer',
        ];
    }

    /**
     * Posisi perangkat IT pada layout ini.
     *
     * @return HasMany<ItLayoutItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ItLayoutItem::class, 'layout_id');
    }

    /**
     * Perangkat IT yang ditempatkan pada layout ini (lewat item).
     *
     * @return HasManyThrough<ItAsset>
     */
    public function assets(): HasManyThrough
    {
        return $this->hasManyThrough(ItAsset::class, ItLayoutItem::class, 'layout_id', 'id', 'id', 'it_asset_id');
    }

    /**
     * Jalur kabel yang digambar pada layout ini.
     *
     * @return HasMany<NetworkLink>
     */
    public function links(): HasMany
    {
        return $this->hasMany(NetworkLink::class, 'layout_id');
    }

    /**
     * URL file denah dalam bentuk path relatif (tanpa skema + host), supaya
     * fetch oleh PDF.js atau <img> di browser selalu same-origin dan tidak
     * diblokir CORS saat aplikasi diakses lewat host selain APP_URL.
     *
     * @return string|null
     */
    public function fileUrlRelative(): ?string
    {
        $full = Storage::disk('public')->url($this->file_path);

        return parse_url($full, PHP_URL_PATH);
    }

    /**
     * Path relatif gambar hasil pra-render PDF di disk 'public'.
     *
     * @return string
     */
    public function previewRelativePath(): string
    {
        return config('denah.preview_folder', 'layout-previews') . '/' . $this->id . '.png';
    }

    /**
     * Apakah gambar hasil pra-render sudah tersedia (khusus denah PDF).
     *
     * @return bool
     */
    public function hasPreview(): bool
    {
        if ($this->file_type !== 'pdf') {
            return false;
        }

        return Storage::disk(config('denah.disk', 'public'))->exists($this->previewRelativePath());
    }

    /**
     * Tipe file untuk ditampilkan di halaman denah: bila PDF sudah
     * dipra-render, tampilkan sebagai gambar supaya tidak perlu render PDF.js.
     *
     * @return string
     */
    public function displayFileType(): string
    {
        return $this->hasPreview() ? 'image' : $this->file_type;
    }
}
