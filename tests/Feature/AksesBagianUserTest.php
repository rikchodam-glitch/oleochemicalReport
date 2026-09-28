<?php

use App\Models\BagianAccess;
use App\Models\Technician;
use App\Models\User;
use App\Support\AksesModul;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

/**
 * Bentuk akun Bagian dengan daftar modul yang diizinkan untuk diuji.
 *
 * @param  string      $bagian   Kode Bagian (section)
 * @param  array       $modul    Daftar kunci modul yang diizinkan
 * @param  string      $username Username akun
 * @param  Technician|null $teknisi Teknisi yang dihubungkan (opsional)
 * @return User
 */
function akunBagian(string $bagian, array $modul, string $username, ?Technician $teknisi = null): User
{
    BagianAccess::query()->updateOrCreate(
        ['bagian' => $bagian],
        ['permissions' => $modul]
    );

    return User::query()->create([
        'name'          => 'Akun ' . ucfirst($bagian),
        'username'      => $username,
        'role'          => $bagian,
        'password'      => 'rahasia123',
        'technician_id' => $teknisi?->id,
    ]);
}

// ============================================================
// LOGIN
// ============================================================

test('login akun teknisi memakai NIK dan password NIK huruf kecil', function () {
    $teknisi = Technician::create([
        'name'   => 'Teknisi NIK',
        'nik'    => 'ARE23070009',
        'status' => 'active',
    ]);

    $akun = akunBagian('mekanik', ['dashboard'], 'ARE23070009', $teknisi);

    // Password akun dibuat dari NIK huruf kecil.
    $akun->update(['password' => 'are23070009']);

    $this->post('/login', [
        'username' => 'ARE23070009',
        'password' => 'are23070009',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($akun->fresh());
});

test('login akun lama memakai email tetap berhasil', function () {
    $this->post('/login', [
        'email'    => 'admin@oleochemical.pro',
        'password' => 'password123',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

test('halaman login tidak lagi memuat contoh username dan password', function () {
    $this->get('/login')
        ->assertStatus(200)
        ->assertDontSee('admin@oleochemical.pro')
        ->assertDontSee('password123')
        ->assertSee('NIK / Email');
});

// ============================================================
// HAK AKSES HALAMAN PER BAGIAN
// ============================================================

test('akun bagian ditolak 403 pada halaman yang tidak dicentang', function () {
    $akun = akunBagian('mekanik', ['dashboard'], '900000001');

    $this->actingAs($akun)->get('/dashboard')->assertStatus(200);
    $this->actingAs($akun)->get('/assets')->assertStatus(403);
    $this->actingAs($akun)->get('/reports')->assertStatus(403);
});

test('menu sidebar yang tidak boleh dibuka masuk daftar sembunyi', function () {
    $akun = akunBagian('mekanik', ['dashboard'], '900000002');

    expect($akun->menuTerlarangUrls())->toContain(route('assets.index'))
        ->and($akun->menuTerlarangUrls())->not->toContain(route('dashboard'));
});

test('akun bagian bisa membuka halaman yang dicentang', function () {
    $akun = akunBagian('mekanik', ['dashboard', 'assets'], '900000003');

    $this->actingAs($akun)->get('/assets')->assertStatus(200);
    $this->actingAs($akun)->get('/reports')->assertStatus(403);
});

test('administrator dan supervisor selalu bisa membuka semua halaman', function () {
    $supervisor = User::where('email', 'supervisor@oleochemical.pro')->firstOrFail();

    $this->actingAs($this->admin)->get('/assets')->assertStatus(200);
    $this->actingAs($this->admin)->get(route('users.index'))->assertStatus(200);
    $this->actingAs($this->admin)->get(route('akses.index'))->assertStatus(200);

    $this->actingAs($supervisor)->get('/assets')->assertStatus(200);
});

test('akun bagian tidak bisa membuka manajemen user walau hak aksesnya dicentang', function () {
    $akun = akunBagian('mekanik', ['dashboard', 'users'], '900000004');

    expect($akun->punyaAkses('users'))->toBeFalse();

    $this->actingAs($akun)->get(route('users.index'))->assertStatus(403);
    $this->actingAs($akun)->get(route('akses.index'))->assertStatus(403);
});

test('akun tanpa role ditolak oleh middleware', function () {
    $akun = User::query()->create([
        'name'     => 'Tanpa Role',
        'username' => '900000005',
        'role'     => '',
        'password' => 'rahasia123',
    ]);

    $this->actingAs($akun)->get('/dashboard')->assertStatus(403);
});

// ============================================================
// PENGATURAN HAK AKSES & MANAJEMEN USER
// ============================================================

test('menyimpan hak akses bagian langsung berlaku', function () {
    $akun = akunBagian('fabrikasi', ['dashboard'], '900000006');

    $this->actingAs($this->admin)
        ->post(route('akses.update', ['bagian' => 'fabrikasi']), [
            'permissions' => ['dashboard', 'reports'],
        ])
        ->assertRedirect(route('akses.index', ['bagian' => 'fabrikasi']));

    $tersimpan = BagianAccess::query()->where('bagian', 'fabrikasi')->firstOrFail();

    expect($tersimpan->daftarModul())->toBe(['dashboard', 'reports']);

    $this->actingAs($akun)->get('/reports')->assertStatus(200);
    $this->actingAs($akun)->get('/assets')->assertStatus(403);
});

test('modul khusus administrator tidak bisa diberikan ke bagian', function () {
    $this->actingAs($this->admin)
        ->post(route('akses.update', ['bagian' => 'sipil']), [
            'permissions' => ['dashboard', 'users', 'storage'],
        ])
        ->assertRedirect();

    $tersimpan = BagianAccess::query()->where('bagian', 'sipil')->firstOrFail();

    expect($tersimpan->daftarModul())->toBe(['dashboard', 'storage']);
});

test('buat akun semua teknisi memakai NIK sebagai username dan password', function () {
    $teknisi = Technician::create([
        'name'    => 'Teknisi Generate',
        'nik'     => '2607021204',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->actingAs($this->admin)
        ->post(route('users.generate-teknisi'))
        ->assertRedirect(route('users.index'));

    $akun = User::query()->where('username', '2607021204')->firstOrFail();

    expect($akun->role)->toBe('mekanik')
        ->and($akun->technician_id)->toBe($teknisi->id)
        ->and(Hash::check('2607021204', $akun->password))->toBeTrue();

    $this->post('/login', ['username' => '2607021204', 'password' => '2607021204'])
        ->assertRedirect('/dashboard');
});

test('reset password akun mengembalikan ke NIK huruf kecil', function () {
    $teknisi = Technician::create([
        'name'   => 'Teknisi Reset',
        'nik'    => 'ARE26020002',
        'status' => 'active',
    ]);

    $akun = akunBagian('fabrikasi', ['dashboard'], 'ARE26020002', $teknisi);
    $akun->update(['password' => 'passwordLama']);

    $this->actingAs($this->admin)
        ->post(route('users.reset-password', $akun))
        ->assertRedirect(route('users.index'));

    expect(Hash::check('are26020002', $akun->fresh()->password))->toBeTrue();
});

test('tambah akun manual memakai password awal dari username huruf kecil', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), [
            'name'     => 'Akun Baru',
            'username' => 'ABC12345',
            'role'     => 'ei',
        ])
        ->assertRedirect(route('users.index'));

    $akun = User::query()->where('username', 'ABC12345')->firstOrFail();

    expect($akun->role)->toBe('ei')
        ->and(Hash::check('abc12345', $akun->password))->toBeTrue();
});

test('administrator tidak bisa menurunkan role akunnya sendiri', function () {
    $this->admin->update(['username' => 'admin1']);

    $this->actingAs($this->admin)
        ->put(route('users.update', $this->admin), [
            'name'     => $this->admin->name,
            'username' => 'admin1',
            'role'     => 'mekanik',
        ])
        ->assertRedirect();

    expect($this->admin->fresh()->role)->toBe('admin');
});

test('semua route bernama punya modul hak akses atau masuk daftar pengecualian', function () {
    $bebasModul = config('akses.route_bebas_modul', []);
    $tanpaModul = [];

    foreach (Route::getRoutes() as $route) {
        $nama = $route->getName();

        if ($nama === null || in_array($nama, $bebasModul, true)) {
            continue;
        }

        if (AksesModul::kunciUntukRoute($nama) === null) {
            $tanpaModul[] = $nama;
        }
    }

    expect($tanpaModul)->toBe([]);
});

// ============================================================
// HALAMAN BARU BISA DIBUKA
// ============================================================

test('halaman manajemen user dan hak akses bisa dibuka administrator', function () {
    $teknisi = Technician::create([
        'name'   => 'Teknisi Halaman',
        'nik'    => '2607021999',
        'status' => 'active',
    ]);

    $akun = akunBagian('mekanik', ['dashboard'], '2607021999', $teknisi);

    $this->actingAs($this->admin)->get('/dashboard')->assertStatus(200)->assertSee(route('users.index'));
    $this->actingAs($this->admin)->get(route('users.index'))->assertStatus(200)->assertSee('Manajemen User');
    $this->actingAs($this->admin)->get(route('users.create'))->assertStatus(200)->assertSee('Username (NIK)');
    $this->actingAs($this->admin)->get(route('users.edit', $akun))->assertStatus(200)->assertSee('Ubah Akun');
    $this->actingAs($this->admin)->get(route('akses.index'))->assertStatus(200)->assertSee('Hak Akses Bagian');
    $this->actingAs($this->admin)
        ->get(route('akses.index', ['bagian' => 'mekanik']))
        ->assertStatus(200)
        ->assertSee('Mekanik');
});

// ============================================================
// MODE HANYA BACA (SELAIN ADMINISTRATOR)
// ============================================================

test('akun bagian hanya bisa membaca walaupun halamannya boleh dibuka', function () {
    $akun = akunBagian('mekanik', ['dashboard', 'assets'], '900000007');

    expect($akun->bolehMenulis())->toBeFalse();

    $this->actingAs($akun)->get('/assets')->assertStatus(200);
    $this->actingAs($akun)->post('/assets', ['equipment_no' => 'TES-1'])->assertStatus(403);
    $this->actingAs($akun)->post('/technicians', ['name' => 'Tes'])->assertStatus(403);
    $this->actingAs($akun)->post('/reports', [])->assertStatus(403);
});

test('supervisor juga hanya bisa membaca', function () {
    $supervisor = User::where('email', 'supervisor@oleochemical.pro')->firstOrFail();

    expect($supervisor->bolehMenulis())->toBeFalse();

    $this->actingAs($supervisor)->get('/assets')->assertStatus(200);
    $this->actingAs($supervisor)->post('/assets', ['equipment_no' => 'TES-2'])->assertStatus(403);
});

test('administrator tetap bisa menambah data', function () {
    expect($this->admin->bolehMenulis())->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('users.store'), [
            'name'     => 'Akun Admin Baru',
            'username' => 'admin2',
            'role'     => 'admin',
        ])
        ->assertRedirect(route('users.index'));
});

test('akun hanya baca tetap bisa logout', function () {
    $akun = akunBagian('mekanik', ['dashboard'], '900000008');

    $this->actingAs($akun)->post(route('logout'))->assertRedirect('/login');

    $this->assertGuest();
});

test('penanda mode hanya baca tampil untuk akun bagian, tidak untuk administrator', function () {
    $akun = akunBagian('mekanik', ['dashboard'], '900000009');

    $this->actingAs($akun)->get('/dashboard')->assertStatus(200)->assertSee('Mode hanya baca');
    $this->actingAs($this->admin)->get('/dashboard')->assertStatus(200)->assertDontSee('Mode hanya baca');
});

test('menu kolaborasi dan kelompok pengaturan dirender pada halaman', function () {
    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertSee(route('collaboration.index'))
        ->assertSee('Manajemen User')
        ->assertSee('Hak Akses Bagian')
        ->assertSee(route('storage.index'));
});
