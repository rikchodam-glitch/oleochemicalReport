<?php

use App\Models\BagianAccess;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
});

/**
 * Buat akun Bagian yang terhubung ke teknisi dengan Group tertentu.
 *
 * @param  string      $bagian   Kode Bagian akun
 * @param  string|null $grup     Kode Group teknisi (null berarti akun tanpa teknisi)
 * @param  string      $username Username akun, sekaligus NIK teknisi
 * @return User
 */
function akunGrupBagian(string $bagian, ?string $grup, string $username): User
{
    $teknisi = null;

    if ($grup !== null) {
        $teknisi = Technician::create([
            'name'    => 'Teknisi ' . $username,
            'nik'     => $username,
            'section' => $bagian,
            'group'   => $grup,
            'status'  => 'active',
        ]);
    }

    return User::query()->create([
        'name'          => 'Akun ' . $username,
        'username'      => $username,
        'role'          => $bagian,
        'password'      => 'rahasia123',
        'technician_id' => $teknisi?->id,
    ]);
}

/**
 * Atur hak akses satu kombinasi Bagian dan Group.
 *
 * @param  string      $bagian      Kode Bagian
 * @param  string|null $grup        Kode Group (null berarti pengaturan bawaan Bagian)
 * @param  array       $permissions Daftar kunci modul yang diizinkan
 * @return void
 */
function aturAksesBagianGrup(string $bagian, ?string $grup, array $permissions): void
{
    BagianAccess::query()->updateOrCreate(
        ['bagian' => $bagian, 'grup' => $grup],
        ['permissions' => $permissions]
    );
}

// ============================================================
// HAK AKSES PER BAGIAN + GROUP
// ============================================================

test('group berbeda dalam satu bagian punya hak akses berbeda', function () {
    $reguler = akunGrupBagian('mekanik', 'reguler', '920000001');
    $staff   = akunGrupBagian('mekanik', 'reguler_staff', '920000002');

    aturAksesBagianGrup('mekanik', 'reguler_staff', ['dashboard']);

    expect($staff->grupBagian())->toBe('reguler_staff')
        ->and($reguler->grupBagian())->toBe('reguler');

    $this->actingAs($staff)->get('/dashboard')->assertStatus(200);
    $this->actingAs($staff)->get('/assets')->assertStatus(403);

    $this->actingAs($reguler)->get('/assets')->assertStatus(200);
});

test('group yang belum diatur memakai pengaturan bawaan bagian', function () {
    $akun = akunGrupBagian('sipil', 'grub_b', '920000003');

    aturAksesBagianGrup('sipil', null, ['dashboard']);

    $this->actingAs($akun)->get('/dashboard')->assertStatus(200);
    $this->actingAs($akun)->get('/assets')->assertStatus(403);
});

test('akun tanpa teknisi memakai pengaturan bawaan bagian', function () {
    $akun = akunGrupBagian('fabrikasi', null, '920000004');

    aturAksesBagianGrup('fabrikasi', null, ['dashboard']);

    expect($akun->grupBagian())->toBeNull();

    $this->actingAs($akun)->get('/dashboard')->assertStatus(200);
    $this->actingAs($akun)->get('/assets')->assertStatus(403);
});

test('pengaturan khusus group menang atas pengaturan bawaan bagian', function () {
    $akun = akunGrupBagian('ei', 'reguler', '920000005');

    aturAksesBagianGrup('ei', null, ['dashboard']);
    aturAksesBagianGrup('ei', 'reguler', ['dashboard', 'reports']);

    $this->actingAs($akun)->get('/reports')->assertStatus(200);
    $this->actingAs($akun)->get('/assets')->assertStatus(403);
});

test('menu sidebar mengikuti hak akses group', function () {
    $akun = akunGrupBagian('mekanik', 'grub_a', '920000006');

    aturAksesBagianGrup('mekanik', 'grub_a', ['dashboard']);

    expect($akun->menuTerlarangUrls())->toContain(route('assets.index'))
        ->and($akun->menuTerlarangUrls())->not->toContain(route('dashboard'));
});

test('menyimpan hak akses per grup tidak mengubah pengaturan bawaan bagian', function () {
    $this->actingAs($this->admin)
        ->post(route('akses.update', ['bagian' => 'mekanik']), [
            'grup'        => 'reguler_staff',
            'permissions' => ['dashboard', 'reports'],
        ])
        ->assertRedirect(route('akses.index', ['bagian' => 'mekanik', 'grup' => 'reguler_staff']));

    expect(BagianAccess::tersimpan('mekanik', 'reguler_staff')?->daftarModul())->toBe(['dashboard', 'reports'])
        ->and(BagianAccess::tersimpan('mekanik', null)?->daftarModul())->toContain('assets');
});

test('halaman hak akses menampilkan tab group', function () {
    $this->actingAs($this->admin)
        ->get(route('akses.index', ['bagian' => 'mekanik', 'grup' => 'reguler_staff']))
        ->assertStatus(200)
        ->assertSee('Hak Akses Bagian')
        ->assertSee('Bawaan Bagian')
        ->assertSee('Reguler Staff')
        ->assertSee('name="grup"', false);
});

test('group yang tidak dikenal ditolak saat menyimpan', function () {
    $this->actingAs($this->admin)
        ->post(route('akses.update', ['bagian' => 'mekanik']), [
            'grup'        => 'grub_z',
            'permissions' => ['dashboard'],
        ])
        ->assertSessionHasErrors('grup');

    expect(BagianAccess::query()->where('grup', 'grub_z')->exists())->toBeFalse();
});

test('label bagian akun memuat group teknisinya', function () {
    expect(akunGrupBagian('mekanik', 'reguler_staff', '920000007')->labelBagian())
        ->toBe('Mekanik - Reguler Staff')
        ->and(akunGrupBagian('mekanik', null, '920000008')->labelBagian())
        ->toBe('Mekanik');
});

test('daftar akses ikut berubah bila role akun diubah pada instance yang sama', function () {
    $akun = akunGrupBagian('mekanik', 'reguler_staff', '920000009');

    aturAksesBagianGrup('mekanik', 'reguler_staff', ['dashboard']);
    aturAksesBagianGrup('ei', 'reguler_staff', ['dashboard', 'reports']);

    expect($akun->punyaAkses('reports'))->toBeFalse();

    $akun->update(['role' => 'ei']);

    expect($akun->punyaAkses('reports'))->toBeTrue();
});
