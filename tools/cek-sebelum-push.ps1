<#
    cek-sebelum-push.ps1 — pemeriksa TUNGGAL sebelum pekerjaan diunggah ke GitHub.

    Dipanggil oleh DUA pihak supaya aturannya hanya hidup di satu tempat:
      1. .githooks/pre-push (dipasang oleh tools\siapkan-clone.ps1 lewat
         `git config core.hooksPath .githooks`).
      2. tools\unggah-tugas.ps1.

    Yang diperiksa:
      - Berkas TER-TRACK yang belum di-commit -> GAGAL. Alasannya: `php artisan
        test` menguji isi folder kerja, jadi kalau masih ada perubahan yang belum
        di-commit, yang diuji BUKAN yang diunggah. Kecuali opsi -IzinkanKotor.
      - Berkas BARU (untracked) -> hanya PERINGATAN (sering berupa berkas kerja
        yang memang tidak di-commit).
      - `php artisan test` -> GAGAL bila ada test merah.
      - $env:DB_DATABASE dibersihkan sementara selama uji: phpunit.xml memakai
        `:memory:` dan nilai variabel lingkungan MENANG atas phpunit.xml, jadi
        sisa nilai dari sesi lain pernah membuat SELURUH suite merah
        ("SQLiteDatabaseDoesNotExistException: Database file at path
        [oleochemical] does not exist").

    Parameter:
        -AkarRepo <path> : folder repo (bawaan: folder induk folder skrip ini).
        -PhpExe <path>   : php.exe. Kosong = dicari otomatis (PATH lalu Laragon).
        -LewatiUji       : jangan jalankan test (untuk menguji skrip ini saja).
        -IzinkanKotor    : jangan gagal walau ada berkas ter-track belum commit.

    Return: exit code 0 = boleh lanjut, 1 = harus dibatalkan.
            Penanda keluaran terakhir: SEBELUM_PUSH=OK atau SEBELUM_PUSH=GAGAL.
#>
param(
    [string]$AkarRepo = '',
    [string]$PhpExe = '',
    [switch]$LewatiUji,
    [switch]$IzinkanKotor
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres pemeriksaan.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[sebelum-push] ' + $Pesan)
}

<#
    Jalankan perintah luar sambil menurunkan ErrorActionPreference sementara
    supaya tulisan stderr (mis. progres git) tidak menghentikan skrip.

    Parameter: $Perintah = path exe, $Argumen = daftar argumen, $Di = folder kerja.
    Return  : hashtable dengan kunci Keluaran (teks) dan Kode (exit code).
#>
function Panggil([string]$Perintah, [string[]]$Argumen, [string]$Di = '') {
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    if ($Di -ne '') { Push-Location $Di }

    try {
        $keluaran = & $Perintah @Argumen 2>&1 | ForEach-Object { [string]$_ }
        $kode = $LASTEXITCODE
    } finally {
        if ($Di -ne '') { Pop-Location }
        $ErrorActionPreference = $lama
    }

    return @{ Keluaran = $keluaran; Kode = $kode }
}

<#
    Cari php.exe: pakai yang diminta, lalu PATH, lalu lokasi bawaan Laragon.
    php TIDAK selalu ada di PATH sesi terminal (terbukti 13/10/2026).

    Parameter: $Diminta = path php.exe dari parameter (boleh kosong).
    Return  : path php.exe, atau string kosong bila tidak ditemukan.
#>
function CariPhp([string]$Diminta) {
    if ($Diminta -ne '' -and (Test-Path $Diminta)) { return $Diminta }

    $ada = Get-Command php -ErrorAction SilentlyContinue

    if ($null -ne $ada) { return $ada.Source }

    foreach ($kandidat in @(
        'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
        'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
    )) {
        if (Test-Path $kandidat) { return $kandidat }
    }

    return ''
}

if ($AkarRepo -eq '') { $AkarRepo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }

if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    Write-Host 'SEBELUM_PUSH=GAGAL'
    exit 1
}

# ==== 1. Berkas yang belum di-commit ====
$hasilStatus = Panggil 'git' @('-C', $AkarRepo, 'status', '--porcelain', '--untracked-files=all')
$daftarKotor = @()
$daftarBaru  = @()

foreach ($baris in @($hasilStatus.Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -eq '') { continue }

    # PERHATIAN: jangan memakai pola `-like '??*'` — di PowerShell tanda tanya
    # adalah wildcard satu karakter, sehingga pola itu cocok untuk SEMUA baris
    # dan berkas yang dimodifikasi ikut dianggap berkas baru.
    if ($teks.StartsWith('??')) { $daftarBaru += $teks } else { $daftarKotor += $teks }
}

if ($daftarKotor.Count -gt 0 -and -not $IzinkanKotor) {
    Tulis ('GAGAL: masih ada ' + $daftarKotor.Count + ' berkas ter-track yang belum di-commit:')
    foreach ($baris in ($daftarKotor | Select-Object -First 15)) { Write-Host ('             ' + $baris) }
    Tulis 'Commit dulu berkas yang relevan (jangan `git add -A`), lalu ulangi.'
    Write-Host 'SEBELUM_PUSH=GAGAL'
    exit 1
}

if ($daftarKotor.Count -gt 0) {
    Tulis ('PERINGATAN: ' + $daftarKotor.Count + ' berkas ter-track belum di-commit (diizinkan lewat -IzinkanKotor).')
}

if ($daftarBaru.Count -gt 0) {
    Tulis ('PERINGATAN: ' + $daftarBaru.Count + ' berkas BARU belum di-commit (tidak akan ikut terunggah):')
    foreach ($baris in ($daftarBaru | Select-Object -First 10)) { Write-Host ('             ' + $baris) }
}

# ==== 2. Uji otomatis ====
if ($LewatiUji) {
    Tulis 'Uji otomatis dilewati (opsi -LewatiUji); hook pre-push akan menjalankannya sendiri.'
} else {
    $php = CariPhp $PhpExe

    if ($php -eq '') {
        Tulis 'GAGAL: php.exe tidak ditemukan. Pakai opsi -PhpExe <path>, atau jalankan tools\siapkan-clone.ps1.'
        Write-Host 'SEBELUM_PUSH=GAGAL'
        exit 1
    }

    Tulis 'Menjalankan uji otomatis (php artisan test). Mohon tunggu...'

    $simpanDb = $env:DB_DATABASE
    $env:DB_DATABASE = $null

    try {
        $uji = Panggil $php @('artisan', 'test') $AkarRepo
    } finally {
        $env:DB_DATABASE = $simpanDb
    }

    foreach ($baris in @($uji.Keluaran | Select-Object -Last 12)) { Write-Host ('             ' + $baris) }

    if ($uji.Kode -ne 0) {
        Tulis ('GAGAL: uji otomatis merah (kode ' + $uji.Kode + '). Unggahan dibatalkan.')
        Write-Host 'SEBELUM_PUSH=GAGAL'
        exit 1
    }

    Tulis 'Uji otomatis HIJAU.'
}

Tulis 'Pemeriksaan sebelum unggah selesai: SEMUA BAIK.'
Write-Host 'SEBELUM_PUSH=OK'
exit 0
