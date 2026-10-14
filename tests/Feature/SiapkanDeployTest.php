<?php

/**
 * Uji alat kerja penyiapan deploy:
 *   - tools/siapkan-deploy.ps1 (memindahkan sementara berkas yang menghalangi
 *     fast-forward, memajukan copy DEV, lalu mengembalikan perubahannya),
 *   - pesan tools/penjaga-main-dev.ps1 dan tools/kembalikan-ke-main.ps1 saat
 *     fast-forward terhalang berkas yang belum di-commit.
 *
 * Seluruh repo git uji dibuat di folder sementara; copy DEV dan copy PROD yang
 * sebenarnya TIDAK pernah disentuh.
 */

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Alat kerja ini hanya dipakai di Windows.');
    }

    foreach ([['git', '--version'], ['powershell.exe', '-NoProfile', '-Command', 'exit 0']] as $perintah) {
        $proses = new Process($perintah);
        $proses->run();

        if (! $proses->isSuccessful()) {
            $this->markTestSkipped('Perintah ' . $perintah[0] . ' tidak tersedia di komputer ini.');
        }
    }
});

afterEach(function () {
    // Folder uji selalu memakai awalan ini, jadi sisa dari run sebelumnya pun
    // ikut terbersihkan.
    foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oleo-alat-uji-*') ?: [] as $folder) {
        File::deleteDirectory($folder);
    }
});

/**
 * Membuat repo uji: `remote.git` (bare, berperan sebagai GitHub) dan `dev`
 * (copy DEV tiruan) yang sudah punya satu commit di cabang main.
 *
 * @return array{akar: string, remote: string, dev: string}
 */
function sdRepoUji(): array
{
    $akar   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oleo-alat-uji-' . bin2hex(random_bytes(4));
    $remote = $akar . DIRECTORY_SEPARATOR . 'remote.git';
    $dev    = $akar . DIRECTORY_SEPARATOR . 'dev';

    mkdir($akar, 0777, true);

    // Bare repo sebagai "GitHub"; HEAD-nya diarahkan ke main supaya clone
    // berikutnya langsung berada di cabang yang benar.
    sdGit(null, ['init', '--quiet', '--bare', $remote]);
    sdGit(null, ['-C', $remote, 'symbolic-ref', 'HEAD', 'refs/heads/main']);

    sdGit(null, ['init', '--quiet', '-b', 'main', $dev]);
    sdGit($dev, ['config', 'user.email', 'uji@contoh.test']);
    sdGit($dev, ['config', 'user.name', 'Pengujian']);
    sdGit($dev, ['config', 'core.autocrlf', 'false']);
    sdTulis($dev . DIRECTORY_SEPARATOR . 'catatan.txt', "baris awal\n");
    sdGit($dev, ['add', 'catatan.txt']);
    sdGit($dev, ['commit', '--quiet', '-m', 'commit awal']);
    sdGit($dev, ['remote', 'add', 'origin', $remote]);
    sdGit($dev, ['push', '--quiet', '-u', 'origin', 'main']);

    return ['akar' => $akar, 'remote' => $remote, 'dev' => $dev];
}

/**
 * Menambah satu commit di cabang utama remote (meniru Pull Request yang baru
 * di-merge) dengan menambahi satu baris ke catatan.txt.
 *
 * @param  array{akar: string, remote: string, dev: string} $repo      Repo uji
 * @param  string                                           $barisBaru Baris yang ditambahkan
 * @return string                                                      Hash commit remote yang baru
 */
function sdCommitRemote(array $repo, string $barisBaru): string
{
    $lain = $repo['akar'] . DIRECTORY_SEPARATOR . 'lain';

    sdGit(null, ['clone', '--quiet', $repo['remote'], $lain]);
    sdGit($lain, ['config', 'user.email', 'lain@contoh.test']);
    sdGit($lain, ['config', 'user.name', 'Teknisi Lain']);
    sdGit($lain, ['config', 'core.autocrlf', 'false']);

    $berkas = $lain . DIRECTORY_SEPARATOR . 'catatan.txt';
    sdTulis($berkas, file_get_contents($berkas) . $barisBaru . "\n");

    sdGit($lain, ['add', 'catatan.txt']);
    sdGit($lain, ['commit', '--quiet', '-m', 'perubahan dari remote']);
    sdGit($lain, ['push', '--quiet', 'origin', 'main']);

    return trim(sdGit($repo['remote'], ['rev-parse', 'refs/heads/main']));
}

/**
 * Menjalankan perintah git di repo tertentu. Kegagalan git dilempar sebagai
 * exception supaya tidak lolos diam-diam.
 *
 * @param  string|null        $di      Repo kerja (null = tanpa opsi -C)
 * @param  array<int, string> $argumen Argumen git
 * @return string                      Keluaran stdout git
 */
function sdGit(?string $di, array $argumen): string
{
    $perintah = ['git'];

    if ($di !== null) {
        $perintah[] = '-C';
        $perintah[] = $di;
    }

    $proses = new Process(array_merge($perintah, $argumen));
    $proses->setTimeout(120);
    $proses->run();

    if (! $proses->isSuccessful()) {
        throw new RuntimeException('git gagal: ' . $proses->getCommandLine() . ' -> ' . $proses->getErrorOutput());
    }

    return $proses->getOutput();
}

/**
 * Menulis berkas uji apa adanya (keluaran baris baru tidak diubah).
 *
 * @param  string $path Path berkas
 * @param  string $isi  Isi berkas
 * @return void
 */
function sdTulis(string $path, string $isi): void
{
    file_put_contents($path, $isi);
}

/**
 * Menambahi catatan.txt di repo DEV tiruan TANPA commit (meniru pekerjaan sesi
 * lain yang belum di-commit).
 *
 * @param  string $dev   Path repo DEV tiruan
 * @param  string $baris Baris yang ditambahkan
 * @return void
 */
function sdUbahLokal(string $dev, string $baris): void
{
    $berkas = $dev . DIRECTORY_SEPARATOR . 'catatan.txt';
    sdTulis($berkas, file_get_contents($berkas) . $baris . "\n");
}

/**
 * Menjalankan skrip alat kerja lewat PowerShell dan mengembalikan exit code
 * beserta seluruh keluarannya.
 *
 * @param  string             $nama    Nama berkas skrip di folder tools
 * @param  array<int, string> $argumen Argumen skrip
 * @return array{kode: int, keluaran: string}
 */
function sdJalankan(string $nama, array $argumen): array
{
    $skrip = base_path('tools' . DIRECTORY_SEPARATOR . $nama);

    $proses = new Process(array_merge(
        ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $skrip],
        $argumen
    ));
    $proses->setTimeout(240);
    $proses->run();

    return [
        'kode'     => (int) $proses->getExitCode(),
        'keluaran' => $proses->getOutput() . $proses->getErrorOutput(),
    ];
}

/**
 * Menyiapkan repo uji pada keadaan yang memicu kasus nyata 14/10/2026: cabang
 * utama remote sudah maju (Pull Request di-merge) sedangkan copy DEV tertinggal
 * DAN punya perubahan belum di-commit pada berkas yang sama.
 *
 * @param  string $tanda Tanda unik supaya tiap uji memakai nama berbeda
 * @return array{akar: string, remote: string, dev: string, komit_remote: string, cadangan: string}
 */
function sdKasusBerkasPenghalang(string $tanda): array
{
    $repo        = sdRepoUji();
    $komitRemote = sdCommitRemote($repo, 'BLOK-DARI-REMOTE-' . $tanda);

    sdUbahLokal($repo['dev'], 'BLOK-DARI-SESI-LAIN-' . $tanda);

    return $repo + [
        'komit_remote' => $komitRemote,
        'cadangan'     => $repo['akar'] . DIRECTORY_SEPARATOR . 'cadangan',
    ];
}

test('siapkan-deploy tidak mengubah apa pun bila copy DEV sudah sejajar', function () {
    $repo    = sdRepoUji();
    $sebelum = trim(sdGit($repo['dev'], ['rev-parse', 'HEAD']));
    $cadang  = $repo['akar'] . DIRECTORY_SEPARATOR . 'cadangan';

    $hasil = sdJalankan('siapkan-deploy.ps1', ['-AkarRepo', $repo['dev'], '-FolderCadangan', $cadang]);

    expect($hasil['kode'])->toBe(0);
    expect($hasil['keluaran'])->toContain('SUDAH SEJAJAR');
    expect($hasil['keluaran'])->toContain('SIAPKAN_DEPLOY=SIAP');
    expect(trim(sdGit($repo['dev'], ['rev-parse', 'HEAD'])))->toBe($sebelum);
    expect(is_dir($cadang))->toBeFalse();
});

test('siapkan-deploy memajukan copy DEV yang tertinggal tanpa menyentuh berkas siapa pun', function () {
    $repo          = sdRepoUji();
    $komitRemote   = sdCommitRemote($repo, 'baris dari remote');
    $belumTerlacak = $repo['dev'] . DIRECTORY_SEPARATOR . 'berkas-baru.txt';

    sdTulis($belumTerlacak, "berkas belum terlacak\n");

    $hasil = sdJalankan('siapkan-deploy.ps1', [
        '-AkarRepo', $repo['dev'],
        '-FolderCadangan', $repo['akar'] . DIRECTORY_SEPARATOR . 'cadangan',
    ]);

    expect($hasil['kode'])->toBe(0);
    expect($hasil['keluaran'])->toContain('BERHASIL');
    expect($hasil['keluaran'])->toContain('tanpa berkas penghalang');
    expect(trim(sdGit($repo['dev'], ['rev-parse', 'HEAD'])))->toBe($komitRemote);
    expect(file_get_contents($belumTerlacak))->toBe("berkas belum terlacak\n");
});

test('siapkan-deploy memindahkan berkas penghalang lalu menggabungkan kedua sisi', function () {
    $repo  = sdKasusBerkasPenghalang('tiga');
    $hasil = sdJalankan('siapkan-deploy.ps1', [
        '-AkarRepo', $repo['dev'],
        '-FolderCadangan', $repo['cadangan'],
    ]);

    expect($hasil['kode'])->toBe(0);
    expect($hasil['keluaran'])->toContain('catatan.txt');
    expect($hasil['keluaran'])->toContain('GABUNG_BENTROK=ya');
    expect($hasil['keluaran'])->toContain('SIAPKAN_DEPLOY=SIAP');

    $isi = file_get_contents($repo['dev'] . DIRECTORY_SEPARATOR . 'catatan.txt');

    // KEDUA sisi dipertahankan dan tidak ada sisa penanda bentrok.
    expect($isi)->toContain('BLOK-DARI-REMOTE-tiga');
    expect($isi)->toContain('BLOK-DARI-SESI-LAIN-tiga');
    expect($isi)->not->toContain('<<<<<<<');

    // Perubahan sesi lain tetap BELUM di-commit: tanpa commit baru dan tanpa
    // perubahan yang tertinggal di staging.
    expect(trim(sdGit($repo['dev'], ['rev-parse', 'HEAD'])))->toBe($repo['komit_remote']);
    expect(trim(sdGit($repo['dev'], ['rev-list', '--count', 'HEAD'])))->toBe('2');
    expect(sdGit($repo['dev'], ['status', '--porcelain']))->toContain('catatan.txt');
    expect(trim(sdGit($repo['dev'], ['diff', '--cached', '--name-only'])))->toBe('');
    expect(trim(sdGit($repo['dev'], ['stash', 'list'])))->toBe('');

    // Cadangan di luar repo dibuat sebagai jaring pengaman.
    expect(glob($repo['cadangan'] . DIRECTORY_SEPARATOR . 'dev-*' . DIRECTORY_SEPARATOR . 'catatan.txt') ?: [])->not->toBeEmpty();
    expect(glob($repo['cadangan'] . DIRECTORY_SEPARATOR . 'dev-*' . DIRECTORY_SEPARATOR . 'CATATAN.txt') ?: [])->not->toBeEmpty();
});

test('siapkan-deploy dengan -Kering hanya melaporkan, tidak mengubah apa pun', function () {
    $repo    = sdKasusBerkasPenghalang('kering');
    $sebelum = trim(sdGit($repo['dev'], ['rev-parse', 'HEAD']));
    $isiAwal = file_get_contents($repo['dev'] . DIRECTORY_SEPARATOR . 'catatan.txt');

    $hasil = sdJalankan('siapkan-deploy.ps1', [
        '-AkarRepo', $repo['dev'],
        '-FolderCadangan', $repo['cadangan'],
        '-Kering',
    ]);

    expect($hasil['kode'])->toBe(1);
    expect($hasil['keluaran'])->toContain('SIMULASI');
    expect($hasil['keluaran'])->toContain('catatan.txt');
    expect(trim(sdGit($repo['dev'], ['rev-parse', 'HEAD'])))->toBe($sebelum);
    expect(file_get_contents($repo['dev'] . DIRECTORY_SEPARATOR . 'catatan.txt'))->toBe($isiAwal);
    expect(trim(sdGit($repo['dev'], ['stash', 'list'])))->toBe('');
    expect(is_dir($repo['cadangan']))->toBeFalse();
});

test('siapkan-deploy dengan -JanganGabung berhenti dan membiarkan penanda bentrok', function () {
    $repo  = sdKasusBerkasPenghalang('jangan-gabung');
    $hasil = sdJalankan('siapkan-deploy.ps1', [
        '-AkarRepo', $repo['dev'],
        '-FolderCadangan', $repo['cadangan'],
        '-JanganGabung',
    ]);

    expect($hasil['kode'])->toBe(1);
    expect($hasil['keluaran'])->toContain('GABUNG_BENTROK=tidak');
    expect($hasil['keluaran'])->toContain('SIAPKAN_DEPLOY=BELUM');

    $isi = file_get_contents($repo['dev'] . DIRECTORY_SEPARATOR . 'catatan.txt');

    expect($isi)->toContain('<<<<<<<');

    // Pekerjaan sesi lain AMAN: ada di git stash dan di folder cadangan.
    expect(sdGit($repo['dev'], ['stash', 'list']))->toContain('siapkan-deploy');
    expect(glob($repo['cadangan'] . DIRECTORY_SEPARATOR . 'dev-*' . DIRECTORY_SEPARATOR . 'catatan.txt') ?: [])->not->toBeEmpty();
});

test('siapkan-deploy berhenti bila cabang utama DEV punya commit sendiri', function () {
    $repo = sdRepoUji();

    sdUbahLokal($repo['dev'], 'commit lokal belum lewat Pull Request');
    sdGit($repo['dev'], ['add', 'catatan.txt']);
    sdGit($repo['dev'], ['commit', '--quiet', '-m', 'commit lokal']);

    $sebelum = trim(sdGit($repo['dev'], ['rev-parse', 'HEAD']));

    $hasil = sdJalankan('siapkan-deploy.ps1', [
        '-AkarRepo', $repo['dev'],
        '-FolderCadangan', $repo['akar'] . DIRECTORY_SEPARATOR . 'cadangan',
    ]);

    expect($hasil['kode'])->toBe(1);
    expect($hasil['keluaran'])->toContain('unggah-tugas.ps1');
    expect($hasil['keluaran'])->toContain('SIAPKAN_DEPLOY=BELUM');
    expect(trim(sdGit($repo['dev'], ['rev-parse', 'HEAD'])))->toBe($sebelum);
});

test('penjaga menyebut berkas penghalang dan menyarankan siapkan-deploy', function () {
    $repo  = sdKasusBerkasPenghalang('penjaga');
    $hasil = sdJalankan('penjaga-main-dev.ps1', [
        '-DevPath', $repo['dev'],
        '-RepoUrl', $repo['remote'],
        '-PerbaikiTertinggal',
        '-TanpaCadangan',
    ]);

    expect($hasil['kode'])->toBe(1);
    expect($hasil['keluaran'])->toContain('catatan.txt');
    expect($hasil['keluaran'])->toContain('siapkan-deploy.ps1');
    expect($hasil['keluaran'])->toContain('PENGHALANG=catatan.txt');
    expect($hasil['keluaran'])->toContain('TERTINGGAL=1');

    // Saran lama yang menyesatkan tidak boleh kembali.
    expect($hasil['keluaran'])->not->toContain('pull --ff-only');

    // Penjaga hanya melaporkan: berkas copy DEV tidak disentuh.
    expect(file_get_contents($repo['dev'] . DIRECTORY_SEPARATOR . 'catatan.txt'))->not->toContain('BLOK-DARI-REMOTE-penjaga');
});

test('penjaga lolos bila copy DEV sejajar dengan commit cabang utama remote', function () {
    $repo  = sdRepoUji();
    $hasil = sdJalankan('penjaga-main-dev.ps1', [
        '-DevPath', $repo['dev'],
        '-RepoUrl', $repo['remote'],
        '-TanpaCadangan',
    ]);

    expect($hasil['kode'])->toBe(0);
    expect($hasil['keluaran'])->toContain('SEJAJAR=1');
    expect($hasil['keluaran'])->toContain('CARA=sejajar');
});

test('kembalikan-ke-main menjelaskan berkas penghalang, bukan menyalahkan commit lokal', function () {
    $repo  = sdKasusBerkasPenghalang('kembalikan');
    $hasil = sdJalankan('kembalikan-ke-main.ps1', ['-AkarRepo', $repo['dev'], '-TanpaPenjaga']);

    expect($hasil['kode'])->toBe(1);
    expect($hasil['keluaran'])->toContain('catatan.txt');
    expect($hasil['keluaran'])->toContain('siapkan-deploy.ps1');
    expect($hasil['keluaran'])->toContain('KEMBALI_MAIN=BELUM');
});

test('ketiga skrip alat kerja menunjuk siapkan-deploy.ps1', function () {
    $penjaga    = file_get_contents(base_path('tools/penjaga-main-dev.ps1'));
    $kembalikan = file_get_contents(base_path('tools/kembalikan-ke-main.ps1'));
    $deploy     = file_get_contents(base_path('tools/deploy-laporan-prod.ps1'));

    expect($penjaga)->toContain('siapkan-deploy.ps1');
    expect($penjaga)->not->toContain('pull --ff-only');

    expect($kembalikan)->toContain('siapkan-deploy.ps1');
    expect($kembalikan)->toContain('PENGHALANG=');

    expect($deploy)->toContain('siapkan-deploy.ps1');
});

