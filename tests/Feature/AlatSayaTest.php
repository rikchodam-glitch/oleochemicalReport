<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\User;
use App\Services\ToolLendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Halaman web peminjaman alat untuk akun teknisi/keeper dan halaman log alat.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->lokasi = ToolLocation::create(['name' => 'Workshop']);
    $this->rak    = ToolRack::create(['tool_location_id' => $this->lokasi->id, 'name' => 'Rak A']);

    $this->alat = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Web',
        'nik'         => 'WEB001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '44001',
    ]);

    $this->akunTeknisi = User::create([
        'name'         => 'Akun Teknisi Web',
        'email'        => 'teknisi-web@oleochemical.pro',
        'password'     => bcrypt('rahasia'),
        'role'         => 'mekanik',
        'technician_id'=> $this->teknisi->id,
    ]);

    $this->keeper = Technician::create([
        'name'           => 'Keeper Web',
        'nik'            => 'WEB002',
        'group'          => 'reguler',
        'section'        => 'mekanik',
        'status'         => 'active',
        'is_tool_keeper' => true,
        'telegram_id'    => '44002',
    ]);

    $this->akunKeeper = User::create([
        'name'         => 'Akun Keeper Web',
        'email'        => 'keeper-web@oleochemical.pro',
        'password'     => bcrypt('rahasia'),
        'role'         => 'mekanik',
        'technician_id'=> $this->keeper->id,
    ]);

    $this->perusahaan = Company::create(['code' => 'WEB', 'name' => 'PT Web Uji']);
    $this->departemen = Department::create([
        'company_id' => $this->perusahaan->id,
        'code'       => 'PRD',
        'name'       => 'Produksi',
    ]);

    config(['services.telegram.bot_token' => 'token-uji-alat-web']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

test('teknisi dapat membuka halaman peminjaman alat dan meminjam untuk diri sendiri', function () {
    $this->actingAs($this->akunTeknisi)
        ->get(route('alat.index'))
        ->assertOk()
        ->assertSee('Peminjaman Alat')
        ->assertSee('Pinjaman Saya')
        ->assertSee('BRBT001');

    $this->actingAs($this->akunTeknisi)
        ->post(route('alat.pinjam', $this->alat->id))
        ->assertRedirect();

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->technician_id)->toBe($this->teknisi->id)
        ->and($pinjaman->handled_by_type)->toBe(ToolLoan::HANDLED_SELF)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);

    $this->actingAs($this->akunTeknisi)
        ->post(route('alat.kembali', $pinjaman->id))
        ->assertRedirect();

    expect($pinjaman->fresh()->return_requested_at)->not->toBeNull()
        ->and($pinjaman->fresh()->returned_at)->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);
});

test('teknisi biasa tidak boleh meminjamkan alat', function () {
    $this->actingAs($this->akunTeknisi)
        ->post(route('alat.pinjamkan'), [
            'tool_id'       => $this->alat->id,
            'borrower_type' => ToolLoan::BORROWER_TEKNISI,
            'technician_id' => $this->teknisi->id,
        ])
        ->assertForbidden();

    expect(ToolLoan::query()->count())->toBe(0);
});

test('keeper dapat meminjamkan alat lewat web dan mengembalikannya', function () {
    $this->actingAs($this->akunKeeper)
        ->post(route('alat.pinjamkan'), [
            'tool_id'       => $this->alat->id,
            'borrower_type' => ToolLoan::BORROWER_TEKNISI,
            'technician_id' => $this->teknisi->id,
        ])
        ->assertRedirect();

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->technician_id)->toBe($this->teknisi->id)
        ->and($pinjaman->handled_by_type)->toBe(ToolLoan::HANDLED_KEEPER)
        ->and($pinjaman->handled_by_technician_id)->toBe($this->keeper->id)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);

    $this->actingAs($this->akunKeeper)
        ->post(route('alat.kembali', $pinjaman->id))
        ->assertRedirect();

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);
});

test('keeper dapat meminjamkan ke non-teknisi dengan departemen pilihan', function () {
    $this->actingAs($this->akunKeeper)
        ->post(route('alat.pinjamkan'), [
            'tool_id'                => $this->alat->id,
            'borrower_type'          => ToolLoan::BORROWER_NON_TEKNISI,
            'borrower_name'          => 'Tamu Kontraktor',
            'borrower_department_id' => $this->departemen->id,
        ])
        ->assertRedirect();

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->borrower_type)->toBe(ToolLoan::BORROWER_NON_TEKNISI)
        ->and($pinjaman->borrower_name)->toBe('Tamu Kontraktor')
        ->and($pinjaman->borrower_department_nama)->toBe('Produksi');
});

test('teknisi tidak boleh mengembalikan pinjaman teknisi lain lewat web', function () {
    $teknisiLain = Technician::create([
        'name'   => 'Teknisi Lain',
        'nik'    => 'WEB003',
        'status' => 'active',
    ]);

    $layanan = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($teknisiLain),
        ToolLoan::HANDLED_ADMIN,
        null,
        $this->admin
    );

    $this->actingAs($this->akunTeknisi)
        ->post(route('alat.kembali', $pinjaman->id))
        ->assertForbidden();

    expect($pinjaman->fresh()->returned_at)->toBeNull();
});

test('halaman log alat menampilkan durasi dan filter tanggal', function () {
    $layanan = app(ToolLendingService::class);

    $lama = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->kembalikan($lama, ToolLoan::HANDLED_SELF, $this->teknisi);
    $lama->update([
        'borrowed_at' => now()->subDays(20),
        'returned_at' => now()->subDays(20)->addMinutes(90),
    ]);

    $baru = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->keeper), ToolLoan::HANDLED_SELF, $this->keeper);
    $layanan->kembalikan($baru, ToolLoan::HANDLED_SELF, $this->keeper);
    $baru->update([
        'borrowed_at' => now()->subDay(),
        'returned_at' => now()->subDay()->addHours(2),
    ]);

    $this->actingAs($this->akunTeknisi)
        ->get(route('alat.show', $this->alat->id))
        ->assertOk()
        ->assertSee('Log Peminjaman')
        ->assertSee('1 jam 30 menit')
        ->assertSee('2 jam');

    $dari = now()->subDays(5)->toDateString();

    $this->actingAs($this->akunTeknisi)
        ->get(route('alat.show', ['tool' => $this->alat->id, 'dari' => $dari]))
        ->assertOk()
        ->assertSee($baru->kode)
        ->assertDontSee($lama->kode);
});

test('durasi pinjaman aktif dihitung sampai sekarang', function () {
    $pinjaman = new ToolLoan(['borrowed_at' => now()->subHours(2)]);

    expect($pinjaman->durasiMenit())->toBeGreaterThanOrEqual(119)
        ->and($pinjaman->durasiTeks())->toContain('masih dipinjam');
});

test('card alat dapat diklik dan rak dapat di-collapse', function () {
    $this->actingAs($this->akunTeknisi)
        ->get(route('alat.index'))
        ->assertOk()
        ->assertSee('data-href-card="' . route('alat.show', $this->alat->id) . '"', false)
        ->assertSee('aria-controls="akun-rak-' . $this->rak->id . '"', false)
        ->assertSee('data-collapse-toggle', false)
        ->assertSee('Tutup semua');
});

test('halaman detail alat menampilkan edit hanya untuk administrator', function () {
    $this->actingAs($this->admin)
        ->get(route('alat.show', $this->alat->id))
        ->assertOk()
        ->assertSee('Edit Alat')
        ->assertSee('data-id="' . $this->alat->id . '"', false);

    $this->actingAs($this->akunTeknisi)
        ->get(route('alat.show', $this->alat->id))
        ->assertOk()
        ->assertDontSee('Edit Alat');
});

test('riwayat peminjaman admin punya tombol menuju log alat', function () {
    $layanan = app(ToolLendingService::class);
    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    $this->actingAs($this->admin)
        ->get(route('tools.loans.index'))
        ->assertOk()
        ->assertSee(route('alat.show', $this->alat->id), false);

    $this->actingAs($this->admin)
        ->get(route('tools.index'))
        ->assertOk()
        ->assertSee(route('alat.show', $this->alat->id), false);
});

test('akun tanpa teknisi hanya bisa melihat halaman alat', function () {
    $akun = User::create([
        'name'     => 'Akun Tanpa Teknisi',
        'email'    => 'tanpa-teknisi@oleochemical.pro',
        'password' => bcrypt('rahasia'),
        'role'     => 'mekanik',
    ]);

    $this->actingAs($akun)->get(route('alat.index'))->assertOk();
    $this->actingAs($akun)->post(route('alat.pinjam', $this->alat->id))
        ->assertSessionHasErrors('alat');

    expect(ToolLoan::query()->count())->toBe(0);
});
