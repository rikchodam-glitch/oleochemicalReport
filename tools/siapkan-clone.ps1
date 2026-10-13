<#
    siapkan-clone.ps1 — WAJIB dijalankan SEKALI pada setiap clone baru.

    Tanpa langkah ini hook `.githooks/pre-push` TIDAK aktif, karena folder
    `.git/hooks` tidak ikut ter-clone dan git hanya membaca hook dari folder
    yang ditunjuk `core.hooksPath`.

    Yang dikerjakan:
      1. Menunjuk `core.hooksPath` ke folder `.githooks` (ikut repository).
      2. Memeriksa perkakas yang dibutuhkan (php, composer, node) beserta
         lokasi cadangan Laragon, karena ketiganya TIDAK selalu ada di PATH.
      3. Membuat `.env` dari `.env.example` bila belum ada.
      4. Mengingatkan nama/email git, dan langkah berikutnya.

    Parameter:
        -AkarRepo <path> : folder repo (bawaan: folder induk folder skrip ini).
        -LewatiEnv       : jangan buat .env otomatis.
        -LewatiHook      : jangan ubah core.hooksPath (hanya memeriksa).

    Return: exit code 0 = siap dipakai, 1 = ada yang harus dibereskan dulu.
#>
param(
    [string]$AkarRepo = '',
    [switch]$LewatiEnv,
    [switch]$LewatiHook
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres penyiapan.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[siap-clone] ' + $Pesan)
}

<#
    Cari sebuah program: PATH dulu, lalu daftar lokasi cadangan Laragon.

    Parameter: $Nama = nama perintah, $Kandidat = daftar path cadangan.
    Return  : path program, atau string kosong bila tidak ditemukan.
#>
function Cari([string]$Nama, [string[]]$Kandidat) {
    $ada = Get-Command $Nama -ErrorAction SilentlyContinue

    if ($null -ne $ada) { return $ada.Source }

    foreach ($jalan in $Kandidat) {
        if (Test-Path $jalan) { return $jalan }
    }

    return ''
}

if ($AkarRepo -eq '') { $AkarRepo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }

if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    exit 1
}

Tulis ('Repo: ' + $AkarRepo)
Write-Host ''

# ==== 1. Hook pre-push ====
$FolderHook = Join-Path $AkarRepo '.githooks'
$FileHook   = Join-Path $FolderHook 'pre-push'

if (-not (Test-Path $FileHook)) {
    Tulis ('GAGAL: berkas hook tidak ada: ' + $FileHook)
    exit 1
}

if ($LewatiHook) {
    Tulis 'Pengaturan hook dilewati (opsi -LewatiHook).'
} else {
    $lama = ''

    try { $lama = (git -C $AkarRepo config --get core.hooksPath 2>$null) } catch { $lama = '' }

    git -C $AkarRepo config core.hooksPath '.githooks'
    Tulis ('core.hooksPath disetel ke .githooks (sebelumnya: ' + (([string]$lama) + '').Trim() + ').')
    Tulis ('Hook pre-push aktif: ' + $FileHook)
}

Write-Host ''

# ==== 2. Perkakas ====
$php      = Cari 'php' @(
    'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
)
$composer = Cari 'composer' @('C:\laragon\bin\composer\composer.bat')
$node     = Cari 'node' @('C:\laragon\bin\nodejs\node-v22\node.exe')

if ($php -eq '') { Tulis 'PERINGATAN: php.exe tidak ditemukan (hook butuh php untuk menjalankan test).' }
else { Tulis ('php      : ' + $php) }

if ($composer -eq '') { Tulis 'PERINGATAN: composer tidak ditemukan (jalankan: composer install).' }
else { Tulis ('composer : ' + $composer) }

if ($node -eq '') { Tulis 'PERINGATAN: node tidak ditemukan (hanya perlu bila membangun aset: npm run build).' }
else { Tulis ('node     : ' + $node) }

Write-Host ''

# ==== 3. .env ====
$fileEnv    = Join-Path $AkarRepo '.env'
$fileContoh = Join-Path $AkarRepo '.env.example'

if ((Test-Path $fileEnv) -or $LewatiEnv) {
    Tulis '.env sudah ada / pembuatan .env dilewati.'
} elseif (Test-Path $fileContoh) {
    Copy-Item -LiteralPath $fileContoh -Destination $fileEnv
    Tulis '.env dibuat dari .env.example. WAJIB disunting dulu (nama database, kredensial) sebelum dipakai.'
} else {
    Tulis 'PERINGATAN: .env belum ada dan .env.example tidak ditemukan.'
}

Write-Host ''

# ==== 4. Identitas git & langkah berikutnya ====
$namaGit  = (((git -C $AkarRepo config --get user.name)  + '') + '').Trim()
$emailGit = (((git -C $AkarRepo config --get user.email) + '') + '').Trim()

if ($namaGit -eq '') { Tulis 'PERINGATAN: git user.name belum diisi. Jalankan: git config user.name "Nama Anda".' }
else { Tulis ('git user.name  : ' + $namaGit) }

if ($emailGit -eq '') { Tulis 'PERINGATAN: git user.email belum diisi. Jalankan: git config user.email "email@anda".' }
else { Tulis ('git user.email : ' + $emailGit) }

Write-Host ''
Tulis 'Langkah berikutnya:'
Write-Host '   1. git fetch origin main'
Write-Host '   2. powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama-anda> -Tugas <ringkas>'
Write-Host '   3. kerjakan perubahan, lalu unggah dengan tools\unggah-tugas.ps1'
Write-Host '   Aturan lengkap: AGENTS.md bagian "ALUR KERJA BANYAK TEKNISI".'
Write-Host ''

if ($php -eq '') { exit 1 }

$komit = (([string]((git -C $AkarRepo log --oneline -1 2>$null) | Select-Object -First 1)) + '').Trim()

Tulis ('SIAP_CLONE=OK (' + $komit + ')')
exit 0
