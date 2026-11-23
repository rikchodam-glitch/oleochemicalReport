<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pencarian laporan pintar halaman Laporan.
 *
 * Aturan yang dijaga:
 *   1. Kata kunci mencari SELURUH tanggal dan kedua tab (Reguler + Shift).
 *   2. Multi-kata berlaku AND; satu kata boleh cocok di kolom/relasi mana pun.
 *   3. Kolom baru ikut dicari: penyebab, temuan, teknisi, area, lokasi,
 *      deskripsi alat, kolaborator, dan alat multi item.
 *   4. Kode laporan persis/awalan diprioritaskan di atas kecocokan lain.
 *   5. Tanggal/rentang yang diisi eksplisit tetap dihormati.
 *   6. Klik saran (teknisi/area/alat/lokasi) membuka daftar lintas tanggal.
 *   7. Endpoint saran hanya untuk pengguna login dan minimal 2 huruf.
 */

/**
 * Buat satu laporan uji pencarian.
 *
 * @param  int                   $teknisiId  ID teknisi pemilik laporan
 * @param  string                $tanggal    Tanggal laporan (Y-m-d)
 * @param  array<string, mixed>  $kolom      Kolom tambahan/penimpa
 * @return Report
 */
function laporanPencarianUji(int $teknisiId, string $tanggal, array $kolom = []): Report
{
    return Report::create(array_merge([
        'technician_id'    => $teknisiId,
        'creator_id'       => $teknisiId,
        'report_date'      => $tanggal,
        'work_description' => 'Pekerjaan uji pencarian laporan',
        'status'           => 'completed',
    ], $kolom));
}

/**
 * Ambil angka dari kartu ringkasan halaman Laporan.
 *
 * @param  string  $html   Isi halaman
 * @param  string  $kartu  Penanda data-kartu
 * @return int
 */
function angkaKartuPencarianUji(string $html, string $kartu): int
{
    preg_match('/data-kartu="' . preg_quote($kartu, '/') . '"[^>]*>([\d.,]+)</', $html, $cocok);

    return (int) str_replace([',', '.'], '', $cocok[1] ?? '0');
}

/**
 * Posisi penanda baris laporan pada HTML halaman.
 *
 * @param  string  $html  Isi halaman
 * @param  int     $id    ID laporan
 * @return int|false
 */
function posisiLaporanPencarianUji(string $html, int $id)
{
    return strpos($html, 'data-laporan-id="' . $id . '"');
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTCRI'], ['name' => 'PT Cari']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPCRI'],
        ['name' => 'Departemen Cari']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREACRI'],
        ['name' => 'Area Cari']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTCRI-DEPCRI-AREACRI-01',
        'segment'       => '01',
        'name'          => 'Section Cari',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'area_id'       => $this->area->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->teknisiReguler = Technician::create([
        'name'   => 'Budi Reguler',
        'nik'    => 'CRIREG1',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->teknisiShift = Technician::create([
        'name'   => 'Sari Shift',
        'nik'    => 'CRISFT1',
        'group'  => 'grub_d',
        'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-CRI-001',
        'description'    => 'Pompa Transfer Cari',
        'tech_ident_no'  => 'CRI-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'status'         => 'active',
    ]);
});

test('pencarian tanpa tanggal dan tab menemukan laporan lama dari kedua grup', function () {
    $lamaReguler = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(40)->toDateString(), [
        'work_description' => 'Perbaikan kompresor unikalpha',
    ]);

    $lamaShift = laporanPencarianUji($this->teknisiShift->id, now()->subDays(70)->toDateString(), [
        'work_description' => 'Pengecekan kompresor unikalpha',
    ]);

    $hariIniLain = laporanPencarianUji($this->teknisiReguler->id, now()->toDateString(), [
        'work_description' => 'Pekerjaan lain tanpa kata kunci',
    ]);

    $respons = $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('unikalpha'));

    $respons->assertStatus(200)
        ->assertSee('data-laporan-id="' . $lamaReguler->id . '"', false)
        ->assertSee('data-laporan-id="' . $lamaShift->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $hariIniLain->id . '"', false)
        ->assertSee('seluruh tanggal', false)
        ->assertSee('id="cari-laporan"', false)
        // Pindah dari tampilan tanggal: dua grup (Reguler + Shift) sekaligus.
        ->assertSee('kedua tab (Reguler + Shift)', false);

    expect(angkaKartuPencarianUji($respons->getContent(), 'total'))->toBe(2);
});

test('pencarian multi kata mencocokkan deskripsi penyebab dan temuan', function () {
    $laporan = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(20)->toDateString(), [
        'work_description' => 'Ganti seal pompa utama',
        'root_cause'       => 'Bocor halus pada rumah seal',
        'finding'          => 'Getaran tinggi saat operasi',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('seal bocor'))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false);

    // Kata kedua dicocokkan ke kolom temuan, bukan hanya deskripsi.
    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('seal getaran'))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false);

    // Semua kata wajib cocok (AND).
    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('seal tidakada'))
        ->assertStatus(200)
        ->assertDontSee('data-laporan-id="' . $laporan->id . '"', false);

    // Kode laporan dan equipment tag tetap bisa dicari.
    $laporanKode = laporanPencarianUji($this->teknisiReguler->id, now()->subDay()->toDateString(), [
        'report_code'   => 'RPT-CARI-KODE-77',
        'equipment_tag' => 'CRI-TAG-55',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?search=RPT-CARI-KODE-77')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanKode->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?search=CRI-TAG-55')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanKode->id . '"', false);
});

test('pencarian menjangkau teknisi area lokasi alat kolaborator dan multi item', function () {
    $areaZeta = Area::create([
        'department_id' => $this->dept->id,
        'code'          => 'AREAZQ',
        'name'          => 'Area Zq',
    ]);

    $laporanTeknisi = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(15)->toDateString(), [
        'work_description' => 'Pekerjaan rutin teknisi',
    ]);

    $laporanArea = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(14)->toDateString(), [
        'work_description' => 'Pekerjaan rutin area',
        'area_id'          => $areaZeta->id,
    ]);

    $laporanLokasi = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(13)->toDateString(), [
        'work_description' => 'Pekerjaan rutin lokasi',
        'funcloc_id'       => $this->flSection->id,
    ]);

    $laporanAlat = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(12)->toDateString(), [
        'work_description' => 'Pekerjaan rutin alat',
        'asset_id'         => $this->aset->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('Budi Reguler'))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanTeknisi->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?search=AREAZQ')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanArea->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode($this->flSection->code))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanLokasi->id . '"', false);

    // Deskripsi alat tidak ada di kolom laporan; harus lewat relasi asset.
    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('Pompa Transfer Cari'))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanAlat->id . '"', false);

    // Kolaborator: laporan induk yang muncul, bukan baris salinannya.
    $indukKolaborasi = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(10)->toDateString(), [
        'work_description' => 'Pekerjaan kolaborasi shift',
    ]);

    $salinanKolaborasi = Report::create([
        'technician_id'    => $this->teknisiShift->id,
        'creator_id'       => $this->teknisiReguler->id,
        'collaborator_of'  => $indukKolaborasi->id,
        'report_date'      => now()->subDays(10)->toDateString(),
        'work_description' => 'Pekerjaan kolaborasi shift',
        'status'           => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?search=' . urlencode('Sari Shift'))
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $indukKolaborasi->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $salinanKolaborasi->id . '"', false);

    // Alat tambahan (multi item): cari alat salinan, induk yang tampil.
    $asetMulti = Asset::create([
        'equipment_no'   => 'EQ-CRI-MULTI',
        'description'    => 'Blower Multi Cari',
        'tech_ident_no'  => 'CRI-MULTI-77',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'status'         => 'active',
    ]);

    $salinanMulti = Report::create([
        'technician_id'    => $this->teknisiReguler->id,
        'creator_id'       => $this->teknisiReguler->id,
        'multi_of'         => $indukKolaborasi->id,
        'report_date'      => now()->subDays(10)->toDateString(),
        'work_description' => 'Pekerjaan kolaborasi shift',
        'asset_id'         => $asetMulti->id,
        'status'           => 'completed',
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?search=CRI-MULTI-77')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $indukKolaborasi->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $salinanMulti->id . '"', false);
});

test('hasil pencarian mendahulukan kode laporan yang cocok persis', function () {
    $kodePersis = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(30)->toDateString(), [
        'report_code'      => 'RPT-CARI-UNIK-99',
        'work_description' => 'Pekerjaan lama biasa',
    ]);

    $sebutDiDeskripsi = laporanPencarianUji($this->teknisiReguler->id, now()->subDays(1)->toDateString(), [
        'report_code'      => 'RPT-LAIN-01',
        'work_description' => 'Laporan baru yang menyebut RPT-CARI-UNIK-99 di deskripsi',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/reports?search=RPT-CARI-UNIK-99')
        ->assertStatus(200)
        ->getContent();

    $posisiKode = posisiLaporanPencarianUji($html, $kodePersis->id);
    $posisiDeskripsi = posisiLaporanPencarianUji($html, $sebutDiDeskripsi->id);

    expect($posisiKode)->not->toBeFalse()
        ->and($posisiDeskripsi)->not->toBeFalse()
        ->and($posisiKode)->toBeLessThan($posisiDeskripsi);
});

test('tanggal eksplisit tetap dihormati dan cakupan semua menampilkan seluruh tanggal', function () {
    $tanggalLama = now()->subDays(45)->toDateString();

    $laporanLama = laporanPencarianUji($this->teknisiReguler->id, $tanggalLama, [
        'work_description' => 'Perbaikan pompa tanggalunik',
    ]);

    $laporanBaru = laporanPencarianUji($this->teknisiReguler->id, now()->toDateString(), [
        'work_description' => 'Perbaikan pompa tanggalunik',
    ]);

    // Tanggal tunggal eksplisit: hanya hari itu.
    $this->actingAs($this->admin)
        ->get('/reports?search=tanggalunik&tanggal=' . $tanggalLama)
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanLama->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $laporanBaru->id . '"', false);

    // Tanpa tanggal: seluruh riwayat.
    $this->actingAs($this->admin)
        ->get('/reports?search=tanggalunik')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanLama->id . '"', false)
        ->assertSee('data-laporan-id="' . $laporanBaru->id . '"', false)
        ->assertSee('Tanggal</th>', false);

    // cakupan=semua tetap seluruh riwayat.
    $this->actingAs($this->admin)
        ->get('/reports?search=tanggalunik&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanLama->id . '"', false)
        ->assertSee('data-laporan-id="' . $laporanBaru->id . '"', false);
});

test('klik saran teknisi area alat dan lokasi membuka daftar lintas tanggal', function () {
    $tanggalLama = now()->subDays(50)->toDateString();

    $laporan = laporanPencarianUji($this->teknisiReguler->id, $tanggalLama, [
        'work_description' => 'Pekerjaan saran lintas tanggal',
        'area_id'          => $this->area->id,
        'funcloc_id'       => $this->flSection->id,
        'asset_id'         => $this->aset->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/reports?technician_id=' . $this->teknisiReguler->id . '&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?area_id=' . $this->area->id . '&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?asset_code=CRI-P-01&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false);

    $this->actingAs($this->admin)
        ->get('/reports?funcloc_id=' . $this->flSection->id . '&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporan->id . '"', false)
        // Filter lokasi tidak punya kotak di Filter Lanjutan, jadi harus ada
        // chip yang bisa dihapus tanpa membuang kata kunci.
        ->assertSee('Hapus filter lokasi', false);

    // Alat yang belum punya tech ident tetap bisa dibuka dari saran: filter
    // Kode Alat mencocokkan nomor equipment juga.
    $asetTanpaTechIdent = Asset::create([
        'equipment_no'   => 'EQ-CRI-NOTECH',
        'description'    => 'Alat Tanpa Tech Ident',
        'tech_ident_no'  => null,
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'status'         => 'active',
    ]);

    $laporanTanpaTechIdent = laporanPencarianUji(
        $this->teknisiReguler->id,
        $tanggalLama,
        [
            'work_description' => 'Pekerjaan alat tanpa tech ident',
            'asset_id'         => $asetTanpaTechIdent->id,
        ]
    );

    $this->actingAs($this->admin)
        ->get('/reports?asset_code=EQ-CRI-NOTECH&cakupan=semua')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $laporanTanpaTechIdent->id . '"', false);
});

test('endpoint saran mengembalikan kelompok laporan teknisi area alat dan lokasi', function () {
    $teknisiZeta = Technician::create([
        'name'   => 'Zeta Teknisi',
        'nik'    => 'CRIZETA1',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $areaZeta = Area::create([
        'department_id' => $this->dept->id,
        'code'          => 'AREAZETA',
        'name'          => 'Zeta Area',
    ]);

    $lokasiZeta = FunctionalLocation::create([
        'code'          => 'PTCRI-DEPCRI-AREAZETA-01',
        'segment'       => '01',
        'name'          => 'Zeta Lokasi',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'area_id'       => $areaZeta->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $asetZeta = Asset::create([
        'equipment_no'   => 'EQ-ZETA-001',
        'description'    => 'Zeta Alat',
        'tech_ident_no'  => 'CRI-ZETA-01',
        'functional_loc' => $lokasiZeta->code,
        'funcloc_id'     => $lokasiZeta->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $areaZeta->id,
        'status'         => 'active',
    ]);

    $laporan = laporanPencarianUji($teknisiZeta->id, now()->subDays(5)->toDateString(), [
        'work_description' => 'Zeta Pekerjaan',
        'area_id'          => $areaZeta->id,
        'funcloc_id'       => $lokasiZeta->id,
        'asset_id'         => $asetZeta->id,
    ]);

    $respons = $this->actingAs($this->admin)
        ->getJson('/reports/cari?q=Zeta');

    $respons->assertStatus(200)
        ->assertJsonPath('kata_kunci', 'Zeta')
        ->assertJsonStructure([
            'kata_kunci',
            'total',
            'kelompok' => [
                '*' => ['tipe', 'label', 'jumlah', 'items'],
            ],
        ]);

    $data  = $respons->json();
    $tipes = array_column($data['kelompok'], 'tipe');

    expect($data['total'])->toBeGreaterThanOrEqual(1)
        ->and($tipes)->toContain('laporan')
        ->and($tipes)->toContain('teknisi')
        ->and($tipes)->toContain('area')
        ->and($tipes)->toContain('alat')
        ->and($tipes)->toContain('lokasi');

    $kelompokLaporan = collect($data['kelompok'])->firstWhere('tipe', 'laporan');

    expect(array_column($kelompokLaporan['items'], 'id'))->toContain($laporan->id)
        ->and(count($kelompokLaporan['items']))->toBeLessThanOrEqual(4);
});

test('endpoint saran mengabaikan kata kunci kurang dari dua huruf', function () {
    $this->actingAs($this->admin)
        ->getJson('/reports/cari?q=Z')
        ->assertStatus(200)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('kelompok', []);
});

test('tamu tidak bisa memakai endpoint saran laporan', function () {
    $this->get('/reports/cari?q=pompa')
        ->assertRedirect('/login');
});

test('kotak pencarian utama menggantikan input pencarian pada filter lanjutan', function () {
    $this->actingAs($this->admin)
        ->get('/reports')
        ->assertStatus(200)
        ->assertSee('id="cari-laporan"', false)
        ->assertSee('name="cakupan" value="semua"', false)
        ->assertDontSee('Pekerjaan / Lokasi / Alat', false);
});
