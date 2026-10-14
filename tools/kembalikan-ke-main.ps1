<#
    kembalikan-ke-main.ps1 — kembalikan copy DEV ke cabang utama dan samakan
    dengan GitHub (langkah "git checkout main" + "git pull --ff-only").

    KENAPA PERLU. tools\penjaga-main-dev.ps1 — penjaga yang dipakai
    Deploy-Laporan.bat — MEMBATALKAN deploy bila copy DEV tidak berada TEPAT
    pada commit cabang utama GitHub, termasuk bila copy DEV masih berdiri di
    cabang tugas. Itulah yang menghalangi deploy selama Pull Request belum
    di-merge. Skrip ini mengerjakan langkah manual itu dengan aman, lalu
    MENJALANKAN penjaga yang sama sebagai verifikasi terakhir supaya jawaban
    "siap deploy" di sini dan keputusan deploy selalu berasal dari satu aturan.

    Yang dikerjakan:
      1. Menampilkan cabang + commit sekarang beserta berkas ter-track yang
         belum di-commit (pekerjaan sesi lain) TANPA mengubahnya.
      2. `git checkout <cabang utama>` — dilewati bila sudah berada di sana.
         Bila ada perubahan yang akan tertimpa, skrip BERHENTI dan menyarankan
         opsi -PindahkanPerubahan.
      3. `git fetch` + `git merge --ff-only` ke commit cabang utama GitHub:
         TIDAK pernah membuat commit baru dan TIDAK menimpa pekerjaan siapa pun.
      4. Menjawab apakah cabang tugas yang tadi aktif SUDAH masuk cabang utama
         (kalau belum, deploy hanya akan memasang kode lama — supaya tidak
         dikira Pull Request-nya sudah di-merge).
      5. Menjalankan tools\penjaga-main-dev.ps1 sebagai VERIFIKASI TERAKHIR.

    Skrip ini HANYA menyentuh copy DEV; copy PROD tidak pernah disentuh.

    Parameter:
        -AkarRepo <path>    : copy DEV (bawaan C:\laragon\www\oleochemicalReport).
        -RemoteUtama <nama> : remote GitHub (bawaan origin).
        -BranchUtama <nama> : cabang utama (bawaan main).
        -PindahkanPerubahan : simpan perubahan yang menghalangi ke git stash,
                              lalu pulihkan (git stash pop) sesudah checkout.
        -TanpaPenjaga       : jangan jalankan penjaga di akhir (untuk pengujian).

    Return: exit code 0 = copy DEV sejajar dengan cabang utama GitHub (siap
            deploy), 1 = belum (penyebabnya dicetak di layar).
            Penanda keluaran: KEMBALI_MAIN=SIAP|BELUM beserta CABANG=... KOMIT=...
#>
param(
    [string]$AkarRepo = 'C:\laragon\www\oleochemicalReport',
    [string]$RemoteUtama = 'origin',
    [string]$BranchUtama = 'main',
    [switch]$PindahkanPerubahan,
    [switch]$TanpaPenjaga
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres skrip ini.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[kembalikan] ' + $Pesan)
}

<#
    Jalankan perintah luar sambil menurunkan ErrorActionPreference sementara
    (git menulis progres ke stderr).

    Parameter: $Perintah = path exe, $Argumen = daftar argumen.
    Return  : hashtable dengan kunci Keluaran (teks) dan Kode (exit code).
#>
function Panggil([string]$Perintah, [string[]]$Argumen) {
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $keluaran = & $Perintah @Argumen 2>&1 | ForEach-Object { [string]$_ }
        $kode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $lama
    }

    return @{ Keluaran = $keluaran; Kode = $kode }
}

<#
    Ambil baris pertama keluaran sebuah perintah git (spasi tepi dibuang).

    Parameter: $Argumen = argumen git.
    Return  : teks baris pertama (string kosong bila gagal).
#>
function BarisPertama([string[]]$Argumen) {
    $hasil = Panggil 'git' $Argumen
    return (([string]($hasil.Keluaran | Select-Object -First 1)) + '').Trim()
}

<#
    Jalankan skrip PowerShell lain sebagai PROSES TERPISAH memakai .NET Process
    (bukan Start-Process, yang terbukti mengembalikan ExitCode kosong di
    komputer ini — Riwayat Insiden #31) dan TANPA memipe keluarannya.

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
    $proses.Start() | Out-Null
    $proses.WaitForExit()

    return $proses.ExitCode
}

# ==== 1. Keadaan sekarang ====
if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    Write-Host 'KEMBALI_MAIN=BELUM'
    exit 1
}

Tulis ('Copy DEV : ' + $AkarRepo)

$cabangAwal = BarisPertama @('-C', $AkarRepo, 'rev-parse', '--abbrev-ref', 'HEAD')
$komitAwal  = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')

Write-Host ('[kembalikan] Cabang sekarang : ' + $cabangAwal)
Write-Host ('[kembalikan] Commit sekarang : ' + $komitAwal)

$kotor = @()

foreach ($b in @((Panggil 'git' @('-C', $AkarRepo, 'status', '--porcelain', '--untracked-files=no')).Keluaran)) {
    $teks = ([string]$b).Trim()

    if ($teks -ne '') { $kotor += $teks }
}

if ($kotor.Count -gt 0) {
    Tulis ('CATATAN: ada ' + $kotor.Count + ' berkas ter-track belum di-commit (pekerjaan sesi lain). Berkas itu TIDAK diubah oleh skrip ini:')

    foreach ($baris in ($kotor | Select-Object -First 12)) { Write-Host ('             ' + $baris) }
}

# ==== 2. Berpindah ke cabang utama ====
$stashDibuat = $false

if ($cabangAwal -ne $BranchUtama) {
    Tulis ('Berpindah ke cabang ' + $BranchUtama + '...')
    $checkout = Panggil 'git' @('-C', $AkarRepo, 'checkout', $BranchUtama)

    if ($checkout.Kode -ne 0) {
        if (-not $PindahkanPerubahan) {
            Tulis 'BERHENTI: git checkout gagal (ada perubahan yang akan tertimpa).'

            foreach ($baris in @($checkout.Keluaran | Select-Object -Last 6)) { Write-Host ('             ' + ([string]$baris).Trim()) }

            Tulis 'Jalankan ulang dengan opsi -PindahkanPerubahan supaya perubahan itu disimpan ke git stash lalu dipulihkan.'
            Tulis 'Copy DEV TIDAK diubah.'
            Write-Host 'KEMBALI_MAIN=BELUM'
            exit 1
        }

        $stash = Panggil 'git' @('-C', $AkarRepo, 'stash', 'push', '-u', '-m', 'perubahan sesi lain (dipindahkan tools\kembalikan-ke-main.ps1)')

        if ($stash.Kode -ne 0) {
            Tulis 'GAGAL: git stash juga gagal; copy DEV TIDAK diubah.'
            Write-Host 'KEMBALI_MAIN=BELUM'
            exit 1
        }

        $stashDibuat = $true
        Tulis 'Perubahan yang menghalangi disimpan ke git stash.'

        $checkout = Panggil 'git' @('-C', $AkarRepo, 'checkout', $BranchUtama)

        if ($checkout.Kode -ne 0) {
            Tulis 'GAGAL: checkout tetap gagal. Perubahan Anda AMAN di git stash (lihat `git stash list`).'
            Write-Host 'KEMBALI_MAIN=BELUM'
            exit 1
        }
    }
} else {
    Tulis ('Sudah berada di cabang ' + $BranchUtama + '; checkout dilewati.')
}

# Perubahan sesi lain dikembalikan supaya pekerjaan mereka tidak hilang dari
# folder kerja.
if ($stashDibuat) {
    $pop = Panggil 'git' @('-C', $AkarRepo, 'stash', 'pop')

    if ($pop.Kode -ne 0) {
        Tulis 'PERINGATAN: git stash pop gagal. Perubahan itu AMAN di git stash -> jalankan `git stash list` lalu `git stash pop`.'
    } else {
        Tulis 'Perubahan sesi lain dikembalikan (git stash pop).'
    }
}

# ==== 3. Samakan dengan cabang utama GitHub (fast-forward saja) ====
$fetch = Panggil 'git' @('-C', $AkarRepo, 'fetch', '--quiet', $RemoteUtama, $BranchUtama)

if ($fetch.Kode -ne 0) {
    Tulis 'PERINGATAN: GitHub tidak bisa dihubungi; pemeriksaan memakai commit lokal yang ada.'
}

$maju = Panggil 'git' @('-C', $AkarRepo, 'merge', '--ff-only', ($RemoteUtama + '/' + $BranchUtama))

if ($maju.Kode -ne 0) {
    Tulis 'BERHENTI: cabang utama lokal TIDAK bisa dimajukan dengan fast-forward.'
    Tulis 'Artinya cabang utama lokal punya commit sendiri (pernah ditulis langsung), bukan sekadar tertinggal.'

    foreach ($baris in @((Panggil 'git' @('-C', $AkarRepo, 'log', '--oneline', '-8', (($RemoteUtama + '/' + $BranchUtama) + '..HEAD'))).Keluaran)) {
        Write-Host ('  commit lokal : ' + ([string]$baris).Trim())
    }

    Tulis 'Mintakan bantuan integrator; JANGAN memaksa (git reset --hard) sebelum isi commit itu jelas.'
    Write-Host 'KEMBALI_MAIN=BELUM'
    exit 1
}

$komitBaru = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')
Write-Host ('[kembalikan] Cabang ' + $BranchUtama + ' sekarang di commit : ' + $komitBaru)

# ==== 4. Apakah cabang tugas tadi sudah masuk cabang utama? ====
$pesanMerge = 'tidak-dinilai'

if ($cabangAwal -ne $BranchUtama -and $komitAwal -ne '') {
    $uji = Panggil 'git' @('-C', $AkarRepo, 'merge-base', '--is-ancestor', $komitAwal, ($RemoteUtama + '/' + $BranchUtama))

    if ($uji.Kode -eq 0) {
        $pesanMerge = 'sudah'
        Tulis ('BAIK: cabang tugas ' + $cabangAwal + ' SUDAH masuk cabang utama (Pull Request sudah di-merge).')
    } else {
        $pesanMerge = 'belum'
        Write-Host ''
        Tulis ('PERHATIAN: cabang tugas ' + $cabangAwal + ' BELUM masuk cabang utama.')
        Tulis 'Deploy sekarang hanya akan memasang kode LAMA. Selesaikan dulu Pull Request-nya:'
        Tulis '  powershell -ExecutionPolicy Bypass -File tools\buat-pr-tugas.ps1'
        Write-Host ''
    }
}

# ==== 5. Verifikasi terakhir memakai penjaga yang sama dengan deploy ====
$siap = $true

if ($TanpaPenjaga) {
    Tulis 'Penjaga dilewati (opsi -TanpaPenjaga).'
} else {
    $skripPenjaga = Join-Path $PSScriptRoot 'penjaga-main-dev.ps1'

    if (-not (Test-Path $skripPenjaga)) {
        Tulis 'PERINGATAN: tools\penjaga-main-dev.ps1 tidak ditemukan; verifikasi terakhir dilewati.'
    } else {
        Write-Host ''
        Tulis 'Verifikasi terakhir: menjalankan tools\penjaga-main-dev.ps1...'
        Write-Host ''

        $kodePenjaga = SkripTerpisah $skripPenjaga @('-DevPath', $AkarRepo, '-PerbaikiTertinggal')

        if ($kodePenjaga -ne 0) { $siap = $false }
    }
}

Write-Host ''

if ($pesanMerge -eq 'belum') { $siap = $false }

if ($siap) {
    Tulis 'SIAP DEPLOY: copy DEV sejajar dengan cabang utama GitHub.'
    Tulis 'Langkah berikutnya: klik Deploy-Laporan.bat.'
    Write-Host ('CABANG=' + $cabangAwal)
    Write-Host ('KOMIT=' + $komitBaru)
    Write-Host 'KEMBALI_MAIN=SIAP'
    exit 0
}

Tulis 'BELUM SIAP DEPLOY (lihat pesan di atas).'
Write-Host ('CABANG=' + $cabangAwal)
Write-Host ('KOMIT=' + $komitBaru)
Write-Host ('CABANG_TUGAS_BELUM_MERGE=' + $pesanMerge)
Write-Host 'KEMBALI_MAIN=BELUM'
exit 1
