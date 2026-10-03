<?php

namespace App\Http\Controllers;

use App\Models\BagianAccess;
use App\Models\Technician;
use App\Models\User;
use App\Support\AksesModul;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengaturan hak akses halaman per Bagian dan per Group teknisi.
 *
 * Satu Bagian (section teknisi) punya daftar modul halaman yang boleh dibuka,
 * dan tiap Group di dalam Bagian itu (reguler, reguler staff, Grub A-D) bisa
 * dipersempit lagi supaya akses "mekanik reguler" bisa berbeda dari "mekanik
 * reguler staff". Group yang belum diatur sendiri memakai pengaturan bawaan
 * Bagian. Modul bertanda khusus_admin (mis. Manajemen User) tidak bisa
 * diberikan ke Bagian mana pun.
 */
class BagianAccessController extends Controller
{
    /**
     * Halaman pengaturan hak akses: tab Bagian, lalu tab Group di dalamnya.
     *
     * @param  Request $request Parameter ?bagian= dan ?grup= untuk membuka satu kombinasi
     * @return View
     */
    public function index(Request $request): View
    {
        $bagianTerbuka = (string) $request->input('bagian', array_key_first(Technician::SECTIONS));

        if (! array_key_exists($bagianTerbuka, Technician::SECTIONS)) {
            $bagianTerbuka = (string) array_key_first(Technician::SECTIONS);
        }

        $grupTerbuka = $this->grupValid($request->input('grup'));

        $jumlahAkun    = $this->jumlahPerBagianDanGrup('akun');
        $jumlahTeknisi = $this->jumlahPerBagianDanGrup('teknisi');

        return view('akses.index', [
            'modulPerGrup'         => AksesModul::perGrup(),
            'daftarBagian'         => $this->daftarBagian($jumlahAkun, $jumlahTeknisi),
            'daftarGrup'           => $this->daftarGrup($bagianTerbuka, $grupTerbuka, $jumlahAkun, $jumlahTeknisi),
            'bagianTerbuka'        => $bagianTerbuka,
            'grupTerbuka'          => $grupTerbuka,
            'jumlahAkunAksesPenuh' => User::query()->whereIn('role', User::ROLE_AKSES_PENUH)->count(),
        ]);
    }

    /**
     * Jumlah akun atau teknisi per kombinasi Bagian + Group.
     *
     * Perhitungan dilakukan di PHP (bukan GROUP BY SQL) supaya aman untuk nama
     * kolom group yang merupakan kata kunci di MySQL maupun SQLite, dan supaya
     * baris yang Bagiannya tidak dikenal bisa dilewati.
     *
     * @param  string $jenis 'akun' untuk tabel users, 'teknisi' untuk tabel technicians
     * @return array<string, int> Peta jumlah dengan kunci "bagian|grup"
     */
    private function jumlahPerBagianDanGrup(string $jenis): array
    {
        $hasil = [];

        if ($jenis === 'teknisi') {
            foreach (Technician::query()->get(['section', 'group']) as $teknisi) {
                $this->tambahkanJumlah($hasil, (string) $teknisi->section, $teknisi->group);
            }

            return $hasil;
        }

        foreach (User::query()->with('technician:id,group')->get() as $akun) {
            $this->tambahkanJumlah($hasil, (string) $akun->role, $akun->technician?->group);
        }

        return $hasil;
    }

    /**
     * Tambahkan satu hitungan ke peta jumlah Bagian + Group.
     *
     * @param  array<string, int> $hasil  Peta jumlah yang sedang disusun
     * @param  string             $bagian Kode Bagian
     * @param  string|null        $grup   Kode Group (null berarti tanpa Group)
     * @return void
     */
    private function tambahkanJumlah(array &$hasil, string $bagian, ?string $grup): void
    {
        if (! array_key_exists($bagian, Technician::SECTIONS)) {
            return;
        }

        $kunci = $this->kunci($bagian, $grup);

        $hasil[$kunci] = ($hasil[$kunci] ?? 0) + 1;
    }

    /**
     * Kunci peta jumlah untuk satu kombinasi Bagian + Group.
     *
     * @param  string      $bagian Kode Bagian
     * @param  string|null $grup   Kode Group
     * @return string              Kunci berbentuk "bagian|grup"
     */
    private function kunci(string $bagian, ?string $grup): string
    {
        return $bagian . '|' . (string) $this->grupValid($grup);
    }

    /**
     * Daftar Bagian untuk baris tab pertama, tanpa rincian Group.
     *
     * @param  array<string, int> $jumlahAkun    Peta jumlah akun per Bagian + Group
     * @param  array<string, int> $jumlahTeknisi Peta jumlah teknisi per Bagian + Group
     * @return array<int, array<string, mixed>>
     */
    private function daftarBagian(array $jumlahAkun, array $jumlahTeknisi): array
    {
        $daftar = [];

        foreach (Technician::SECTIONS as $kode => $label) {
            $daftar[] = [
                'kode'           => $kode,
                'label'          => $label,
                'jumlah_akun'    => $this->totalBagian($jumlahAkun, $kode),
                'jumlah_teknisi' => $this->totalBagian($jumlahTeknisi, $kode),
                'tersimpan'      => BagianAccess::tersimpan($kode, null) !== null,
            ];
        }

        return $daftar;
    }

    /**
     * Total jumlah seluruh Group pada satu Bagian.
     *
     * @param  array<string, int> $peta   Peta jumlah per Bagian + Group
     * @param  string             $bagian Kode Bagian
     * @return int
     */
    private function totalBagian(array $peta, string $bagian): int
    {
        $total = 0;

        foreach ($peta as $kunci => $jumlah) {
            if (str_starts_with((string) $kunci, $bagian . '|')) {
                $total += $jumlah;
            }
        }

        return $total;
    }

    /**
     * Daftar tab Group di dalam satu Bagian, dimulai dari pengaturan bawaan.
     *
     * @param  string             $bagian        Kode Bagian yang sedang dibuka
     * @param  string|null        $grupTerbuka   Group yang sedang dibuka (null berarti bawaan)
     * @param  array<string, int> $jumlahAkun    Peta jumlah akun per Bagian + Group
     * @param  array<string, int> $jumlahTeknisi Peta jumlah teknisi per Bagian + Group
     * @return array<int, array<string, mixed>>
     */
    private function daftarGrup(
        string $bagian,
        ?string $grupTerbuka,
        array $jumlahAkun,
        array $jumlahTeknisi
    ): array {
        $daftar = [];

        foreach (array_merge([null], array_keys(Technician::GROUPS)) as $grup) {
            $tersimpan = BagianAccess::tersimpan($bagian, $grup);
            $baris     = $tersimpan ?? BagianAccess::untukBagian($bagian, $grup);

            $daftar[] = [
                'kode'           => $grup,
                'label'          => $grup === null ? 'Bawaan Bagian' : (Technician::GROUPS[$grup] ?? $grup),
                'permissions'    => $baris->daftarModul(),
                'tersimpan'      => $tersimpan !== null,
                'mengikuti'      => $grup !== null && $tersimpan === null,
                'jumlah_akun'    => $jumlahAkun[$this->kunci($bagian, $grup)] ?? 0,
                'jumlah_teknisi' => $jumlahTeknisi[$this->kunci($bagian, $grup)] ?? 0,
                'aktif'          => $grupTerbuka === $grup,
            ];
        }

        return $daftar;
    }

    /**
     * Simpan hak akses satu Bagian (bawaan) atau satu Bagian + Group.
     *
     * @param  Request $request Berisi array permissions dan grup (opsional)
     * @param  string  $bagian  Kode Bagian yang diubah
     * @return RedirectResponse
     */
    public function update(Request $request, string $bagian): RedirectResponse
    {
        if (! array_key_exists($bagian, Technician::SECTIONS)) {
            abort(404, 'Bagian tidak dikenali.');
        }

        $data = $request->validate([
            'grup'          => 'nullable|string|in:' . implode(',', array_keys(Technician::GROUPS)),
            'permissions'   => 'array',
            'permissions.*' => 'string|in:' . implode(',', array_keys(AksesModul::semua())),
        ]);

        $grup = $this->grupValid($data['grup'] ?? null);

        // Modul khusus admin tidak bisa diberikan ke Bagian maupun Group mana pun.
        $permissions = array_values(array_filter(
            (array) ($data['permissions'] ?? []),
            fn ($kunci) => ! AksesModul::khususAdmin((string) $kunci)
        ));

        BagianAccess::query()->updateOrCreate(
            ['bagian' => $bagian, 'grup' => $grup],
            ['permissions' => $permissions]
        );

        $label = Technician::SECTIONS[$bagian]
            . ($grup === null ? ' (bawaan seluruh Group)' : ' - ' . (Technician::GROUPS[$grup] ?? $grup));

        return redirect()
            ->route('akses.index', array_filter([
                'bagian' => $bagian,
                'grup'   => $grup,
            ], fn ($nilai) => $nilai !== null))
            ->with('success', 'Hak akses ' . $label . ' disimpan (' . count($permissions) . ' halaman diizinkan).');
    }

    /**
     * Rapikan kode Group dari isian form atau parameter URL.
     *
     * @param  mixed $grup Kode Group mentah
     * @return string|null Kode Group yang dikenal, null berarti pengaturan bawaan Bagian
     */
    private function grupValid(mixed $grup): ?string
    {
        $kode = trim((string) $grup);

        return array_key_exists($kode, Technician::GROUPS) ? $kode : null;
    }
}
