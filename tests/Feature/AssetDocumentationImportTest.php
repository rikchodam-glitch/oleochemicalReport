<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('public');

    $this->folderUji = storage_path('framework/testing/asset-docs-' . uniqid());
    $this->sumber    = $this->folderUji . '/sumber';
    $this->laporan   = $this->folderUji . '/laporan.csv';

    File::ensureDirectoryExists($this->sumber);

    $perusahaan = Company::first() ?? Company::create(['code' => 'DOC', 'name' => 'PT Dokumentasi Uji']);

    $this->funcLoc = FunctionalLocation::create([
        'code'       => 'DOC-PT',
        'segment'    => 'DOC-PT',
        'name'       => 'PT Dokumentasi Uji',
        'level'      => 0,
        'company_id' => $perusahaan->id,
        'is_active'  => true,
    ]);

    $buatAset = function (string $tech, ?string $equip = null) use ($perusahaan): Asset {
        return Asset::create([
            'tech_ident_no'  => $tech,
            'equipment_no'   => $equip,
            'description'    => 'Aset dokumentasi ' . $tech,
            'equipment_type' => 'PUMP',
            'funcloc_id'     => $this->funcLoc->id,
            'company_id'     => $perusahaan->id,
            'status'         => 'active',
            'data_source'    => 'manual',
        ]);
    };

    $this->asetFolder    = $buatAset('FOLDER-TAG');
    $this->asetFile      = $buatAset('FILE-TAG');
    $this->asetCompact   = $buatAset('COMPACT-TAG');
    $this->asetSmart     = $buatAset('SMART-1234');
    $this->asetEquipment = $buatAset('EQUIP-TAG', 'EQ-123');
    $this->asetExisting  = $buatAset('EXISTING-TAG');
    $this->asetDuplikat1 = $buatAset('DUP-TAG');
    $this->asetDuplikat2 = $buatAset('DUP-TAG');

    $fotoLamaSumber = $this->folderUji . '/lama.jpg';
    buatGambarDokumentasi($fotoLamaSumber);

    $this->fotoLama = 'assets/photos/' . $this->asetExisting->id . '/lama.jpg';
    Storage::disk('public')->put($this->fotoLama, File::get($fotoLamaSumber));
    $this->asetExisting->update(['photos' => [$this->fotoLama]]);

    buatGambarDokumentasi($this->sumber . '/FOLDER-TAG/foto-folder.jpg');
    File::copy(
        $this->sumber . '/FOLDER-TAG/foto-folder.jpg',
        $this->sumber . '/FOLDER-TAG/foto-folder-copy.jpg'
    );

    buatGambarDokumentasi($this->sumber . '/FILE-TAG.jpg');
    buatGambarDokumentasi($this->sumber . '/COMPACT TAG.jpg');
    buatGambarDokumentasi($this->sumber . '/PHE SMART-1234 (2).jpg');
    buatGambarDokumentasi($this->sumber . '/EXISTING-TAG.jpg');
    buatGambarDokumentasi($this->sumber . '/EQ-123/foto-equipment.jpg');
    buatGambarDokumentasi($this->sumber . '/DUP-TAG.jpg');
    buatGambarDokumentasi($this->sumber . '/tidak-cocok.jpg');

    File::put($this->sumber . '/kosong.jpg', '');
    File::put($this->sumber . '/rusak.jpg', 'bukan gambar');
    File::put($this->sumber . '/catatan.xlsx', 'bukan gambar');
    File::put($this->sumber . '/foto.heic', 'bukan gambar');
});

afterEach(function () {
    File::deleteDirectory($this->folderUji);
});

/**
 * Membuat gambar uji kecil dengan isi unik berdasarkan path.
 *
 * @param  string $path
 * @param  string $ext
 * @return void
 */
function buatGambarDokumentasi(string $path, string $ext = 'jpg'): void
{
    File::ensureDirectoryExists(dirname($path));

    $gambar = imagecreatetruecolor(4, 4);
    $nilai  = crc32($path);
    $warna  = imagecolorallocate($gambar, $nilai % 256, ($nilai >> 8) % 256, ($nilai >> 16) % 256);
    imagefill($gambar, 0, 0, $warna);

    if (strtolower($ext) === 'png') {
        imagepng($gambar, $path);
    } else {
        imagejpeg($gambar, $path);
    }

    imagedestroy($gambar);
}

test('dry-run tidak mengubah foto maupun database dan tetap membuat laporan', function () {
    $filesSebelum = Storage::disk('public')->allFiles();

    $this->artisan('assets:import-dokumentasi', [
        '--path'   => $this->sumber,
        '--report' => $this->laporan,
    ])->assertSuccessful();

    $filesSesudah = Storage::disk('public')->allFiles();
    sort($filesSebelum);
    sort($filesSesudah);

    expect($filesSesudah)->toBe($filesSebelum)
        ->and(File::exists($this->laporan))->toBeTrue()
        ->and(File::exists(preg_replace('/\.csv$/i', '', $this->laporan) . '.json'))->toBeTrue();

    $csv = File::get($this->laporan);

    expect($csv)->toContain('belum_cocok')
        ->toContain('ambigu')
        ->toContain('dilewati_kosong')
        ->toContain('dilewati_rusak');

    expect($this->asetFolder->refresh()->photos)->toBeNull()
        ->and($this->asetFile->refresh()->photos)->toBeNull();
});

test('apply mencocokkan folder, nama file, compact, smart, equipment, dan menambahkan foto lama', function () {
    $this->artisan('assets:import-dokumentasi', [
        '--path'   => $this->sumber,
        '--report' => $this->laporan,
        '--apply'  => true,
    ])->assertSuccessful();

    $this->asetFolder->refresh();
    $this->asetFile->refresh();
    $this->asetCompact->refresh();
    $this->asetSmart->refresh();
    $this->asetEquipment->refresh();
    $this->asetExisting->refresh();
    $this->asetDuplikat1->refresh();
    $this->asetDuplikat2->refresh();

    expect($this->asetFolder->photos)->toHaveCount(1)
        ->and($this->asetFile->photos)->toHaveCount(1)
        ->and($this->asetCompact->photos)->toHaveCount(1)
        ->and($this->asetSmart->photos)->toHaveCount(1)
        ->and($this->asetEquipment->photos)->toHaveCount(1)
        ->and($this->asetExisting->photos)->toHaveCount(2)
        ->and($this->asetExisting->photos)->toContain($this->fotoLama)
        ->and($this->asetDuplikat1->photos ?? [])->toBe([])
        ->and($this->asetDuplikat2->photos ?? [])->toBe([]);

    foreach ([$this->asetFolder, $this->asetFile, $this->asetCompact, $this->asetSmart, $this->asetEquipment] as $aset) {
        $path = $aset->photos[0];

        expect($path)->toMatch('#^assets/photos/' . $aset->id . '/[a-f0-9]{64}\.jpg$#')
            ->and(Storage::disk('public')->exists($path))->toBeTrue();
    }

    expect(Storage::disk('public')->allFiles())->toHaveCount(7);

    $json = json_decode(File::get(preg_replace('/\.csv$/i', '', $this->laporan) . '.json'), true);
    expect($json['mode'])->toBe('apply')
        ->and($json['ringkasan']['cocok'])->toBe(6)
        ->and($json['ringkasan']['ambigu'])->toBe(1)
        ->and($json['ringkasan']['duplikat'])->toBe(1)
        ->and($json['ringkasan']['foto_ditambahkan'])->toBe(6);

    $backup = File::glob($this->folderUji . '/backup-fotos-*.json');
    expect($backup)->toHaveCount(1);

    $backupData = json_decode(File::get($backup[0]), true);
    expect(collect($backupData['aset'])->firstWhere('id', $this->asetExisting->id)['photos'])->toContain($this->fotoLama);
});

test('apply dua kali tidak menggandakan foto', function () {
    $opsi = [
        '--path'   => $this->sumber,
        '--report' => $this->laporan,
        '--apply'  => true,
    ];

    $this->artisan('assets:import-dokumentasi', $opsi)->assertSuccessful();

    $jumlahPertama = $this->asetFolder->refresh()->photos;
    $filesPertama  = Storage::disk('public')->allFiles();
    sort($filesPertama);

    $this->artisan('assets:import-dokumentasi', $opsi)->assertSuccessful();

    $filesKedua = Storage::disk('public')->allFiles();
    sort($filesKedua);

    expect($this->asetFolder->refresh()->photos)->toBe($jumlahPertama)
        ->and($filesKedua)->toBe($filesPertama);
});

test('opsi tag hanya memproses aset yang dipilih', function () {
    $this->artisan('assets:import-dokumentasi', [
        '--path'   => $this->sumber,
        '--report' => $this->laporan,
        '--tag'    => 'FILE-TAG',
        '--apply'  => true,
    ])->assertSuccessful();

    expect($this->asetFile->refresh()->photos)->toHaveCount(1)
        ->and($this->asetFolder->refresh()->photos)->toBeNull()
        ->and($this->asetSmart->refresh()->photos)->toBeNull();
});
