<#
    buat-pr-tugas.ps1 — siapkan Pull Request cabang tugas (satu klik dari Desktop).

    LATAR. GitHub TIDAK PERNAH membuat Pull Request sendiri: tombol
    "Merge pull request" baru muncul SESUDAH ada PR yang dibuat MANUSIA di
    peramban. Komputer ini tidak punya `gh` CLI maupun token GitHub, jadi
    pembuatan PR memang hanya bisa lewat peramban. Skrip ini mengerjakan
    seluruh bagian yang bisa diotomatiskan supaya tinggal SATU klik:

      1. memeriksa repo dan cabang yang sedang aktif;
      2. membuktikan commit cabang tugas SUDAH sampai ke GitHub, dibaca LANGSUNG
         dari GitHub memakai `git ls-remote` (bukan ref remote-tracking lokal
         yang bisa basi — Riwayat Insiden #42);
      3. menyusun judul + isi PR: bagian catatan dari berkas
         `tugas\pr-<ringkas cabang>.md` (bila ada) DITAMBAH daftar commit serta
         ringkasan berkas yang dibuat OTOMATIS dari git, sehingga isi PR selalu
         sesuai keadaan cabang saat itu;
      4. menyalin isi PR ke papan klip dan menuliskannya ke berkas .md;
      5. membuka peramban pada halaman "Compare & pull request" GitHub dengan
         judul sudah terisi (query `quick_pull=1` + `title`, sesuai dokumentasi
         GitHub "Using query parameters to create a pull request"). Isi PR ikut
         dimasukkan ke tautan HANYA bila tautannya tetap pendek — GitHub
         membalas 414 URI Too Long untuk tautan yang terlalu panjang.

    TABEL KEPUTUSAN (sengaja setara penjaga deploy supaya pesannya konsisten):
        sejajar dengan GitHub     -> siap; tautan PR dicetak (exit 0).
        belum diunggah ke GitHub  -> exit 1; jalankan tools\unggah-tugas.ps1.
        commit lokal beda GitHub  -> exit 1; jalankan tools\unggah-tugas.ps1.
        sedang di cabang utama    -> exit 1; tidak ada PR untuk dibuat.
        -Periksa                  -> tidak menyiapkan PR, hanya menjawab
                                     apakah commit cabang sudah masuk main.

    Parameter:
        -AkarRepo <path>    : folder repo (bawaan: folder induk folder skrip ini).
        -RepoUrl <url>      : URL repo GitHub.
        -RemoteUtama <nama> : remote GitHub (bawaan origin).
        -BranchUtama <nama> : cabang produksi (bawaan main).
        -Judul <teks>       : judul PR. Bawaan: baris pertama `# ...` pada
                              berkas catatan, atau subjek commit terbaru.
        -BerkasCatatan <p>  : berkas markdown isi PR (bawaan: dicari otomatis di
                              `tugas\pr-<ringkas cabang>.md`).
        -TanpaPeramban      : jangan buka peramban (untuk pengujian otomatis).
        -TanpaPapanKlip     : jangan salin isi PR ke papan klip.
        -Periksa            : hanya periksa apakah commit cabang sudah masuk ke
                              cabang utama GitHub (tidak menyiapkan PR).
        -CetakIsi           : cetak isi PR ke layar (untuk pengujian manusia).

    Return: exit code 0 = siap (atau sudah di-merge pada mode -Periksa),
            1 = masih ada yang harus dibereskan.
            Penanda keluaran: PR_STATUS=... beserta PR_TAUTAN=... / PR_BERKAS=...
#>
param(
    [string]$AkarRepo = '',
    [string]$RepoUrl = 'https://github.com/rikchodam-glitch/oleochemicalReport.git',
    [string]$RemoteUtama = 'origin',
    [string]$BranchUtama = 'main',
    [string]$Judul = '',
    [string]$BerkasCatatan = '',
    [switch]$TanpaPeramban,
    [switch]$TanpaPapanKlip,
    [switch]$Periksa,
    [switch]$CetakIsi
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres skrip ini.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[buat-pr] ' + $Pesan)
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
    Enkode teks untuk dipakai sebagai nilai query pada tautan GitHub. GitHub
    memakai tanda + untuk spasi (dokumentasi resmi), sedangkan
    EscapeDataString menghasilkan %20 — karena itu %20 ditukar menjadi +.

    Parameter: $Teks = teks mentah.
    Return  : teks siap ditempel ke URL.
#>
function EncodeUrl([string]$Teks) {
    return ([uri]::EscapeDataString($Teks) -replace '%20', '+')
}


# ==== 1. Repo dan cabang yang sedang aktif ====
if ($AkarRepo -eq '') { $AkarRepo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }

if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    Write-Host 'PR_STATUS=BUKAN_REPO'
    exit 1
}

$cabang     = BarisPertama @('-C', $AkarRepo, 'rev-parse', '--abbrev-ref', 'HEAD')
$komitLokal = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')

Tulis ('Repo   : ' + $AkarRepo)
Tulis ('Cabang : ' + $cabang + ' @ ' + $komitLokal)

if ($cabang -eq '' -or $komitLokal -eq '') {
    Tulis 'GAGAL: keadaan repo tidak terbaca (rev-parse gagal).'
    Write-Host 'PR_STATUS=REPO_TIDAK_TERBACA'
    exit 1
}

if ($cabang -eq $BranchUtama) {
    Tulis ('BERHENTI: repo sedang berada di cabang ' + $BranchUtama + '; cabang utama tidak pernah dibuatkan PR.')
    Tulis 'Kalau maksudnya mengembalikan copy DEV ke cabang utama, pakai tools\kembalikan-ke-main.ps1.'
    Tulis 'Kalau maksudnya mulai mengerjakan tugas baru, pakai tools\mulai-tugas.ps1.'
    Write-Host 'PR_STATUS=CABANG_UTAMA'
    exit 1
}

# ==== 2. Buktikan commit cabang SUDAH ada di GitHub ====
# Dibaca LANGSUNG dari GitHub (ls-remote), bukan dari ref remote-tracking lokal
# yang bisa basi. Kredensial dimatikan karena repo ini publik.
$hasilRemote = Panggil 'git' @('-c', 'credential.helper=', '-C', $AkarRepo, 'ls-remote', '--heads', $RemoteUtama, ('refs/heads/' + $cabang))
$barisRemote = (([string]($hasilRemote.Keluaran | Select-Object -First 1)) + '').Trim()
$komitGithub = ''

if ($barisRemote -ne '') { $komitGithub = $barisRemote.Split()[0] }

if ($komitGithub -eq '') {
    Tulis ('BERHENTI: cabang ' + $cabang + ' BELUM ada di GitHub (' + $RemoteUtama + ').')
    Tulis 'Jalankan dulu: powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1'
    Write-Host 'PR_STATUS=BELUM_TERUNGGAH'
    exit 1
}

if ($komitGithub -ne $komitLokal) {
    Tulis 'BERHENTI: commit cabang di komputer ini BEDA dengan yang ada di GitHub.'
    Tulis ('  lokal  : ' + $komitLokal)
    Tulis ('  GitHub : ' + $komitGithub)
    Tulis 'Jalankan dulu: powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1'
    Write-Host 'PR_STATUS=KOMIT_BEDA'
    exit 1
}

Tulis ('Commit cabang TERBUKTI ada di GitHub: ' + $komitGithub)

# ==== 3. Mode -Periksa: apakah commit cabang sudah masuk cabang utama ====
if ($Periksa) {
    Panggil 'git' @('-C', $AkarRepo, 'fetch', '--quiet', $RemoteUtama, $BranchUtama) | Out-Null

    $komitUtama = BarisPertama @('-C', $AkarRepo, 'rev-parse', ('refs/remotes/' + $RemoteUtama + '/' + $BranchUtama))
    $uji        = Panggil 'git' @('-C', $AkarRepo, 'merge-base', '--is-ancestor', $komitLokal, $komitUtama)

    Write-Host ''
    Tulis ('Cabang utama GitHub: ' + $BranchUtama + ' @ ' + $komitUtama)

    if ($uji.Kode -eq 0) {
        Tulis 'SUDAH DI-MERGE: commit cabang ini sudah ada di cabang utama GitHub.'
        Tulis 'Langkah berikutnya: tools\kembalikan-ke-main.ps1 lalu Deploy-Laporan.bat.'
        Write-Host 'PR_STATUS=SUDAH_MERGE'
        exit 0
    }

    Tulis 'BELUM DI-MERGE: commit cabang ini belum ada di cabang utama GitHub.'
    Tulis 'Artinya Pull Request-nya belum dibuat, belum di-merge, atau belum disetujui.'
    Write-Host 'PR_STATUS=BELUM_MERGE'
    exit 1
}

# ==== 4. Susun judul + isi PR ====
$ringkas = $cabang
$pemisah = $cabang.LastIndexOf('/')

if ($pemisah -ge 0) { $ringkas = $cabang.Substring($pemisah + 1) }

if ($BerkasCatatan -eq '') {
    $kandidat = Join-Path $AkarRepo ('tugas\pr-' + $ringkas + '.md')

    if (Test-Path -LiteralPath $kandidat) { $BerkasCatatan = $kandidat }
}

$barisCatatan = @()

if ($BerkasCatatan -ne '' -and (Test-Path -LiteralPath $BerkasCatatan)) {
    $isiCatatan   = [IO.File]::ReadAllText($BerkasCatatan)
    $barisCatatan = @($isiCatatan -split "`r?`n")

    if ($barisCatatan.Count -gt 0 -and $barisCatatan[0].StartsWith('# ')) {
        if ($Judul -eq '') { $Judul = $barisCatatan[0].Substring(2).Trim() }

        $barisCatatan = @($barisCatatan | Select-Object -Skip 1)

        # Buang baris kosong di awal supaya isi PR tidak diawali baris kosong.
        while ($barisCatatan.Count -gt 0 -and ([string]$barisCatatan[0]).Trim() -eq '') {
            $barisCatatan = @($barisCatatan | Select-Object -Skip 1)
        }
    }

    Tulis ('Catatan PR : ' + $BerkasCatatan)
} else {
    Tulis ('Catatan PR : tidak ada (dicari di tugas\pr-' + $ringkas + '.md); isi PR disusun dari data git.')
}

if ($Judul -eq '') {
    $Judul = BarisPertama @('-C', $AkarRepo, 'log', '-1', '--format=%s', (($RemoteUtama + '/' + $BranchUtama) + '..HEAD'))
}

if ($Judul -eq '') { $Judul = 'Pull Request cabang ' + $cabang }

# Daftar commit + ringkasan berkas diambil dari git supaya isi PR tidak pernah
# ketinggalan commit yang baru diunggah.
$daftarKomit = @()
$daftarStat  = @()

foreach ($b in @((Panggil 'git' @('-C', $AkarRepo, 'log', '--oneline', '--no-merges', (($RemoteUtama + '/' + $BranchUtama) + '..HEAD'))).Keluaran)) {
    $teks = ([string]$b).Trim()

    if ($teks -ne '') { $daftarKomit += $teks }
}

foreach ($b in @((Panggil 'git' @('-C', $AkarRepo, 'diff', '--stat', (($RemoteUtama + '/' + $BranchUtama) + '...HEAD'))).Keluaran)) {
    $teks = ([string]$b).Trim()

    if ($teks -ne '') { $daftarStat += $teks }
}

$potongan = New-Object System.Collections.Generic.List[string]

if ($barisCatatan.Count -gt 0) {
    foreach ($b in $barisCatatan) { $potongan.Add($b) }
} else {
    $potongan.Add('## Ringkasan perubahan')
    $potongan.Add('')
    $potongan.Add('Tulis ringkasan pada berkas tugas\pr-' + $ringkas + '.md supaya bagian ini terisi otomatis.')
}

$potongan.Add('')
$potongan.Add('## Commit pada cabang ini (dibuat otomatis dari git)')
$potongan.Add('')
$potongan.Add('```')
foreach ($b in $daftarKomit) { $potongan.Add($b) }
$potongan.Add('```')
$potongan.Add('')
$potongan.Add('## Ringkasan berkas yang berubah (dibuat otomatis dari git)')
$potongan.Add('')
$potongan.Add('```')
foreach ($b in $daftarStat) { $potongan.Add($b) }
$potongan.Add('```')

$teksIsi = ($potongan.ToArray() -join [Environment]::NewLine) + [Environment]::NewLine

# ==== 5. Simpan isi PR ke berkas + papan klip ====
$berkasIsi = Join-Path $env:TEMP ('pr-' + $ringkas + '.md')

[IO.File]::WriteAllText($berkasIsi, $teksIsi, (New-Object System.Text.UTF8Encoding($false)))
Tulis ('Isi PR disimpan di: ' + $berkasIsi + ' (' + $teksIsi.Length + ' karakter)')

$papanKlip = $false

if (-not $TanpaPapanKlip) {
    try {
        Set-Clipboard -Value $teksIsi
        $papanKlip = $true
    } catch {
        $papanKlip = $false
    }
}

if ($papanKlip) {
    Tulis 'Isi PR sudah disalin ke papan klip (siap ditempel dengan Ctrl+V).'
} elseif ($TanpaPapanKlip) {
    Tulis 'Papan klip dilewati (opsi -TanpaPapanKlip); tempel manual dari berkas di bawah.'
} else {
    Tulis 'Isi PR tidak bisa disalin ke papan klip; tempel manual dari berkas di bawah.'
}

if ($CetakIsi) {
    Write-Host ''
    Write-Host '=== ISI PR ==='
    Write-Host $teksIsi
    Write-Host '=== AKHIR ISI PR ==='
}

# ==== 6. Tautan "Compare & pull request" ====
$alamat = $RepoUrl

if ($alamat.EndsWith('.git')) { $alamat = $alamat.Substring(0, $alamat.Length - 4) }

$tautanDasar = $alamat + '/compare/' + $BranchUtama + '...' + $cabang + '?quick_pull=1&title=' + (EncodeUrl $Judul)
$tautanPenuh = $tautanDasar + '&body=' + (EncodeUrl $teksIsi)
$tautan      = $tautanDasar

# GitHub membalas 414 URI Too Long untuk tautan yang terlalu panjang, jadi isi PR
# hanya ikut ke tautan bila tautannya tetap pendek.
if ($tautanPenuh.Length -le 1800) {
    $tautan = $tautanPenuh
    Tulis 'Isi PR ikut dimasukkan ke tautan (tautan masih pendek).'
} else {
    Tulis ('Isi PR tidak dimasukkan ke tautan (' + $tautanPenuh.Length + ' karakter); tempel dengan Ctrl+V di kotak isi PR.')
}

if (-not $TanpaPeramban) {
    try {
        Start-Process $tautan
        Tulis 'Peramban dibuka pada halaman Compare & pull request.'
    } catch {
        Tulis 'Peramban tidak bisa dibuka otomatis; salin tautan di bawah ke peramban.'
    }
}

# ==== 7. Laporan untuk manusia ====
Write-Host ''
Write-Host '=== YANG HARUS DIKLIK MANUSIA (3 langkah) ==='
Write-Host '1. Di halaman yang terbuka, periksa judul + isi PR.'
Write-Host '2. Tekan tombol hijau "Create pull request".'
Write-Host '3. Beri tahu integrator; HANYA integrator yang menekan "Merge pull request".'
Write-Host ''

if ($papanKlip) {
    Write-Host 'Kalau kotak isi PR kosong: klik kotak itu, tekan Ctrl+A lalu Ctrl+V.'
} else {
    Write-Host 'Papan klip tidak dipakai: buka berkas di bawah, salin isinya, lalu tempel di kotak isi PR.'
}

Write-Host 'Isi PR juga tersimpan sebagai berkas berikut:'
Write-Host ('  ' + $berkasIsi)
Write-Host ''
Write-Host 'Sesudah di-merge, kembali ke sini dan jalankan urutan ini:'
Write-Host '  powershell -ExecutionPolicy Bypass -File tools\kembalikan-ke-main.ps1'
Write-Host '  lalu klik Deploy-Laporan.bat'
Write-Host ''
Tulis ('Judul PR : ' + $Judul)
Write-Host ('PR_TAUTAN=' + $tautan)
Write-Host ('PR_BERKAS=' + $berkasIsi)
Write-Host 'PR_STATUS=SIAP'
exit 0
