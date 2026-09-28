<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manajemen akun user: akun admin/supervisor dan akun teknisi yang rolenya
 * mengikuti Bagian (section) teknisi.
 *
 * Aturan penting:
 * - Username akun teknisi memakai NIK, password awalnya NIK huruf kecil.
 * - Role akun berisi kode Bagian (mekanik, ei, it, dst) atau admin/supervisor.
 * - Hak akses halaman per Bagian diatur di BagianAccessController.
 */
class UserController extends Controller
{
    /**
     * Daftar akun user beserta Bagian dan teknisi yang terhubung.
     *
     * @param  Request $request Filter pencarian dan Bagian
     * @return View
     */
    public function index(Request $request): View
    {
        $query = User::with('technician');

        if ($request->filled('search')) {
            $cari = (string) $request->search;
            $query->where(function ($q) use ($cari) {
                $q->where('name', 'like', "%{$cari}%")
                    ->orWhere('username', 'like', "%{$cari}%")
                    ->orWhere('email', 'like', "%{$cari}%");
            });
        }

        if ($request->filled('bagian')) {
            $query->where('role', (string) $request->bagian);
        }

        $users = $query->orderBy('role')->orderBy('name')->paginate(20)->withQueryString();

        $jumlahPerRole = User::query()
            ->select('role', DB::raw('count(*) as jumlah'))
            ->groupBy('role')
            ->pluck('jumlah', 'role')
            ->all();

        return view('users.index', [
            'users'            => $users,
            'bagian'           => Technician::SECTIONS,
            'teknisiTanpaAkun' => $this->teknisiTanpaAkun(),
            'jumlahPerRole'    => $jumlahPerRole,
            'totalAkun'        => User::query()->count(),
        ]);
    }

    /**
     * Form tambah akun user.
     *
     * @return View
     */
    public function create(): View
    {
        return view('users.create', [
            'bagian'           => Technician::SECTIONS,
            'roleAksesPenuh'   => User::ROLE_AKSES_PENUH,
            'teknisiTanpaAkun' => $this->teknisiTanpaAkun(),
        ]);
    }

    /**
     * Simpan akun user baru.
     *
     * Bila kolom password dikosongkan, password awal memakai username huruf
     * kecil (untuk akun teknisi berarti sama dengan NIK huruf kecil).
     *
     * @param  Request $request Data form akun baru
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->aturanValidasi());

        $password = $this->passwordDariInput($data['password'] ?? null, $data['username']);

        User::query()->create([
            'name'          => $data['name'],
            'username'      => $data['username'],
            'email'         => $data['email'] ?? null,
            'role'          => $data['role'],
            'technician_id' => $data['technician_id'] ?? null,
            'password'      => $password,
        ]);

        return redirect()
            ->route('users.index')
            ->with('success', 'Akun ' . $data['name'] . ' berhasil dibuat. Password awal: ' . $password);
    }

    /**
     * Form ubah akun user.
     *
     * @param  User $user Akun yang diubah
     * @return View
     */
    public function edit(User $user): View
    {
        return view('users.edit', [
            'user'           => $user->loadMissing('technician'),
            'bagian'         => Technician::SECTIONS,
            'roleAksesPenuh' => User::ROLE_AKSES_PENUH,
            'teknisi'        => $this->teknisiUntukPilihan($user),
        ]);
    }

    /**
     * Simpan perubahan akun user.
     *
     * @param  Request $request Data form
     * @param  User    $user    Akun yang diubah
     * @return RedirectResponse
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->aturanValidasi($user));

        // Administrator tidak boleh menurunkan role akunnya sendiri supaya
        // tidak terkunci dari halaman manajemen user.
        if ($user->id === Auth::id() && $data['role'] !== User::ROLE_ADMIN) {
            return back()->withInput()->with('error', 'Role akun Anda sendiri tidak bisa diubah dari administrator.');
        }

        // Administrator terakhir tidak boleh kehilangan rolenya.
        if ($user->role === User::ROLE_ADMIN && $data['role'] !== User::ROLE_ADMIN
            && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1) {
            return back()->withInput()->with('error', 'Minimal harus ada satu akun administrator.');
        }

        $ubah = [
            'name'          => $data['name'],
            'username'      => $data['username'],
            'email'         => $data['email'] ?? null,
            'role'          => $data['role'],
            'technician_id' => $data['technician_id'] ?? null,
        ];

        // Password hanya diubah bila kolomnya diisi.
        if (! empty($data['password'])) {
            $ubah['password'] = $data['password'];
        }

        $user->update($ubah);

        return redirect()->route('users.index')->with('success', 'Akun ' . $user->name . ' berhasil diperbarui.');
    }

    /**
     * Hapus akun user.
     *
     * @param  User $user Akun yang dihapus
     * @return RedirectResponse
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Akun yang sedang dipakai tidak bisa dihapus.');
        }

        if ($user->role === User::ROLE_ADMIN && User::query()->where('role', User::ROLE_ADMIN)->count() <= 1) {
            return back()->with('error', 'Minimal harus ada satu akun administrator.');
        }

        $nama = $user->name;
        $user->delete();

        return redirect()->route('users.index')->with('success', 'Akun ' . $nama . ' berhasil dihapus.');
    }

    /**
     * Reset password akun: ke NIK teknisi (huruf kecil), atau ke password yang
     * diisi administrator bila teknisi tidak punya NIK.
     *
     * @param  Request $request Boleh berisi password pengganti
     * @param  User    $user    Akun yang direset
     * @return RedirectResponse
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => 'nullable|string|min:6',
        ]);

        $nik = $user->technician?->nik;

        if (! empty($data['password'])) {
            $password = $data['password'];
        } elseif (! empty($nik)) {
            $password = mb_strtolower((string) $nik);
        } else {
            return back()->with('error', 'Akun ini tidak terhubung ke teknisi ber-NIK, isi password pengganti secara manual.');
        }

        $user->update(['password' => $password]);

        return redirect()->route('users.index')->with('success', 'Password ' . $user->name . ' direset menjadi: ' . $password);
    }

    /**
     * Buat akun login sekaligus untuk semua teknisi yang belum punya akun.
     *
     * Username memakai NIK, password memakai NIK huruf kecil, dan role memakai
     * Bagian (section) teknisi. Teknisi tanpa NIK atau yang NIK-nya sudah
     * dipakai akun lain akan dilaporkan sebagai dilewati, bukan dihentikan.
     *
     * @return RedirectResponse
     */
    public function generateTeknisi(): RedirectResponse
    {
        $idPunyaAkun = User::query()->whereNotNull('technician_id')->pluck('technician_id')->all();

        $kandidat = Technician::query()
            ->whereNotIn('id', $idPunyaAkun)
            ->orderBy('name')
            ->get();

        $dibuat   = 0;
        $dilewati = [];

        foreach ($kandidat as $teknisi) {
            $nik = trim((string) $teknisi->nik);

            if ($nik === '') {
                $dilewati[] = $teknisi->name . ' (NIK kosong)';
                continue;
            }

            $sudahDipakai = User::query()
                ->where('username', $nik)
                ->orWhere('email', $nik)
                ->exists();

            if ($sudahDipakai) {
                $dilewati[] = $teknisi->name . ' (NIK ' . $nik . ' sudah dipakai akun lain)';
                continue;
            }

            User::query()->create([
                'name'          => $teknisi->name,
                'username'      => $nik,
                'email'         => null,
                'role'          => $this->bagianValid($teknisi->section),
                'technician_id' => $teknisi->id,
                'password'      => mb_strtolower($nik),
            ]);

            $dibuat++;
        }

        $pesan = $dibuat . ' akun teknisi dibuat (username NIK, password awal NIK huruf kecil).';

        if ($dilewati !== []) {
            $pesan .= ' ' . count($dilewati) . ' teknisi dilewati: ' . implode('; ', array_slice($dilewati, 0, 10));
        }

        return redirect()->route('users.index')->with('success', $pesan);
    }

    /**
     * Pastikan kode Bagian yang disimpan selalu nilai yang dikenal.
     *
     * @param  string|null $bagian Kode section teknisi
     * @return string              Kode section, 'lainnya' bila tidak dikenal
     */
    private function bagianValid(?string $bagian): string
    {
        $kode = (string) $bagian;

        return array_key_exists($kode, Technician::SECTIONS) ? $kode : 'lainnya';
    }

    /**
     * Aturan validasi form akun user.
     *
     * @param  User|null $user Akun yang sedang diubah (null saat tambah baru)
     * @return array<string, mixed>
     */
    private function aturanValidasi(?User $user = null): array
    {
        $daftarRole = array_merge(User::ROLE_AKSES_PENUH, array_keys(Technician::SECTIONS));

        return [
            'name'          => 'required|string|max:255',
            'username'      => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'email'         => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role'          => 'required|string|in:' . implode(',', $daftarRole),
            'technician_id' => [
                'nullable', 'integer', 'exists:technicians,id',
                Rule::unique('users', 'technician_id')->ignore($user?->id),
            ],
            'password'      => 'nullable|string|min:6',
        ];
    }

    /**
     * Password awal: pakai isian administrator bila ada, jika tidak memakai
     * username huruf kecil.
     *
     * @param  string|null $password Isian password dari form
     * @param  string      $username Username akun
     * @return string
     */
    private function passwordDariInput(?string $password, string $username): string
    {
        if ($password !== null && $password !== '') {
            return $password;
        }

        return mb_strtolower($username);
    }

    /**
     * Teknisi yang belum punya akun login.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Technician>
     */
    private function teknisiTanpaAkun()
    {
        $idPunyaAkun = User::query()->whereNotNull('technician_id')->pluck('technician_id')->all();

        return Technician::query()
            ->whereNotIn('id', $idPunyaAkun)
            ->orderBy('name')
            ->get(['id', 'name', 'nik', 'section']);
    }

    /**
     * Teknisi yang bisa dipilih pada form ubah akun: yang belum punya akun
     * ditambah teknisi yang saat ini terhubung dengan akun tersebut.
     *
     * @param  User $user Akun yang sedang diubah
     * @return \Illuminate\Database\Eloquent\Collection<int, Technician>
     */
    private function teknisiUntukPilihan(User $user)
    {
        $idPunyaAkun = User::query()
            ->whereNotNull('technician_id')
            ->where('id', '!=', $user->id)
            ->pluck('technician_id')
            ->all();

        return Technician::query()
            ->whereNotIn('id', $idPunyaAkun)
            ->orderBy('name')
            ->get(['id', 'name', 'nik', 'section']);
    }
}
