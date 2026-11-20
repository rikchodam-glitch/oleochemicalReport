<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolToken;
use App\Services\ToolLendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Form token publik Management Tools: pinjam dan kembali untuk keeper/teknisi.
 */
beforeEach(function () {
    $this->seed();

    $this->lokasi = ToolLocation::create(['name' => 'Workshop']);
    $this->rak    = ToolRack::create(['tool_location_id' => $this->lokasi->id, 'name' => 'Rak A']);

    $this->alat = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->alatKedua = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT002',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Form',
        'nik'         => 'FRM001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '66001',
    ]);

    $this->keeper = Technician::create([
        'name'           => 'Keeper Form',
        'nik'            => 'FRM002',
        'group'          => 'reguler',
        'section'        => 'mekanik',
        'status'         => 'active',
        'is_tool_keeper' => true,
        'telegram_id'    => '66002',
    ]);

    $this->perusahaan = Company::create(['code' => 'TFM', 'name' => 'PT Form Uji']);
    $this->departemen = Department::create([
        'company_id' => $this->perusahaan->id,
        'code'       => 'PRD',
        'name'       => 'Produksi',
    ]);

    config(['services.telegram.bot_token' => 'token-uji-form']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

/**
 * Buat token uji dengan token asli yang diketahui.
 *
 * @return array{0: ToolToken, 1: string}
 */
function buatTokenAlat(Technician $teknisi, string $purpose, ?int $prefill = null, string $asli = 'token-alat-uji'): array
{
    $token = ToolToken::create([
        'purpose'         => $purpose,
        'technician_id'   => $teknisi->id,
        'token_hash'      => hash('sha256', $asli),
        'prefill_tool_id' => $prefill,
        'expires_at'      => now()->addMinutes(30),
    ]);

    return [$token, $asli];
}

test('tautan tidak valid atau kedaluwarsa ditolak', function () {
    $this->get(route('tools.form', ['token' => 'tidak-ada']))->assertStatus(410);

    [$token, $asli] = buatTokenAlat($this->teknisi, ToolToken::PURPOSE_TEKNISI_PINJAM);
    $token->update(['expires_at' => now()->subMinute()]);

    $this->get(route('tools.form', ['token' => $asli]))->assertStatus(410);
});

test('teknisi meminjam lewat token sekali pakai', function () {
    [$token, $asli] = buatTokenAlat($this->teknisi, ToolToken::PURPOSE_TEKNISI_PINJAM, $this->alat->id);

    $this->get(route('tools.form', ['token' => $asli]))
        ->assertOk()
        ->assertSee('Form Peminjaman Alat')
        ->assertSee('Bor Batrai');

    $this->post(route('tools.form.pinjam', ['token' => $asli]), [
        'tool_id'       => $this->alat->id,
        'technician_id' => $this->keeper->id,
    ])->assertRedirect(route('tools.form.selesai'))
        ->assertSessionHas('hasil');

    $pinjaman = ToolLoan::firstOrFail();

    expect($pinjaman->technician_id)->toBe($this->teknisi->id)
        ->and($pinjaman->borrower_name)->toBe('Teknisi Form')
        ->and($pinjaman->handled_by_type)->toBe(ToolLoan::HANDLED_SELF)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI)
        ->and($token->fresh()->used_at)->not->toBeNull();

    // Token sekali pakai tidak boleh dibuka lagi.
    $this->get(route('tools.form', ['token' => $asli]))->assertStatus(410);
});

test('token keeper bisa dipakai beberapa peminjaman sampai kedaluwarsa', function () {
    [$token, $asli] = buatTokenAlat($this->keeper, ToolToken::PURPOSE_KEEPER_PINJAM);

    $this->post(route('tools.form.pinjam', ['token' => $asli]), [
        'tool_id'       => $this->alat->id,
        'borrower_type' => ToolLoan::BORROWER_TEKNISI,
        'technician_id' => $this->teknisi->id,
    ])->assertRedirect(route('tools.form', ['token' => $asli]));

    $this->post(route('tools.form.pinjam', ['token' => $asli]), [
        'tool_id'                => $this->alatKedua->id,
        'borrower_type'          => ToolLoan::BORROWER_NON_TEKNISI,
        'borrower_name'          => 'Tamu Pabrik',
        'borrower_department_id' => $this->departemen->id,
    ])->assertRedirect(route('tools.form', ['token' => $asli]));

    expect(ToolLoan::query()->count())->toBe(2)
        ->and($token->fresh()->used_at)->toBeNull()
        ->and(ToolLoan::query()->where('borrower_type', ToolLoan::BORROWER_NON_TEKNISI)->firstOrFail()->borrower_department_nama)->toBe('Produksi');

    $this->get(route('tools.form', ['token' => $asli]))->assertOk();

    // Unit yang sudah dipinjam tidak bisa dipinjam lagi lewat token yang sama.
    $this->post(route('tools.form.pinjam', ['token' => $asli]), [
        'tool_id'       => $this->alat->id,
        'borrower_type' => ToolLoan::BORROWER_TEKNISI,
        'technician_id' => $this->teknisi->id,
    ])->assertSessionHasErrors('tool_id');

    expect(ToolLoan::query()->count())->toBe(2);
});

test('token keeper tidak berlaku lagi setelah status keeper dicabut', function () {
    [$token, $asli] = buatTokenAlat($this->keeper, ToolToken::PURPOSE_KEEPER_PINJAM);

    $this->keeper->update(['is_tool_keeper' => false]);

    $this->get(route('tools.form', ['token' => $asli]))->assertStatus(410);
});

test('teknisi mengembalikan satu alat lewat token dan token hangus', function () {
    $layanan = app(ToolLendingService::class);
    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->pinjamkan($this->alatKedua, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    $pinjaman = ToolLoan::query()->where('tool_id', $this->alat->id)->firstOrFail();

    [$token, $asli] = buatTokenAlat($this->teknisi, ToolToken::PURPOSE_TEKNISI_KEMBALI);

    $this->get(route('tools.form', ['token' => $asli]))
        ->assertOk()
        ->assertSee('Form Pengembalian Alat')
        ->assertSee('BRBT001')
        ->assertSee('BRBT002');

    $this->post(route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjaman->id]))
        ->assertRedirect(route('tools.form.selesai'));

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA)
        ->and($token->fresh()->used_at)->not->toBeNull();

    $this->get(route('tools.form', ['token' => $asli]))->assertStatus(410);
});

test('teknisi mengembalikan semua alat lewat token', function () {
    $layanan = app(ToolLendingService::class);
    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->pinjamkan($this->alatKedua, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    [$token, $asli] = buatTokenAlat($this->teknisi, ToolToken::PURPOSE_TEKNISI_KEMBALI);

    $this->post(route('tools.form.kembali-semua', ['token' => $asli]))
        ->assertRedirect(route('tools.form.selesai'))
        ->assertSessionHas('hasil');

    expect(ToolLoan::query()->aktif()->count())->toBe(0)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA)
        ->and($this->alatKedua->fresh()->status)->toBe(Tool::STATUS_TERSEDIA)
        ->and($token->fresh()->used_at)->not->toBeNull();
});

test('teknisi tidak boleh mengembalikan pinjaman teknisi lain', function () {
    $teknisiLain = Technician::create([
        'name'   => 'Teknisi Lain',
        'nik'    => 'FRM003',
        'status' => 'active',
    ]);

    $layanan = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan(
        $this->alat,
        $layanan->dataPeminjamTeknisi($teknisiLain),
        ToolLoan::HANDLED_ADMIN,
        null,
        \App\Models\User::where('email', 'admin@oleochemical.pro')->firstOrFail()
    );

    [, $asli] = buatTokenAlat($this->teknisi, ToolToken::PURPOSE_TEKNISI_KEMBALI);

    $this->post(route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjaman->id]))
        ->assertForbidden();

    expect($pinjaman->fresh()->returned_at)->toBeNull();
});

test('token keeper mengembalikan alat milik peminjam mana pun', function () {
    $teknisiLain = Technician::create([
        'name'   => 'Teknisi Kedua',
        'nik'    => 'FRM004',
        'status' => 'active',
    ]);

    $layanan = app(ToolLendingService::class);
    $pinjamanA = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $pinjamanB = $layanan->pinjamkan($this->alatKedua, $layanan->dataPeminjamTeknisi($teknisiLain), ToolLoan::HANDLED_KEEPER, $this->keeper);

    [$token, $asli] = buatTokenAlat($this->keeper, ToolToken::PURPOSE_KEEPER_KEMBALI);

    $this->get(route('tools.form', ['token' => $asli]))
        ->assertOk()
        ->assertSee('Teknisi Form')
        ->assertSee('Teknisi Kedua');

    $this->post(route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjamanA->id]))
        ->assertRedirect(route('tools.form', ['token' => $asli]));

    $this->post(route('tools.form.kembali', ['token' => $asli, 'toolLoan' => $pinjamanB->id]))
        ->assertRedirect(route('tools.form', ['token' => $asli]));

    expect($pinjamanA->fresh()->returned_at)->not->toBeNull()
        ->and($pinjamanB->fresh()->returned_at)->not->toBeNull()
        ->and($token->fresh()->used_at)->toBeNull();
});
