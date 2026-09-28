<?php

namespace App\Models;

use App\Support\AksesModul;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Route;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Role yang selalu mendapat seluruh akses halaman dan tidak bisa dibatasi
     * per Bagian.
     */
    public const ROLE_AKSES_PENUH = ['admin', 'supervisor'];

    /**
     * Role yang boleh mengelola akun user dan hak akses Bagian.
     */
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'technician_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    /**
     * Data teknisi yang terhubung dengan akun ini (bila akunnya milik teknisi).
     *
     * @return BelongsTo<Technician, User>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Apakah akun ini selalu mendapat seluruh akses halaman tanpa dibatasi
     * Bagian (role admin atau supervisor).
     *
     * @return bool
     */
    public function aksesPenuh(): bool
    {
        return in_array((string) $this->role, self::ROLE_AKSES_PENUH, true);
    }

    /**
     * Apakah akun ini boleh membuka halaman manajemen user dan hak akses.
     *
     * @return bool
     */
    public function bolehKelolaUser(): bool
    {
        return (string) $this->role === self::ROLE_ADMIN;
    }

    /**
     * Kode Bagian akun ini (kolom role bila bukan akun akses penuh).
     *
     * @return string|null Null bila akun ini akses penuh
     */
    public function kodeBagian(): ?string
    {
        return $this->aksesPenuh() ? null : (string) $this->role;
    }

    /**
     * Label Bagian akun ini untuk ditampilkan di layar.
     *
     * @return string
     */
    public function labelBagian(): string
    {
        if ($this->bolehKelolaUser()) {
            return 'Administrator';
        }

        if ((string) $this->role === 'supervisor') {
            return 'Supervisor';
        }

        $kode = (string) $this->kodeBagian();

        return Technician::SECTIONS[$kode] ?? ucfirst($kode);
    }

    /**
     * Daftar kunci modul halaman yang boleh diakses akun ini.
     *
     * @return array<int, string>
     */
    public function daftarAkses(): array
    {
        if ($this->aksesPenuh()) {
            return array_keys(AksesModul::semua());
        }

        return BagianAccess::untukBagian($this->kodeBagian())->daftarModul();
    }

    /**
     * Apakah akun ini boleh mengakses modul halaman tertentu.
     *
     * @param  string $kunci Kunci modul pada config/akses.php
     * @return bool
     */
    public function punyaAkses(string $kunci): bool
    {
        if ($this->aksesPenuh()) {
            return true;
        }

        if (AksesModul::khususAdmin($kunci)) {
            return false;
        }

        return in_array($kunci, $this->daftarAkses(), true);
    }

    /**
     * URL menu sidebar yang harus disembunyikan dari akun ini.
     *
     * Dipakai layout untuk menyembunyikan menu yang tidak boleh dibuka.
     * Penegakan izin yang sebenarnya dilakukan AksesMiddleware di sisi server,
     * jadi menyembunyikan menu di sini hanya untuk kerapian tampilan.
     *
     * @return array<int, string>
     */
    public function menuTerlarangUrls(): array
    {
        if ($this->aksesPenuh()) {
            return [];
        }

        $urls = [];

        foreach (array_keys(AksesModul::semua()) as $kunci) {
            if ($this->punyaAkses($kunci)) {
                continue;
            }

            $namaLink = AksesModul::link($kunci);

            if ($namaLink === null || ! Route::has($namaLink)) {
                continue;
            }

            $urls[] = route($namaLink);
        }

        return array_values(array_unique($urls));
    }
}
