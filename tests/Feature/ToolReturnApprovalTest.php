<?php

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolGroup;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolToken;
use App\Models\ToolType;
use App\Models\User;
use App\Services\ToolLendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Pengembalian alat harus diperiksa/diterima tool keeper.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $lokasi = ToolLocation::create(['name' => 'Workshop']);
    $rak    = ToolRack::create(['tool_location_id' => $lokasi->id, 'name' => 'Rak A']);
    $jenis  = ToolType::create(['name' => 'Bor']);
    $grup   = ToolGroup::create(['tool_type_id' => $jenis->id, 'name' => 'Bor Batrai']);

    $this->alat = Tool::create([
        'tool_rack_id'  => $rak->id,
        'tool_group_id' => $grup->id,
        'name'          => 'Bor Batrai',
        'code'          => 'BRBT001',
        'status'        => Tool::STATUS_TERSEDIA,
    ]);

    $this->peminjam = Technician::create([
        'name'        => 'Teknisi Peminjam',
        'nik'         => 'KMB001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '77001',
    ]);

    $this->keeper = Technician::create([
        'name'           => 'Keeper Kembali',
        'nik'            => 'KMB002',
        'group'          => 'reguler',
        'section'        => 'mekanik',
        'status'         => 'active',
        'is_tool_keeper' => true,
        'telegram_id'    => '77002',
    ]);

    $this->akunPeminjam = User::create([
        'name'          => 'Akun Peminjam',
        'email'         => 'kembali-peminjam@oleochemical.pro',
        'password'      => bcrypt('rahasia'),
        'role'          => 'mekanik',
        'technician_id' => $this->peminjam->id,
    ]);

    $this->akunKeeper = User::create([
        'name'          => 'Akun Keeper',
        'email'         => 'kembali-keeper@oleochemical.pro',
        'password'      => bcrypt('rahasia'),
        'role'          => 'mekanik',
        'technician_id' => $this->keeper->id,
    ]);

    config(['services.telegram.bot_token' => 'token-uji-kembali']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

test('pengajuan kembali menunggu keeper dan mengirim notifikasi tombol Terima', function () {
    $layanan  = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->peminjam),
        ToolLoan::HANDLED_SELF,
        $this->peminjam
    );

    $layanan->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $this->peminjam);
    $pinjaman->refresh();

    expect($pinjaman->return_requested_at)->not->toBeNull()
        ->and($pinjaman->returned_at)->toBeNull()
        ->and($pinjaman->menungguPenerimaan())->toBeTrue()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI)
        ->and(ToolToken::query()->where('purpose', ToolToken::PURPOSE_KEEPER_KEMBALI)->count())->toBe(1);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '77002'
        && str_contains((string) ($http['text'] ?? ''), 'Permintaan pengembalian')
        && (($http['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'alat:terima:' . $pinjaman->id)
        && str_contains((string) ($http['reply_markup']['inline_keyboard'][1][0]['url'] ?? ''), '/tools/form/'));
});

test('keeper menerima pengembalian lewat halaman Barang Kembali', function () {
    $layanan  = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->peminjam),
        ToolLoan::HANDLED_SELF,
        $this->peminjam
    );
    $layanan->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $this->peminjam);

    $this->actingAs($this->akunKeeper)
        ->get(route('alat.returns.index'))
        ->assertOk()
        ->assertSee('Bor Batrai')
        ->assertSee('BRBT001');

    $this->actingAs($this->akunKeeper)
        ->post(route('alat.returns.terima', $pinjaman->id))
        ->assertRedirect();

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '77001'
        && str_contains((string) ($http['text'] ?? ''), 'sudah dikembalikan'));
});

test('teknisi biasa tidak boleh membuka halaman Barang Kembali', function () {
    $this->actingAs($this->akunPeminjam)
        ->get(route('alat.returns.index'))
        ->assertForbidden();
});

test('admin dapat menerima pengembalian dari halaman tools', function () {
    $layanan  = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->peminjam),
        ToolLoan::HANDLED_SELF,
        $this->peminjam
    );
    $layanan->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $this->peminjam);

    $this->actingAs($this->admin)
        ->get(route('tools.returns.index'))
        ->assertOk()
        ->assertSee('BRBT001');

    $this->actingAs($this->admin)
        ->post(route('tools.returns.terima', $pinjaman->id))
        ->assertRedirect();

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);
});

test('form token keeper menampilkan pengajuan dan dapat menerimanya', function () {
    $layanan  = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->peminjam),
        ToolLoan::HANDLED_SELF,
        $this->peminjam
    );
    $layanan->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $this->peminjam);

    $asli = 'token-keeper-kembali';
    ToolToken::create([
        'purpose'       => ToolToken::PURPOSE_KEEPER_KEMBALI,
        'technician_id' => $this->keeper->id,
        'token_hash'    => hash('sha256', $asli),
        'expires_at'    => now()->addMinutes(30),
    ]);

    $this->get(route('tools.form', ['token' => $asli]))
        ->assertOk()
        ->assertSee('Menunggu Diterima')
        ->assertSee('BRBT001');

    $this->post(route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjaman->id]))
        ->assertRedirect(route('tools.form', ['token' => $asli]));

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);
});
