<?php

use App\Models\BagianAccess;
use App\Models\SpRequest;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('telegram_photos');

    config([
        'services.telegram.bot_token' => '',
        'telegram.photo_disk'          => 'telegram_photos',
    ]);

    $this->pemohon = Technician::create([
        'name'        => 'Teknisi Pemohon',
        'nik'         => 'REQ101',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '92001',
    ]);

    $teknisiStaff = Technician::create([
        'name'        => 'Staff CM PM',
        'nik'         => 'REQ102',
        'group'       => 'reguler_staff',
        'section'     => 'cm_pm',
        'status'      => 'active',
        'telegram_id' => '92002',
    ]);

    $teknisiStaffLain = Technician::create([
        'name'        => 'Staff Mekanik',
        'nik'         => 'REQ103',
        'group'       => 'reguler_staff',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '92003',
    ]);

    $this->staff = User::create([
        'name'          => 'Akun Staff CM PM',
        'username'      => 'staffcmpm',
        'role'          => 'cm_pm',
        'technician_id' => $teknisiStaff->id,
        'password'      => 'rahasia',
    ]);

    $this->staffLain = User::create([
        'name'          => 'Akun Staff Mekanik',
        'username'      => 'staffmekanik',
        'role'          => 'mekanik',
        'technician_id' => $teknisiStaffLain->id,
        'password'      => 'rahasia',
    ]);

    $this->reguler = User::create([
        'name'          => 'Akun Teknisi Reguler',
        'username'      => 'teknisireguler',
        'role'          => 'mekanik',
        'technician_id' => $this->pemohon->id,
        'password'      => 'rahasia',
    ]);

    BagianAccess::create([
        'bagian'      => 'cm_pm',
        'grup'        => 'reguler_staff',
        'permissions' => ['dashboard', 'sparepart', 'sparepart_request'],
    ]);

    BagianAccess::create([
        'bagian'      => 'mekanik',
        'grup'        => 'reguler_staff',
        'permissions' => ['dashboard', 'sparepart', 'sparepart_request'],
    ]);

    BagianAccess::create([
        'bagian'      => 'mekanik',
        'grup'        => 'reguler',
        'permissions' => ['dashboard', 'sparepart'],
    ]);
});

/**
 * Membuat satu request uji.
 *
 * @param  Technician            $teknisi
 * @param  array<string, mixed>  $data
 * @return SpRequest
 */
function buatSpRequestUji(Technician $teknisi, array $data = []): SpRequest
{
    return SpRequest::query()->create(array_merge([
        'kode'          => SpRequest::generateKode(),
        'technician_id' => $teknisi->id,
        'keterangan'    => 'Seal pompa bocor',
        'jumlah'        => 2,
        'satuan'        => 'pcs',
        'status'        => SpRequest::STATUS_MENUNGGU,
    ], $data));
}

test('tab request tampil untuk Staff CM/PM dan tidak tampil untuk teknisi biasa', function () {
    $this->actingAs($this->staff)
        ->get(route('sparepart.index'))
        ->assertOk()
        ->assertSee('Request Sparepart');

    $this->actingAs($this->reguler)
        ->get(route('sparepart.index'))
        ->assertOk()
        ->assertDontSee('Request Sparepart');
});

test('teknisi biasa tidak boleh membuka halaman request', function () {
    $this->actingAs($this->reguler)
        ->get(route('sparepart.request.index'))
        ->assertForbidden();
});

test('semua tab request bisa dibuka beserta data dan foto', function () {
    buatSpRequestUji($this->pemohon, [
        'foto_path' => 'sparepart-requests/uji.jpg',
    ]);

    buatSpRequestUji($this->pemohon, [
        'status' => SpRequest::STATUS_KEJAR_PO,
        'pr_no'  => 'PR-TAB-1',
        'pr_at'  => now(),
    ]);

    buatSpRequestUji($this->pemohon, [
        'status' => SpRequest::STATUS_KEJAR_DELIVERY,
        'pr_no'  => 'PR-TAB-2',
        'po_no'  => 'PO-TAB-2',
        'pr_at'  => now(),
        'po_at'  => now(),
    ]);

    buatSpRequestUji($this->pemohon, [
        'status'       => SpRequest::STATUS_SELESAI,
        'pr_no'        => 'PR-TAB-3',
        'po_no'        => 'PO-TAB-3',
        'pr_at'        => now(),
        'po_at'        => now(),
        'received_at'  => now(),
        'received_by'  => $this->staff->id,
    ]);

    foreach (['pengajuan', 'kejar-po', 'kejar-delivery', 'selesai'] as $tab) {
        $this->actingAs($this->staff)
            ->get(route('sparepart.request.index', ['tab' => $tab]))
            ->assertOk();
    }
});

test('Staff CM/PM dapat menyetujui request', function () {
    $request = buatSpRequestUji($this->pemohon);

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.setujui', $request))
        ->assertRedirect();

    $this->assertDatabaseHas('sp_requests', [
        'id'          => $request->id,
        'status'      => SpRequest::STATUS_DISETUJUI,
        'approved_by' => $this->staff->id,
    ]);
});

test('Staff CM/PM dapat menolak request dengan alasan', function () {
    $request = buatSpRequestUji($this->pemohon);

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.tolak', $request), ['alasan' => 'Stok masih ada'])
        ->assertRedirect();

    $this->assertDatabaseHas('sp_requests', [
        'id'           => $request->id,
        'status'       => SpRequest::STATUS_DITOLAK,
        'alasan_tolak' => 'Stok masih ada',
        'rejected_by'  => $this->staff->id,
    ]);
});

test('alur PR, PO, dan barang diterima memindahkan request sampai selesai', function () {
    $request = buatSpRequestUji($this->pemohon, [
        'status' => SpRequest::STATUS_DISETUJUI,
    ]);

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.pr', $request), ['pr_no' => '1000004103'])
        ->assertRedirect(route('sparepart.request.index', ['tab' => 'kejar-po']));

    $this->assertDatabaseHas('sp_requests', [
        'id'     => $request->id,
        'status' => SpRequest::STATUS_KEJAR_PO,
        'pr_no'  => '1000004103',
    ]);

    $request->refresh();

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.po', $request), ['po_no' => '4500001234'])
        ->assertRedirect(route('sparepart.request.index', ['tab' => 'kejar-delivery']));

    $this->assertDatabaseHas('sp_requests', [
        'id'     => $request->id,
        'status' => SpRequest::STATUS_KEJAR_DELIVERY,
        'po_no'  => '4500001234',
    ]);

    $request->refresh();

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.terima', $request))
        ->assertRedirect(route('sparepart.request.index', ['tab' => 'selesai']));

    $this->assertDatabaseHas('sp_requests', [
        'id'          => $request->id,
        'status'      => SpRequest::STATUS_SELESAI,
        'received_by' => $this->staff->id,
    ]);
});

test('Reguler Staff dari Bagian lain tidak boleh menyetujui request', function () {
    $request = buatSpRequestUji($this->pemohon);

    $this->actingAs($this->staffLain)
        ->post(route('sparepart.request.setujui', $request))
        ->assertForbidden();

    $this->assertDatabaseHas('sp_requests', [
        'id'     => $request->id,
        'status' => SpRequest::STATUS_MENUNGGU,
    ]);
});

test('tab Kejar PO menampilkan tabel PR menunggu PO yang bisa di-collapse', function () {
    buatSpRequestUji($this->pemohon, [
        'status' => SpRequest::STATUS_KEJAR_PO,
        'pr_no'  => '1000009999',
        'pr_at'  => now(),
    ]);

    $this->actingAs($this->staff)
        ->get(route('sparepart.request.index', ['tab' => 'kejar-po']))
        ->assertOk()
        ->assertSee('Daftar PR Menunggu PO')
        ->assertSee('1000009999')
        ->assertSee('Simpan PO')
        ->assertSee('<details', false);
});

test('nomor PR wajib diisi dan status tidak berubah bila kosong', function () {
    $request = buatSpRequestUji($this->pemohon, [
        'status' => SpRequest::STATUS_DISETUJUI,
    ]);

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.pr', $request), [])
        ->assertSessionHasErrors('pr_no');

    $this->assertDatabaseHas('sp_requests', [
        'id'     => $request->id,
        'status' => SpRequest::STATUS_DISETUJUI,
    ]);
});

test('notifikasi status disetujui dikirim ke teknisi pemohon', function () {
    config(['services.telegram.bot_token' => 'token-uji-web']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $request = buatSpRequestUji($this->pemohon);

    $this->actingAs($this->staff)
        ->post(route('sparepart.request.setujui', $request))
        ->assertRedirect();

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '92001'
        && str_contains((string) ($http['text'] ?? ''), $request->kode)
        && str_contains((string) ($http['text'] ?? ''), 'disetujui'));
});
