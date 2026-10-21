<?php

namespace App\Models;

use App\Support\AksesModul;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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

    /**
     * Role khusus untuk menginput cuti teknisi (bukan administrator penuh).
     */
    public const ROLE_ADMIN_REGULER = 'admin_reguler';

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

    /**
     * Hasil daftarAkses yang sudah dihitung untuk instance akun ini.
     *
     * Dipakai supaya pemeriksaan hak akses per modul (mis. saat menyembunyikan
     * menu sidebar yang memeriksa 15 modul) tidak menanyakan database berkali-
     * kali pada satu permintaan. Kunci disimpan beserta Bagian + Group-nya
     * sehingga tetap benar bila role akun diubah pada instance yang sama.
     *
     * @var array{kunci: string, modul: array<int, string>}|null
     */
    private ?array $cacheDaftarAkses = null;

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
     * Apakah akun ini boleh mencatat/mengubah cuti teknisi.
     *
     * @return bool
     */
    public function bolehKelolaCuti(): bool
    {
        return in_array((string) $this->role, [self::ROLE_ADMIN, self::ROLE_ADMIN_REGULER], true);
    }

    /**
     * Apakah akun ini boleh mengubah data (tambah/ubah/hapus/import).
     *
     * Akun selain administrator hanya bisa membaca; daftar role penulis ada di
     * config('akses.role_penulis').
     *
     * @return bool
     */
    public function bolehMenulis(): bool
    {
        return in_array((string) $this->role, (array) config('akses.role_penulis', ['admin']), true);
    }

    /**
     * Daftar kemampuan tulis sebagian akun ini (lihat config('akses.tulis_grup')).
     *
     * Diambil dari group teknisi yang terhubung. Administrator tidak memakai
     * daftar ini (selalu boleh menulis), dan akun tanpa group mengembalikan
     * daftar kosong (hanya baca).
     *
     * @return array<int, string>
     */
    public function kemampuanTulis(): array
    {
        if ($this->bolehMenulis()) {
            return [];
        }

        $grup = $this->grupBagian();

        if ($grup === null) {
            return [];
        }

        return array_values((array) config('akses.tulis_grup.' . $grup, []));
    }

    /**
     * Apakah akun ini punya kemampuan tulis tertentu.
     *
     * @param  string $kemampuan Kunci kemampuan (mis. 'laporan')
     * @return bool
     */
    public function bolehMenulisKemampuan(string $kemampuan): bool
    {
        return in_array($kemampuan, $this->kemampuanTulis(), true);
    }

    /**
     * Apakah akun ini boleh memakai metode tulis pada route tertentu.
     *
     * Administrator selalu boleh. Selain itu hanya route yang termasuk
     * kemampuan tulis sebagian group-nya (mis. 'reports.update') yang boleh.
     * Dipakai HanyaBacaMiddleware sebagai penegak utama.
     *
     * @param  string|null $namaRoute Nama route yang diminta
     * @return bool
     */
    public function bolehMenulisRoute(?string $namaRoute): bool
    {
        if ($this->bolehMenulis()) {
            return true;
        }

        if ($namaRoute === null || $namaRoute === '') {
            return false;
        }

        foreach ($this->kemampuanTulis() as $kemampuan) {
            foreach ((array) config('akses.tulis_kemampuan.' . $kemampuan, []) as $pola) {
                if (Str::is((string) $pola, $namaRoute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Pola PATH (regex) yang boleh dikirim akun ini pada tampilan.
     *
     * Dipakai partial mode-hanya-baca supaya form yang boleh (mis. form edit
     * laporan staf) tetap tampil, sedangkan form lain disembunyikan.
     *
     * @return array<int, string>
     */
    public function polaTulisTampilan(): array
    {
        if ($this->bolehMenulis()) {
            return [];
        }

        $pola = [];

        foreach ($this->kemampuanTulis() as $kemampuan) {
            $pola = array_merge($pola, (array) config('akses.tulis_kemampuan_tampilan.' . $kemampuan, []));
        }

        return array_values($pola);
    }

    /**
     * Apakah akun ini boleh mengelola (ubah/hapus/status/foto/kolaborator/
     * alat tambahan) sebuah laporan INDUK.
     *
     * Semua akun selain group STAFF (reguler_staff / Staff Grub A-D) mengikuti
     * perilaku lama: penegakannya ada di HanyaBacaMiddleware dan penjaga
     * laporan induk. Group STAFF dibatasi pada grub yang dinaunginya, jadi
     * Staff Grub A tidak boleh mengubah laporan grub lain.
     *
     * @param  Report|null $laporan Laporan INDUK yang sedang diproses
     * @return bool
     */
    public function bolehKelolaLaporan(?Report $laporan): bool
    {
        if ($this->bolehMenulis()) {
            return true;
        }

        $grup = $this->grupBagian();

        // Bukan group STAFF: perilaku lama (bukan pengelola terbatas).
        if ($grup === null || Technician::grupYangDikelola($grup) === []) {
            return true;
        }

        if ($laporan === null || ! $this->bolehMenulisKemampuan('laporan')) {
            return false;
        }

        $grupLaporan = $laporan->technician?->group;

        return $grupLaporan !== null
            && in_array((string) $grupLaporan, Technician::grupYangDikelola($grup), true);
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
     * Kode Group akun ini, diambil dari data teknisi yang terhubung.
     *
     * Group dipakai untuk membedakan hak akses di dalam satu Bagian, mis.
     * "mekanik reguler" bisa berbeda dari "mekanik reguler staff". Akun yang
     * tidak terhubung teknisi (atau teknisi tanpa Group) tidak punya Group
     * sendiri sehingga memakai pengaturan bawaan Bagian.
     *
     * @return string|null Kode Group (lihat Technician::GROUPS), null bila tidak ada
     */
    public function grupBagian(): ?string
    {
        if ($this->aksesPenuh()) {
            return null;
        }

        $this->loadMissing('technician');

        $grup = $this->technician?->group;

        return ($grup === null || $grup === '') ? null : (string) $grup;
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

        if ((string) $this->role === self::ROLE_ADMIN_REGULER) {
            return 'Admin Reguler';
        }

        $kode  = (string) $this->kodeBagian();
        $label = Technician::SECTIONS[$kode] ?? ucfirst($kode);
        $grup  = $this->grupBagian();

        return $grup === null ? $label : $label . ' - ' . (Technician::GROUPS[$grup] ?? ucfirst($grup));
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

        // Admin Reguler adalah role khusus untuk pencatatan cuti: boleh
        // melihat dashboard dan halaman teknisi (read-only), serta mengelola
        // cuti. Tidak mendapat modul lain dan bukan administrator penuh.
        if ((string) $this->role === self::ROLE_ADMIN_REGULER) {
            return ['dashboard', 'technicians', 'cuti'];
        }

        $bagian = $this->kodeBagian();
        $grup   = $this->grupBagian();
        $kunci  = ((string) $bagian) . '|' . ((string) $grup);

        if ($this->cacheDaftarAkses !== null && $this->cacheDaftarAkses['kunci'] === $kunci) {
            return $this->cacheDaftarAkses['modul'];
        }

        $modul = BagianAccess::untukBagian($bagian, $grup)->daftarModul();

        $this->cacheDaftarAkses = ['kunci' => $kunci, 'modul' => $modul];

        return $modul;
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
