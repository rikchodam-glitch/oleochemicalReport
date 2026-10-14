<#
    siapkan-deploy.ps1 — menyiapkan copy DEV supaya penjaga deploy LOLOS.

    KENAPA PERLU. tools\penjaga-main-dev.ps1 (yang dipakai Deploy-Laporan.bat)
    memajukan copy DEV ke commit terbaru cabang utama GitHub memakai
    fast-forward. Fast-forward MENULIS ULANG berkas, jadi git MENOLAK bila ada
    berkas ter-track yang belum di-commit DAN berkas itu juga berubah di commit
    yang datang. Kejadian nyata 14/10/2026: `.env.example` diubah sesi lain di
    copy DEV, sementara Pull Request yang di-merge juga mengubahnya, sehingga
    deploy dibatalkan dengan pesan
    "Please commit your changes or stash them before you merge".

    Skrip ini menyelesaikan keadaan itu TANPA menghilangkan pekerjaan siapa pun:
    berkas yang menghalangi DICADANGKAN ke folder backup di luar repo, disimpan
    sementara ke git stash, fast-forward dijalankan, lalu perubahan itu
    DIKEMBALIKAN sebagai perubahan yang tetap BELUM di-commit (tidak dibuatkan
    commit baru atas nama orang lain).

    Yang dikerjakan:
      1. Berhenti bila copy DEV tidak berada di cabang utama (pindahkan dulu
         memakai tools\kembalikan-ke-main.ps1) atau bukan repo git.
      2. Berhenti bila cabang utama DEV punya commit SENDIRI (bercabang): itu
         urusan Pull Request, bukan penyeragaman commit.
      3. Sudah sejajar -> selesai tanpa mengubah apa pun.
      4. Fast-forward apa adanya. Bila ditolak karena berkas belum di-commit:
         cadangkan -> git stash -> fast-forward -> kembalikan (git stash pop).
      5. Bila pengembalian perubahan menemui bentrok (perubahan sesi lain dan
         commit tujuan menyentuh bagian yang berdekatan), KEDUA isi digabung
         (baris penanda bentrok dibuang; tidak ada pekerjaan yang hilang).
         Pakai -JanganGabung untuk berhenti dengan penanda bentrok dibiarkan.

    Skrip ini HANYA menyentuh copy DEV; copy PROD tidak pernah disentuh.

    Parameter:
        -AkarRepo <path>       : copy DEV (bawaan C:\laragon\www\oleochemicalReport).
        -RemoteUtama <nama>    : remote utama (bawaan origin).
        -BranchUtama <nama>    : cabang utama (bawaan main).
        -FolderCadangan <path> : folder cadangan di luar repo (bawaan
                                 C:\laragon\backup-oleochemical\siapkan-deploy).
        -Kering                : hanya melaporkan yang AKAN dikerjakan.
        -JanganGabung          : jangan gabungkan bentrok otomatis.

    Return: exit code 0 = copy DEV sejajar dengan commit cabang utama remote,
            1 = belum (penyebabnya dicetak di layar).
            Penanda keluaran: SIAPKAN_DEPLOY=SIAP|BELUM, KOMIT_DEV=,
            KOMIT_REMOTE=, BERKAS_DIPINDAH=<jumlah>, CADANGAN=<folder>,
            GABUNG_BENTROK=ya|tidak (hanya bila ada bentrok).
#>
param(
    [string]$AkarRepo = 'C:\laragon\www\oleochemicalReport',
    [string]$RemoteUtama = 'origin',
    [string]$BranchUtama = 'main',
    [string]$FolderCadangan = 'C:\laragon\backup-oleochemical\siapkan-deploy',
    [switch]$Kering,
    [switch]$JanganGabung
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres skrip ini.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[siapkan] ' + $Pesan)
}

<#
    Cetak penanda KUNCI=NILAI yang dibaca skrip/manusia lain.

    Parameter: $Status = SIAP|BELUM, $KomitDev/$KomitRemote = hash commit,
               $JumlahPindah = jumlah berkas yang dipindahkan sementara,
               $Folder = folder cadangan (boleh kosong).
    Return  : tidak ada.
#>
function Penanda([string]$Status, [string]$KomitDev, [string]$KomitRemote, [int]$JumlahPindah, [string]$Folder) {
    Write-Host ('KOMIT_DEV=' + $KomitDev)
    Write-Host ('KOMIT_REMOTE=' + $KomitRemote)
    Write-Host ('BERKAS_DIPINDAH=' + $JumlahPindah)
    Write-Host ('CADANGAN=' + $Folder)
    Write-Host ('SIAPKAN_DEPLOY=' + $Status)
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
    Ambil seluruh baris keluaran git yang tidak kosong (spasi tepi dibuang).

    Parameter: $Argumen = argumen git.
    Return  : larik teks.
#>
function BarisSemua([string[]]$Argumen) {
    $hasil  = Panggil 'git' $Argumen
    $daftar = @()

    foreach ($baris in @($hasil.Keluaran)) {
        $teks = ([string]$baris).Trim()

        if ($teks -ne '') { $daftar += $teks }
    }

    return $daftar
}

<#
    Baris `git status --porcelain` untuk berkas TER-TRACK yang belum di-commit
    (berkas belum terlacak/`??` sengaja tidak diikutkan: berkas itu tidak pernah
    menghalangi fast-forward).

    Parameter: tidak ada (memakai $AkarRepo).
    Return  : larik teks porcelain (mis. " M .env.example").
#>
function BarisKotor() {
    return BarisSemua @('-C', $AkarRepo, 'status', '--porcelain', '--untracked-files=no')
}

<#
    Ubah satu baris `git status --porcelain` menjadi path relatif.
    Penamaan ulang ("lama -> baru") diambil bagian barunya.

    Parameter: $Baris = satu baris porcelain.
    Return  : path relatif (string kosong bila baris tidak berbentuk itu).
#>
function PathDariPorcelain([string]$Baris) {
    # Baris porcelain berbentuk dua huruf status lalu path (" M catatan.txt"),
    # tetapi keluaran sudah di-Trim sehingga spasi pertama bisa HILANG. Karena
    # itu hanya DUA huruf pertama yang dibuang; memakai Substring(3) seperti
    # versi pertama membuat huruf pertama path ikut terpotong ("atan.txt"),
    # sehingga berkas penghalang tidak pernah ketemu (ketahuan dari uji nyata).
    if ($Baris.Length -lt 3) { return '' }

    $path = $Baris.Substring(2).Trim().Trim('"')

    if ($path.Contains(' -> ')) { $path = $path.Split(' -> ')[-1].Trim().Trim('"') }

    return $path
}

<#
    Berkas belum-di-commit yang MENGHALANGI fast-forward, yaitu berkas yang juga
    berubah di commit tujuan.

    Parameter: $KomitTujuan = commit yang akan dipasang.
    Return  : larik path relatif (boleh kosong).
#>
function BerkasPenghalang([string]$KomitTujuan) {
    $berubah = BarisSemua @('-C', $AkarRepo, 'diff', '--name-only', 'HEAD', $KomitTujuan)
    $hasil   = @()

    foreach ($baris in BarisKotor) {
        $path = PathDariPorcelain $baris

        if (($path -ne '') -and ($berubah -contains $path) -and ($hasil -notcontains $path)) {
            $hasil += $path
        }
    }

    return $hasil
}

<#
    Gabungkan bentrok hasil `git stash pop` dengan mengambil KEDUA sisi.

    Dipakai ketika perubahan sesi lain dan commit tujuan sama-sama menambah atau
    mengubah bagian yang berdekatan, sehingga git melaporkan "both modified".
    Baris penanda bentrok dibuang dan KEDUA isi dipertahankan, berkas
    dikembalikan ke keadaan "belum di-commit" (di-unstage lagi), lalu sisa
    stasher dibuang.

    Akhiran baris asli TIAP baris dipertahankan (berkas ber-akhiran LF tidak
    berubah menjadi CRLF), dan berkas biner TIDAK pernah diolah.

    Parameter: tidak ada (memakai $AkarRepo).
    Return  : $true bila SELURUH bentrok berhasil digabung.
#>
function GabungkanBentrok() {
    $bentrok = BarisSemua @('-C', $AkarRepo, 'diff', '--name-only', '--diff-filter=U')

    if ($bentrok.Count -eq 0) { return $false }

    foreach ($berkas in $bentrok) {
        $path = Join-Path $AkarRepo $berkas

        if (-not (Test-Path -LiteralPath $path)) { return $false }

        $mentah = [IO.File]::ReadAllBytes($path)

        foreach ($bit in $mentah) {
            if ($bit -eq 0) { return $false }
        }

        $teks    = [IO.File]::ReadAllText($path)
        $potongan = [regex]::Split($teks, '(\r?\n)')
        $keluar   = New-Object System.Collections.Generic.List[string]
        $diDalam  = $false
        $berubah  = $false

        for ($i = 0; $i -lt $potongan.Length; $i += 2) {
            $baris = $potongan[$i]
            $akhir = ''

            if (($i + 1) -lt $potongan.Length) { $akhir = $potongan[$i + 1] }

            if ($baris.StartsWith('<<<<<<<')) { $diDalam = $true; $berubah = $true; continue }
            if ($baris.StartsWith('>>>>>>>')) { $diDalam = $false; $berubah = $true; continue }
            if ($diDalam -and ($baris -eq '=======')) { $berubah = $true; continue }

            $keluar.Add($baris + $akhir)
        }

        if (-not $berubah) { return $false }

        [IO.File]::WriteAllText($path, ($keluar -join ''), (New-Object System.Text.UTF8Encoding($false)))

        Write-Host ('   digabung : ' + $berkas)

        $tambah = Panggil 'git' @('-C', $AkarRepo, 'add', '--', $berkas)

        if ($tambah.Kode -ne 0) { return $false }

        # Di-unstage lagi supaya keadaannya tetap "perubahan belum di-commit"
        # seperti sebelum dipindahkan (bukan perubahan yang siap di-commit).
        Panggil 'git' @('-C', $AkarRepo, 'reset', '--quiet', '--', $berkas) | Out-Null
    }

    $buang = Panggil 'git' @('-C', $AkarRepo, 'stash', 'drop')

    if ($buang.Kode -ne 0) {
        Tulis 'PERINGATAN: stasher tidak bisa dibuang; jalankan `git stash list` lalu `git stash drop`.'
    }

    return $true
}

# ==== 1. Keadaan copy DEV ====
if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    Penanda 'BELUM' '' '' 0 ''
    exit 1
}

Tulis ('Copy DEV : ' + $AkarRepo)

$cabang   = BarisPertama @('-C', $AkarRepo, 'rev-parse', '--abbrev-ref', 'HEAD')
$komitDev = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')

Write-Host ('[siapkan] cabang     : ' + $cabang)
Write-Host ('[siapkan] commit DEV : ' + $komitDev)

if ($cabang -ne $BranchUtama) {
    Tulis ('BERHENTI: copy DEV berada di cabang ' + $cabang + ', bukan ' + $BranchUtama + '.')
    Tulis 'Pindahkan dulu ke cabang utama: powershell -File tools\kembalikan-ke-main.ps1'
    Penanda 'BELUM' $komitDev '' 0 ''
    exit 1
}

# Konflik/rebase yang belum selesai membuat SEMUA operasi git gagal; keadaan itu
# tidak boleh dibereskan otomatis oleh skrip ini.
foreach ($baris in BarisKotor) {
    $kode = $baris.Substring(0, 2).Trim()

    if (@('UU', 'AA', 'DD', 'AU', 'UA', 'DU', 'UD') -contains $kode) {
        Tulis ('BERHENTI: copy DEV sedang menyelesaikan konflik pada ' + (PathDariPorcelain $baris) + '.')
        Tulis 'Selesaikan konfliknya lebih dulu (git status), baru jalankan skrip ini lagi.'
        Penanda 'BELUM' $komitDev '' 0 ''
        exit 1
    }
}

# ==== 2. Commit tujuan (cabang utama remote) ====
$fetch = Panggil 'git' @('-C', $AkarRepo, 'fetch', '--quiet', $RemoteUtama, $BranchUtama)

if ($fetch.Kode -ne 0) {
    Tulis ('PERINGATAN: fetch ' + $RemoteUtama + ' gagal; memakai ref yang sudah ada di komputer ini.')
}

$refRemote   = $RemoteUtama + '/' + $BranchUtama
$komitRemote = BarisPertama @('-C', $AkarRepo, 'rev-parse', $refRemote)

if ($komitRemote -eq '') {
    Tulis ('GAGAL: ref ' + $refRemote + ' tidak terbaca; tidak ada yang diubah.')
    Penanda 'BELUM' $komitDev '' 0 ''
    exit 1
}

Write-Host ('[siapkan] commit ' + $refRemote + ' : ' + $komitRemote)

if ($komitDev -eq $komitRemote) {
    Tulis 'SUDAH SEJAJAR: tidak ada yang perlu dikerjakan.'
    Penanda 'SIAP' $komitDev $komitRemote 0 ''
    exit 0
}

# ==== 3. Bercabang? ====
$leluhur = ((Panggil 'git' @('-C', $AkarRepo, 'merge-base', '--is-ancestor', $komitDev, $komitRemote)).Kode -eq 0)

if (-not $leluhur) {
    Write-Host ''
    Tulis 'BERHENTI: cabang utama DEV punya commit yang TIDAK ada di commit remote.'
    Tulis 'Ini urusan Pull Request, bukan penyeragaman commit. Yang perlu dilakukan:'
    Write-Host '   1. powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1'
    Write-Host '   2. buka Pull Request, minta integrator meng-merge'
    Write-Host '   3. powershell -ExecutionPolicy Bypass -File tools\kembalikan-ke-main.ps1'
    Write-Host ''

    foreach ($baris in BarisSemua @('-C', $AkarRepo, 'log', '--oneline', '-5', ($komitRemote + '..HEAD'))) {
        Write-Host ('   dev belum di-merge : ' + $baris)
    }

    Write-Host ''
    Penanda 'BELUM' $komitDev $komitRemote 0 ''
    exit 1
}

# ==== 4. Fast-forward apa adanya (tanpa mengubah berkas siapa pun) ====
$maju = Panggil 'git' @('-C', $AkarRepo, 'merge', '--ff-only', $refRemote)

if ($maju.Kode -eq 0) {
    $komitBaru = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')
    Tulis ('BERHASIL: copy DEV dimajukan ke ' + $komitBaru + ' (tanpa berkas penghalang).')
    Penanda 'SIAP' $komitBaru $komitRemote 0 ''
    exit 0
}

# ==== 5. Berkas belum-di-commit yang menghalangi fast-forward ====
$penghalang = @(BerkasPenghalang $komitRemote)

if ($penghalang.Count -eq 0) {
    Tulis 'GAGAL: fast-forward ditolak, tetapi bukan karena berkas yang belum di-commit.'
    Tulis 'Periksa sebabnya pada keluaran git di bawah ini.'

    foreach ($baris in @($maju.Keluaran | Select-Object -Last 8)) { Write-Host ('   ' + ([string]$baris).Trim()) }

    Penanda 'BELUM' $komitDev $komitRemote 0 ''
    exit 1
}

Write-Host ''
Tulis ('Fast-forward terhalang ' + $penghalang.Count + ' berkas yang belum di-commit (pekerjaan sesi lain):')

foreach ($berkas in $penghalang) { Write-Host ('   - ' + $berkas) }

Write-Host ''
Tulis 'Git tidak boleh menimpa berkas itu. Berkas tersebut akan DICADANGKAN,'
Tulis 'dipindahkan sementara ke git stash, lalu DIKEMBALIKAN apa adanya.'

Write-Host ''

if ($Kering) {
    Tulis 'SIMULASI (-Kering): tidak ada yang diubah. Jalankan tanpa -Kering untuk mengerjakannya.'
    Penanda 'BELUM' $komitDev $komitRemote $penghalang.Count ''
    exit 1
}

# ==== 6. Cadangkan ke luar repo (jaring pengaman kedua setelah git stash) ====
$stempel   = (Get-Date).ToString('yyyyMMdd-HHmmss')
$folderTuj = Join-Path $FolderCadangan ('dev-' + $stempel)

try {
    New-Item -ItemType Directory -Path $folderTuj -Force | Out-Null

    foreach ($berkas in $penghalang) {
        $tujuan = Join-Path $folderTuj $berkas

        New-Item -ItemType Directory -Path (Split-Path -Parent $tujuan) -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path $AkarRepo $berkas) -Destination $tujuan -Force
    }

    $catatan   = @()
    $catatan  += 'Cadangan berkas yang menghalangi fast-forward copy DEV'
    $catatan  += ''
    $catatan  += 'Waktu        : ' + $stempel + ' WIB'
    $catatan  += 'Copy DEV     : ' + $AkarRepo
    $catatan  += 'Commit DEV   : ' + $komitDev + ' (sebelum dimajukan)'
    $catatan  += 'Commit tujuan : ' + $komitRemote
    $catatan  += ''
    $catatan  += 'Berkas (isinya PERSIS seperti sebelum dipindahkan):'

    foreach ($berkas in $penghalang) { $catatan += '  - ' + $berkas }

    $catatan  += ''
    $catatan  += 'Sebab     : berkas ter-track belum di-commit ini juga berubah di commit tujuan,'
    $catatan  += '            sehingga git menolak memajukan copy DEV.'
    $catatan  += 'Tindakan  : berkas disalin ke folder ini, dipindahkan sementara ke git stash,'
    $catatan  += '            fast-forward dijalankan, lalu perubahannya dikembalikan (git stash pop).'
    $catatan  += 'Memulihkan: salin berkas dari folder ini kembali ke copy DEV, atau jalankan'
    $catatan  += '            `git stash list` lalu `git stash pop`.'

    [IO.File]::WriteAllText((Join-Path $folderTuj 'CATATAN.txt'), (($catatan -join "`n") + "`n"), (New-Object System.Text.UTF8Encoding($false)))
} catch {
    Tulis ('GAGAL: pencadangan gagal (' + $_.Exception.Message + '); copy DEV TIDAK diubah.')
    Penanda 'BELUM' $komitDev $komitRemote 0 ''
    exit 1
}

Tulis ('Cadangan dibuat: ' + $folderTuj)

# ==== 7. Pindahkan berkas penghalang sementara ke git stash ====
$argumenStash  = @('-C', $AkarRepo, 'stash', 'push', '-m', ('siapkan-deploy ' + $stempel + ': berkas belum di-commit yang menghalangi fast-forward'), '--')
$argumenStash += $penghalang

$stash = Panggil 'git' $argumenStash

if ($stash.Kode -ne 0) {
    Tulis 'GAGAL: git stash gagal; copy DEV TIDAK diubah (cadangan tetap ada).'

    foreach ($baris in @($stash.Keluaran | Select-Object -Last 8)) { Write-Host ('   ' + ([string]$baris).Trim()) }

    Penanda 'BELUM' $komitDev $komitRemote 0 $folderTuj
    exit 1
}

Tulis 'Berkas penghalang dipindahkan sementara ke git stash.'

# ==== 8. Fast-forward ====
$maju2 = Panggil 'git' @('-C', $AkarRepo, 'merge', '--ff-only', $refRemote)

if ($maju2.Kode -ne 0) {
    Tulis 'GAGAL: fast-forward tetap ditolak; perubahan dikembalikan dari git stash.'

    foreach ($baris in @($maju2.Keluaran | Select-Object -Last 8)) { Write-Host ('   ' + ([string]$baris).Trim()) }

    $batal = Panggil 'git' @('-C', $AkarRepo, 'stash', 'pop')

    if ($batal.Kode -ne 0) { Tulis 'PERHATIAN: pengembalian dari stash gagal; jalankan `git stash list` lalu `git stash pop`.' }

    Penanda 'BELUM' $komitDev $komitRemote 0 $folderTuj
    exit 1
}

$komitBaru = BarisPertama @('-C', $AkarRepo, 'rev-parse', 'HEAD')
Tulis ('Copy DEV sudah dimajukan ke ' + $komitBaru + '.')

# ==== 9. Kembalikan perubahan sesi lain (tetap BELUM di-commit) ====
$kembali = Panggil 'git' @('-C', $AkarRepo, 'stash', 'pop')

if ($kembali.Kode -ne 0) {
    Write-Host ''
    Tulis 'Pengembalian perubahan (git stash pop) menemui bentrok.'
    Tulis 'Sebabnya: perubahan sesi lain dan commit tujuan menyentuh bagian yang berdekatan.'

    $digabung = $false

    if ($JanganGabung) {
        Tulis 'Penggabungan otomatis dimatikan oleh opsi -JanganGabung.'
    } else {
        Tulis 'Menggabungkan dengan mengambil KEDUA sisi (isi keduanya dipertahankan):'

        $digabung = GabungkanBentrok
    }

    if (-not $digabung) {
        Tulis 'Perubahan itu TIDAK hilang: masih tersimpan di git stash dan salinannya ada di folder cadangan.'
        Write-Host ('   salinan : ' + $folderTuj)
        Write-Host ''

        foreach ($baris in @($kembali.Keluaran | Select-Object -Last 10)) { Write-Host ('   ' + ([string]$baris).Trim()) }

        Write-Host ''
        Tulis 'Selesaikan bentrok pada berkas di atas (buang penanda <<<<<<< ======= >>>>>>>),'
        Tulis 'lalu jalankan `git add <berkas>` dan `git stash drop`, baru ulangi Deploy-Laporan.bat.'
        Write-Host 'GABUNG_BENTROK=tidak'
        Penanda 'BELUM' $komitBaru $komitRemote $penghalang.Count $folderTuj
        exit 1
    }

    Tulis 'Bentrok digabung: isi perubahan sesi lain DAN isi commit tujuan sama-sama dipertahankan.'
    Tulis 'Periksa hasilnya dengan `git diff` bila ingin lebih yakin.'
    Write-Host 'GABUNG_BENTROK=ya'
}

Tulis 'Perubahan sesi lain dikembalikan sebagai perubahan yang BELUM di-commit (tanpa commit baru).'
Write-Host ''
Tulis 'SELESAI: copy DEV sejajar dengan commit cabang utama remote.'
Tulis 'Langkah berikutnya: klik Deploy-Laporan.bat.'
Penanda 'SIAP' $komitBaru $komitRemote $penghalang.Count $folderTuj
exit 0
