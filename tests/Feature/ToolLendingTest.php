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
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Aturan transaksi peminjaman alat: status unit, larangan pinjam ganda,
 * pengembalian, riwayat, dan notifikasi Telegram.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->lokasi = ToolLocation::create(['name' => 'Workshop']);
    $this->rak    = ToolRack::create(['tool_location_id' => $this->lokasi->id, 'name' => 'Rak A']);

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Peminjam',
        'nik'         => 'PIN001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '77001',
    ]);

    $this->keeper = Technician::create([
        'name'           => 'Keeper Uji',
        'nik'            => 'PIN002',
        'group'          => 'reguler',
        'section'        => 'mekanik',
        'status'         => 'active',
        'is_tool_keeper' => true,
        'telegram_id'    => '77002',
    ]);

    $this->alat = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    config(['services.telegram.bot_token' => 'token-uji-tools']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

test('peminjaman mengunci unit dan tidak bisa dipinjam dua kali', function () {
    $layanan = app(ToolLendingService::class);

    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->teknisi),
        ToolLoan::HANDLED_KEEPER,
        $this->keeper
    );

    expect($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI)
        ->and($pinjaman->kode)->toMatch('/^PJM-\d{8}-\d{4}$/')
        ->and($pinjaman->tool_name)->toBe('Bor Batrai')
        ->and($pinjaman->tool_code)->toBe('BRBT001')
        ->and($pinjaman->peminjamLabel())->toBe('Teknisi Peminjam')
        ->and($pinjaman->masihDipinjam())->toBeTrue();

    expect(fn () => $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->teknisi),
        ToolLoan::HANDLED_KEEPER,
        $this->keeper
    ))->toThrow(ValidationException::class);

    expect(ToolLoan::query()->count())->toBe(1);
});

test('alat rusak tidak bisa dipinjam', function () {
    $this->alat->update(['status' => Tool::STATUS_RUSAK]);

    $layanan = app(ToolLendingService::class);

    expect(fn () => $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->teknisi),
        ToolLoan::HANDLED_ADMIN,
        null,
        $this->admin
    ))->toThrow(ValidationException::class);

    expect($this->alat->fresh()->status)->toBe(Tool::STATUS_RUSAK)
        ->and(ToolLoan::query()->count())->toBe(0);
});

test('pengembalian mengembalikan status tersedia dan mengirim notifikasi', function () {
    $layanan = app(ToolLendingService::class);

    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($this->teknisi),
        ToolLoan::HANDLED_KEEPER,
        $this->keeper
    );

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '77001'
        && str_contains((string) ($http['text'] ?? ''), 'tercatat dipinjam'));

    $hasil = $layanan->kembalikan($pinjaman, ToolLoan::HANDLED_ADMIN, null, $this->admin);

    expect($hasil->returned_at)->not->toBeNull()
        ->and($hasil->masihDipinjam())->toBeFalse()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '77001'
        && str_contains((string) ($http['text'] ?? ''), 'sudah dikembalikan'));

    // Pengembalian kedua tidak boleh mengubah waktu kembali.
    $waktu = $hasil->returned_at;
    $layanan->kembalikan($hasil, ToolLoan::HANDLED_ADMIN, null, $this->admin);

    expect($hasil->fresh()->returned_at->equalTo($waktu))->toBeTrue();
});

test('kembalikan semua menutup seluruh pinjaman aktif teknisi', function () {
    $alatKedua = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT002',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $layanan = app(ToolLendingService::class);

    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->pinjamkan($alatKedua, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    expect(ToolLoan::query()->aktif()->count())->toBe(2);

    $jumlah = $layanan->kembalikanSemua($this->teknisi, ToolLoan::HANDLED_SELF);

    expect($jumlah)->toBe(2)
        ->and(ToolLoan::query()->aktif()->count())->toBe(0)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA)
        ->and($alatKedua->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);
});

test('peminjaman non-teknisi menyimpan nama dan departemen pilihan', function () {
    $perusahaan = Company::create(['code' => 'TLS', 'name' => 'PT Tools Uji']);
    $departemen = Department::create([
        'company_id' => $perusahaan->id,
        'code'       => 'PRD',
        'name'       => 'Produksi',
    ]);

    $this->actingAs($this->admin)->post(route('tools.loans.store'), [
        'tool_id'                => $this->alat->id,
        'borrower_type'          => ToolLoan::BORROWER_NON_TEKNISI,
        'borrower_name'          => 'Tamu Kontraktor',
        'borrower_department_id' => $departemen->id,
        'catatan'                => 'Dipinjam untuk pekerjaan harian',
    ])->assertRedirect();

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->borrower_name)->toBe('Tamu Kontraktor')
        ->and($pinjaman->borrower_department_id)->toBe($departemen->id)
        ->and($pinjaman->borrower_department_nama)->toBe('Produksi')
        ->and($pinjaman->peminjamLabel())->toContain('Produksi')
        ->and($pinjaman->handled_by_user_id)->toBe($this->admin->id)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);
});

test('admin dapat meminjamkan dan mengembalikan dari halaman web', function () {
    $this->actingAs($this->admin)->post(route('tools.loans.store'), [
        'tool_id'       => $this->alat->id,
        'borrower_type' => ToolLoan::BORROWER_TEKNISI,
        'technician_id' => $this->teknisi->id,
    ])->assertRedirect();

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->technician_id)->toBe($this->teknisi->id);

    $this->actingAs($this->admin)->post(route('tools.loans.kembalikan', $pinjaman->id))
        ->assertRedirect();

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);
});

test('administrator dapat menunjuk dan melepas tool keeper', function () {
    $this->actingAs($this->admin)->post(route('tools.keepers.toggle', $this->keeper->id))
        ->assertRedirect();

    expect($this->keeper->fresh()->is_tool_keeper)->toBeFalse();

    $this->actingAs($this->admin)->post(route('tools.keepers.toggle', $this->keeper->id))
        ->assertRedirect();

    expect($this->keeper->fresh()->is_tool_keeper)->toBeTrue();
});

test('teknisi tanpa telegram tidak bisa dijadikan keeper', function () {
    $tanpaTelegram = Technician::create([
        'name'   => 'Tanpa Telegram',
        'nik'    => 'KPR003',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)->post(route('tools.keepers.toggle', $tanpaTelegram->id))
        ->assertSessionHasErrors('keeper');

    expect($tanpaTelegram->fresh()->is_tool_keeper)->toBeFalse();
});
