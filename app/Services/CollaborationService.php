<?php

namespace App\Services;

use App\Models\CollaborationGroup;
use App\Models\Technician;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * CollaborationService
 *
 * Mengatur kolaborasi otomatis berbasis kelompok: ketika salah satu anggota
 * kelompok membuat laporan, anggota lain pada kelompok yang sama otomatis ikut
 * andil (dibuatkan salinan laporan kolaborasi seperti mekanisme manual di
 * wizard Telegram, lihat WizardReportSaverTrait::saveReport()).
 *
 * Kelompok dikelola di tabel collaboration_groups + collaboration_group_members
 * sehingga susunan anggota bisa disesuaikan admin tanpa mengubah data teknisi.
 * Bawaan sistem: kelompok shift Grub A sampai Grub D (lihat perintah
 * `collab:sync-groups`); kelompok Reguler tidak dibuat otomatis.
 */
class CollaborationService
{
    /**
     * Semua kelompok kolaborasi beserta anggotanya, diurutkan untuk tampilan.
     *
     * @return Collection<int, CollaborationGroup>
     */
    public function groups(): Collection
    {
        return CollaborationGroup::with(['members' => fn ($query) => $query->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Kelompok yang diikuti teknisi ini dan siap dipakai untuk otomasi.
     *
     * @param  Technician|null  $teknisi
     * @return Collection<int, CollaborationGroup>
     */
    public function groupsUntuk(?Technician $teknisi): Collection
    {
        if ($teknisi === null) {
            return collect();
        }

        return $teknisi->collaborationGroups()
            ->where('collaboration_groups.is_active', true)
            ->where('collaboration_groups.auto_collaborate', true)
            ->orderBy('collaboration_groups.sort_order')
            ->get();
    }

    /**
     * Rekan yang otomatis ikut andil ketika $pelapor membuat laporan.
     *
     * Anggota dari SELURUH kelompok aktif + otomatis yang diikuti pelapor
     * digabung, tanpa pelapor sendiri, dan unik per teknisi. Tidak ada batas
     * jumlah kolaborator (batas 5 dihapus atas permintaan user 15/10/2026).
     *
     * @param  Technician|null  $pelapor
     * @return Collection<int, Technician>
     */
    public function autoCollaboratorsFor(?Technician $pelapor): Collection
    {
        if ($pelapor === null) {
            return collect();
        }

        $hasil = collect();

        foreach ($this->groupsUntuk($pelapor) as $group) {
            foreach ($group->members as $anggota) {
                if ((int) $anggota->id === (int) $pelapor->id) {
                    continue;
                }

                if ($hasil->contains('id', $anggota->id)) {
                    continue;
                }

                $hasil->push($anggota);
            }
        }

        return $hasil->values();
    }

    /**
     * Gabungkan kolaborator manual (NIK/nama dari wizard) dengan rekan otomatis.
     *
     * Rekan otomatis dari kelompok HANYA ditambahkan bila NIK-nya tidak ada di
     * $dikecualikan. Daftar pengecualian dipakai wizard untuk menghormati nama
     * yang sengaja dihapus teknisi lewat menu "Sesuaikan" (atau tombol "Lewati")
     * pada Step 8a. Tanpa pengecualian ini, penggabungan ulang saat laporan
     * disimpan akan memasukkan kembali rekan kelompok yang sudah dihapus.
     *
     * @param  Technician|null      $pelapor
     * @param  array<int, string>   $manualNiks
     * @param  array<int, string>   $manualNames
     * @param  array<int, string>   $dikecualikan  NIK yang tidak boleh ditambahkan otomatis
     * @return array{niks: array<int, string>, names: array<int, string>, auto_niks: array<int, string>}
     */
    public function gabungKolaborator(
        ?Technician $pelapor,
        array $manualNiks,
        array $manualNames = [],
        array $dikecualikan = []
    ): array
    {
        $niks  = [];
        $names = [];

        // Kolaborator manual dipertahankan lebih dulu (urutan pilihan teknisi).
        foreach (array_values($manualNiks) as $index => $nik) {
            $nik = trim((string) $nik);

            if ($nik === '' || in_array($nik, $niks, true)) {
                continue;
            }

            $niks[]  = $nik;
            $names[] = (string) ($manualNames[$index] ?? '');
        }

        // Rekan otomatis dari kelompok, hanya bila belum ada (tanpa batas jumlah).
        $autoNiks = [];

        foreach ($this->autoCollaboratorsFor($pelapor) as $rekan) {
            $nikRekan = (string) $rekan->nik;

            // Nama yang sudah dihapus teknisi tidak dimasukkan kembali.
            if ($nikRekan === ''
                || in_array($nikRekan, $niks, true)
                || in_array($nikRekan, $dikecualikan, true)) {
                continue;
            }

            $niks[]     = $nikRekan;
            $names[]    = (string) $rekan->name;
            $autoNiks[] = $nikRekan;
        }

        return [
            'niks'      => $niks,
            'names'     => $names,
            'auto_niks' => $autoNiks,
        ];
    }

    /**
     * Buat kelompok kolaborasi baru.
     *
     * @param  array<string, mixed>  $data
     * @return CollaborationGroup
     */
    public function buatGrup(array $data): CollaborationGroup
    {
        $group = CollaborationGroup::create([
            'name'             => $data['name'],
            'code'             => $data['code'],
            'description'      => $data['description'] ?? null,
            'is_active'        => (bool) ($data['is_active'] ?? true),
            'auto_collaborate' => (bool) ($data['auto_collaborate'] ?? true),
            'sort_order'       => (int) ($data['sort_order'] ?? 0),
        ]);

        Log::info('CollaborationService: kelompok kolaborasi dibuat', [
            'group_id' => $group->id,
            'code'     => $group->code,
        ]);

        return $group;
    }

    /**
     * Perbarui kelompok kolaborasi.
     *
     * @param  CollaborationGroup    $group
     * @param  array<string, mixed>  $data
     * @return void
     */
    public function perbaruiGrup(CollaborationGroup $group, array $data): void
    {
        $group->update([
            'name'             => $data['name'] ?? $group->name,
            'description'      => $data['description'] ?? null,
            'is_active'        => (bool) ($data['is_active'] ?? false),
            'auto_collaborate' => (bool) ($data['auto_collaborate'] ?? false),
            'sort_order'       => (int) ($data['sort_order'] ?? $group->sort_order),
        ]);
    }

    /**
     * Tambah satu teknisi sebagai anggota kelompok.
     *
     * @param  CollaborationGroup  $group
     * @param  int                 $technicianId
     * @return bool  true bila benar-benar ditambahkan
     */
    public function tambahAnggota(CollaborationGroup $group, int $technicianId): bool
    {
        if ($group->members()->where('technicians.id', $technicianId)->exists()) {
            return false;
        }

        $group->members()->attach($technicianId, [
            'sort_order' => (int) $group->members()->count() + 1,
        ]);

        return true;
    }

    /**
     * Hapus satu teknisi dari kelompok.
     *
     * @param  CollaborationGroup  $group
     * @param  int                 $technicianId
     * @return bool  true bila ada yang dihapus
     */
    public function hapusAnggota(CollaborationGroup $group, int $technicianId): bool
    {
        if (! $group->members()->where('technicians.id', $technicianId)->exists()) {
            return false;
        }

        $group->members()->detach($technicianId);

        return true;
    }

    /**
     * Isi anggota kelompok dari penanda grup teknisi (technicians.group).
     *
     * Dipakai tombol "Selaraskan dari grup teknisi": semua teknisi yang kolom
     * group-nya sama dengan kode kelompok ditambahkan sebagai anggota. Anggota
     * lama tidak dihapus agar penyesuaian manual tidak hilang.
     *
     * @param  CollaborationGroup  $group
     * @return int  Jumlah anggota baru yang ditambahkan
     */
    public function selaraskanDariGrupTeknisi(CollaborationGroup $group): int
    {
        $ditambah = 0;

        foreach (Technician::where('group', $group->code)->orderBy('name')->get() as $orang) {
            if ($this->tambahAnggota($group, (int) $orang->id)) {
                $ditambah++;
            }
        }

        Log::info('CollaborationService: kelompok diselaraskan dari grup teknisi', [
            'group_id' => $group->id,
            'code'     => $group->code,
            'ditambah' => $ditambah,
        ]);

        return $ditambah;
    }

    /**
     * Buat/pastikan kelompok untuk seluruh grup shift (Grub A sampai D).
     *
     * Dipakai perintah `collab:sync-groups`. Grup 'reguler' dan 'reguler_staff'
     * dilewati secara bawaan karena anggotanya banyak; bisa dibuat manual lewat
     * halaman Kolaborasi (atau dengan opsi --with-reguler).
     *
     * @param  bool  $denganReguler  true = sekalian membuat kelompok Reguler (otomasi mati)
     * @return array<int, array{code: string, created: bool, members: int}>
     */
    public function sinkronkanKelompokGrupTeknisi(bool $denganReguler = false): array
    {
        $laporan = [];
        $urutan  = 1;

        // Group non-shift: tidak dibuatkan kelompok otomatis karena anggotanya
        // banyak dan kolaborasi otomatis akan membanjiri laporan.
        // Group non-shift (reguler/reguler staff) DAN Staff Grub A-D tidak
        // dibuatkan kelompok otomatis: anggotanya banyak, dan staf bukan pelaksana
        // shift sehingga kolaborasi otomatis akan membanjiri laporan.
        $tanpaOtomasi = array_merge(Technician::GRUP_NON_SHIFT, Technician::GRUP_STAFF);

        foreach (Technician::GROUPS as $kode => $label) {
            if (in_array($kode, $tanpaOtomasi, true) && ! $denganReguler) {
                continue;
            }

            $group = CollaborationGroup::firstOrCreate(
                ['code' => $kode],
                [
                    'name'             => $label,
                    'description'      => 'Dibuat otomatis dari grup teknisi ' . $label,
                    'is_active'        => true,
                    // Reguler / Reguler Staff dimatikan otomasinya.
                    'auto_collaborate' => ! in_array($kode, $tanpaOtomasi, true),
                    'sort_order'       => $urutan,
                ]
            );

            $laporan[] = [
                'code'    => $kode,
                'created' => $group->wasRecentlyCreated,
                'members' => $this->selaraskanDariGrupTeknisi($group),
            ];

            $urutan++;
        }

        return $laporan;
    }
}

