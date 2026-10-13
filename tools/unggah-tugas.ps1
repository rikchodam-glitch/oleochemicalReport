<#
    unggah-tugas.ps1 — unggah cabang tugas ke GitHub dengan aman.

    Dipakai SETIAP teknisi (dan asisten AI-nya) SETELAH pekerjaan di-commit.
    Inilah langkah "upload" pada alur edit -> uji -> upload.

    Yang dikerjakan (urutannya sengaja begini):
      1. MENOLAK jalan bila sedang berada di cabang main (main hanya boleh diisi
         lewat Pull Request, lihat AGENTS.md "ALUR KERJA BANYAK TEKNISI").
      2. Menolak jalan bila masih ada berkas ter-track yang belum di-commit.
      3. git fetch origin main, lalu git rebase origin/main supaya cabang tugas
         selalu menempel di ujung main terbaru -> inilah pencegah konflik utama.
         Bila terjadi konflik, rebase DIBATALKAN otomatis agar tidak menyisakan
         keadaan setengah jalan.
      4. Menjalankan tools\cek-sebelum-push.ps1 (php artisan test WAJIB hijau).
      5. Push cabang tugas ke GitHub, lalu mencetak tautan "Buat Pull Request".

    Parameter:
        -AkarRepo <path>    : folder repo (bawaan: folder induk folder skrip ini).
        -RemoteUtama <nama> : remote GitHub (bawaan origin).
        -RepoUrl <url>      : URL repo GitHub (untuk mencetak tautan Pull Request).
        -Branch <nama>      : cabang produksi (bawaan main).
        -LewatiUji          : jangan jalankan test di sini. Karena itu push TANPA
                              --no-verify sehingga hook pre-push tetap menguji.
        -AbaikanKotor       : lanjut walau ada berkas ter-track belum di-commit.

    Return: exit code 0 = berhasil (tautan Pull Request dicetak), 1 = gagal.
#>
param(
    [string]$AkarRepo = '',
    [string]$RemoteUtama = 'origin',
    [string]$RepoUrl = 'https://github.com/rikchodam-glitch/oleochemicalReport.git',
    [string]$Branch = 'main',
    [switch]$LewatiUji,
    [switch]$AbaikanKotor
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[unggah] ' + $Pesan)
}

<#
    Jalankan perintah luar sambil menurunkan ErrorActionPreference sementara.

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
    Jalankan skrip PowerShell lain sebagai PROSES TERPISAH memakai .NET Process
    (bukan Start-Process, yang terbukti mengembalikan ExitCode kosong di
    komputer ini) dan TANPA memipe keluarannya.

    Parameter: $Skrip = path skrip, $Argumen = daftar argumen.
    Return  : exit code proses anak.
#>
function SkripTerpisah([string]$Skrip, [string[]]$Argumen) {
    $argumenTeks = ''
    foreach ($a in $Argumen) { $argumenTeks += ' "' + $a + '"' }

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName        = 'powershell.exe'
    $info.Arguments       = '-NoProfile -ExecutionPolicy Bypass -File "' + $Skrip + '"' + $argumenTeks
    $info.UseShellExecute = $false

    $proses = New-Object System.Diagnostics.Process
    $proses.StartInfo = $info
    $null = $proses.Start()
    $proses.WaitForExit()

    return $proses.ExitCode
}

# ==== LOGIKA UNGGAH ====

if ($AkarRepo -eq '') { $AkarRepo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }

if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    exit 1
}

# ==== 1. Jangan pernah mengunggah dari cabang produksi ====
$hasilCabang = Panggil 'git' @('-C', $AkarRepo, 'rev-parse', '--abbrev-ref', 'HEAD')
$cabang      = (([string]($hasilCabang.Keluaran | Select-Object -First 1)) + '').Trim()

if ($cabang -eq $Branch -or $cabang -eq 'master' -or $cabang -eq 'HEAD') {
    Tulis ('GAGAL: sedang berada di cabang "' + $cabang + '". Mengunggah langsung ke cabang')
    Tulis 'produksi DILARANG. Pindah ke cabang tugas lebih dulu:'
    Write-Host '   powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas <ringkas>'
    exit 1
}

if ($cabang -notlike 'tugas/*') {
    Tulis ('PERINGATAN: nama cabang tidak mengikuti pola tugas/<nama>/<ringkas> (' + $cabang + ').')
}

# ==== 2. Berkas ter-track yang belum di-commit ====
$status = Panggil 'git' @('-C', $AkarRepo, 'status', '--porcelain', '--untracked-files=no')
$kotor  = @()

foreach ($baris in @($status.Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -ne '') { $kotor += $teks }
}

if ($kotor.Count -gt 0 -and -not $AbaikanKotor) {
    Tulis ('GAGAL: masih ada ' + $kotor.Count + ' berkas ter-track yang belum di-commit:')

    foreach ($baris in ($kotor | Select-Object -First 15)) { Write-Host ('       ' + $baris) }

    Tulis 'Commit dulu berkas yang relevan (jangan `git add -A`), lalu ulangi.'
    exit 1
}

# ==== 3. Selaraskan dengan main terbaru (rebase) ====
# Inilah pencegah konflik utama: cabang tugas selalu menempel di ujung main
# terbaru, jadi konflik ketahuan di komputer sendiri saat masih mudah dibaca.
$ambil = Panggil 'git' @('-C', $AkarRepo, 'fetch', $RemoteUtama, $Branch)

if ($ambil.Kode -ne 0) {
    Tulis ('GAGAL: git fetch ' + $RemoteUtama + ' ' + $Branch + ' gagal. Periksa koneksi internet.')
    exit 1
}

# autoStash dipakai bila masih ada berkas ter-track yang belum di-commit (opsi
# -AbaikanKotor): git menyimpannya sendiri lalu mengembalikannya sesudah rebase.
# Tanpa ini, `git rebase` MENOLAK jalan pada folder kerja yang kotor dan
# pesannya ("You have unstaged changes") mudah disalahartikan sebagai konflik.
$argRebase = @('-C', $AkarRepo)

if ($kotor.Count -gt 0) { $argRebase += @('-c', 'rebase.autoStash=true') }

$argRebase += @('rebase', ($RemoteUtama + '/' + $Branch))
$rebase     = Panggil 'git' $argRebase

if ($rebase.Kode -ne 0) {
    $teksRebase = (@($rebase.Keluaran) -join ' ')

    if ($teksRebase -like '*unstaged changes*') {
        Tulis 'GAGAL: rebase tidak bisa dimulai karena ada berkas ter-track yang belum di-commit.'
        Tulis 'Commit berkas yang relevan, atau pakai -PindahkanPerubahan lebih dulu.'
        Tulis 'Keadaan cabang TIDAK diubah dan tidak ada yang diunggah.'
        exit 1
    }

    Tulis 'GAGAL: rebase ke main terbaru menemui KONFLIK. Rebase dibatalkan otomatis.'
    foreach ($baris in @($rebase.Keluaran | Select-Object -Last 12)) { Write-Host ('       ' + ([string]$baris).Trim()) }

    $batal = Panggil 'git' @('-C', $AkarRepo, 'rebase', '--abort')

    if ($batal.Kode -eq 0) { Tulis 'Keadaan cabang dikembalikan seperti sebelum rebase.' }

    Tulis 'Selesaikan konflik: jalankan `git rebase origin/main`, perbaiki berkas yang'
    Tulis 'bertanda konflik, `git add` berkas itu, `git rebase --continue`.'
    Tulis 'Aturan menyelesaikan konflik per jenis berkas: AGENTS.md bagian'
    Tulis '"ALUR KERJA BANYAK TEKNISI" butir 6.'
    exit 1
}

Tulis ('Cabang sudah menempel di ujung ' + $RemoteUtama + '/' + $Branch + '.')

# ==== 4. Ringkasan yang akan diunggah ====
$hasilJumlah = Panggil 'git' @('-C', $AkarRepo, 'rev-list', '--count', (($RemoteUtama + '/' + $Branch) + '..HEAD'))
$jumlah      = (([string]($hasilJumlah.Keluaran | Select-Object -First 1)) + '').Trim()

Tulis ('Commit yang akan diunggah: ' + $jumlah)

foreach ($baris in @((Panggil 'git' @('-C', $AkarRepo, 'log', '--oneline', '--no-merges', (($RemoteUtama + '/' + $Branch) + '..HEAD'))).Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -ne '') { Write-Host ('   ' + $teks) }
}

Write-Host ''

foreach ($baris in @((Panggil 'git' @('-C', $AkarRepo, 'diff', '--stat', (($RemoteUtama + '/' + $Branch) + '...HEAD'))).Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -ne '') { Write-Host ('   ' + $teks) }
}

Write-Host ''

# ==== 5. Uji sebelum unggah ====
$skripCek      = Join-Path $PSScriptRoot 'cek-sebelum-push.ps1'
$pakaiNoVerify = $false

if ($LewatiUji) {
    Tulis 'Uji di sini dilewati (-LewatiUji); hook pre-push yang akan mengujinya.'
} else {
    $argUji = @('-AkarRepo', $AkarRepo)

    if ($AbaikanKotor) { $argUji += '-IzinkanKotor' }

    $kodeUji = SkripTerpisah $skripCek $argUji

    if ($kodeUji -ne 0) {
        Tulis 'GAGAL: pemeriksaan sebelum unggah tidak lolos. Tidak ada yang diunggah.'
        exit 1
    }

    $pakaiNoVerify = $true
}

# ==== 6. Unggah cabang tugas ====
$argumenPush = @('-C', $AkarRepo, 'push', '-u', $RemoteUtama, $cabang)

if ($pakaiNoVerify) { $argumenPush += '--no-verify' }

$push = Panggil 'git' $argumenPush

if ($push.Kode -ne 0) {
    Tulis 'GAGAL: unggahan cabang tugas ditolak.'
    foreach ($baris in @($push.Keluaran | Select-Object -Last 8)) { Write-Host ('       ' + ([string]$baris).Trim()) }
    exit 1
}

# ==== 7. Tautan Pull Request ====
$alamat = $RepoUrl

if ($alamat.EndsWith('.git')) { $alamat = $alamat.Substring(0, $alamat.Length - 4) }

$tautan = $alamat + '/compare/' + $Branch + '...' + $cabang + '?expand=1'

Write-Host ''
Tulis ('Cabang terunggah: ' + $cabang)
Write-Host ''
Write-Host '=== LANGKAH MANUSIA ==='
Write-Host '1. Buka tautan di bawah pada peramban, lalu tekan "Create pull request":'
Write-Host ('   ' + $tautan)
Write-Host '2. Isi ringkasan + checklist (template sudah tersedia), lalu kirim.'
Write-Host '3. Integrator me-review dan menekan Merge.'
Write-Host '4. Sesudah di-merge, baru jalankan Deploy-Laporan.bat (komputer integrator).'
Write-Host ''
Tulis ('TAUTAN_PR=' + $tautan)
exit 0
