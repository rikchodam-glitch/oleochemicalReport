<#
    mulai-tugas.ps1 — mulai satu tugas baru di cabang sendiri.

    Dipakai SETIAP teknisi (dan asisten AI-nya) sebelum menyunting apa pun.
    Tujuannya: pekerjaan tidak pernah dikerjakan langsung di cabang `main`,
    sehingga beberapa orang bisa bekerja bersamaan tanpa saling menimpa.

    Yang dikerjakan:
      1. Menolak jalan bila masih ada berkas ter-track yang belum di-commit
         (kecuali -AbaikanKotor; -PindahkanPerubahan menyimpannya ke stash).
      2. git fetch origin main, checkout main, lalu majukan main (fast-forward).
      3. Membuat/masuk cabang tugas: tugas/<nama>/<ringkas-tugas>.
      4. Memperbarui papan klaim tugas/<nama>.md (satu berkas per orang sehingga
         berkas itu sendiri tidak pernah menimbulkan konflik).
      5. Menampilkan pekerjaan teknisi LAIN yang tercatat, supaya berkas yang
         sama bisa dihindari sejak awal.

    Parameter:
        -Nama <teks>        : WAJIB. Nama pendek teknisi (mis. vicky).
        -Tugas <teks>       : ringkasan tugas (mis. "perbaiki man hour kolaborasi").
        -AkarRepo <path>    : folder repo (bawaan: folder induk folder skrip ini).
        -RemoteUtama <nama> : remote GitHub (bawaan origin).
        -AbaikanKotor       : lanjut walau ada berkas ter-track belum di-commit.
        -PindahkanPerubahan : simpan perubahan yang belum di-commit ke git stash.

    Return: exit code 0 = cabang tugas siap dipakai, 1 = gagal/dibatalkan.
#>
param(
    [Parameter(Mandatory = $true)][string]$Nama,
    [string]$Tugas = '',
    [string]$AkarRepo = '',
    [string]$RemoteUtama = 'origin',
    [switch]$AbaikanKotor,
    [switch]$PindahkanPerubahan
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[mulai] ' + $Pesan)
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
    Ubah teks menjadi potongan nama cabang/berkas: huruf kecil, hanya huruf,
    angka, dan tanda hubung.

    Parameter: $Teks = teks asal.
    Return  : teks yang aman dipakai di nama cabang (boleh kosong).
#>
function Slug([string]$Teks) {
    $hasil = ([string]$Teks).ToLower().Trim()
    $hasil = [regex]::Replace($hasil, '[^a-z0-9]+', '-')
    return $hasil.Trim('-')
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

# ==== LOGIKA MULAI TUGAS ====

if ($AkarRepo -eq '') { $AkarRepo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path }

if (-not (Test-Path (Join-Path $AkarRepo '.git'))) {
    Tulis ('GAGAL: bukan repo git: ' + $AkarRepo)
    exit 1
}

$slugNama = Slug $Nama

if ($slugNama -eq '') {
    Tulis 'GAGAL: -Nama tidak boleh kosong.'
    exit 1
}

# ==== 1. Berkas yang belum di-commit ====
$status = Panggil 'git' @('-C', $AkarRepo, 'status', '--porcelain', '--untracked-files=no')
$kotor  = @()

foreach ($baris in @($status.Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -ne '') { $kotor += $teks }
}

if ($kotor.Count -gt 0) {
    if ($PindahkanPerubahan) {
        $pesanStash = 'mulai-tugas ' + $slugNama + ' ' + (Get-Date).ToString('yyyyMMdd-HHmmss')
        $stash      = Panggil 'git' @('-C', $AkarRepo, 'stash', 'push', '-m', $pesanStash)

        if ($stash.Kode -ne 0) {
            Tulis 'GAGAL: perubahan belum di-commit tidak bisa disimpan ke stash.'
            exit 1
        }

        Tulis ('Perubahan belum di-commit disimpan ke stash: ' + $pesanStash)
        Tulis 'Pulihkan nanti dengan: git stash list, lalu git stash pop'
    } elseif (-not $AbaikanKotor) {
        Tulis ('GAGAL: masih ada ' + $kotor.Count + ' berkas ter-track yang belum di-commit:')

        foreach ($baris in ($kotor | Select-Object -First 15)) { Write-Host ('       ' + $baris) }

        Tulis 'Commit dulu (jangan `git add -A`), atau pakai -PindahkanPerubahan / -AbaikanKotor.'
        exit 1
    } else {
        Tulis ('PERINGATAN: ' + $kotor.Count + ' berkas ter-track belum di-commit (diizinkan lewat -AbaikanKotor).')
    }
}

# ==== 2. Segarkan main ====
$ambil = Panggil 'git' @('-C', $AkarRepo, 'fetch', $RemoteUtama, 'main')

if ($ambil.Kode -ne 0) {
    Tulis ('GAGAL: git fetch ' + $RemoteUtama + ' main gagal. Periksa koneksi internet / kredensial GitHub.')
    exit 1
}

$pindah = Panggil 'git' @('-C', $AkarRepo, 'checkout', 'main')

if ($pindah.Kode -ne 0) {
    Tulis 'GAGAL: tidak bisa berpindah ke cabang main.'
    foreach ($baris in @($pindah.Keluaran | Select-Object -Last 4)) { Write-Host ('       ' + ([string]$baris).Trim()) }
    exit 1
}

$maju = Panggil 'git' @('-C', $AkarRepo, 'merge', '--ff-only', ($RemoteUtama + '/main'))

if ($maju.Kode -ne 0) {
    Tulis 'GAGAL: cabang main di copy ini TIDAK bisa dimajukan ke main GitHub.'
    Tulis 'Artinya main lokal punya commit sendiri, dan itu DILARANG (lihat AGENTS.md).'
    Tulis 'Pindahkan commit itu ke cabang: git branch <nama-cabang>, lalu minta integrator'
    Tulis 'membantu merapikan main. JANGAN bekerja langsung di main.'
    exit 1
}

Tulis ('main sudah sejajar dengan ' + $RemoteUtama + '/main.')

# ==== 3. Cabang tugas ====
$slugTugas = Slug $Tugas

if ($slugTugas -eq '') { $slugTugas = 'tugas-' + (Get-Date).ToString('yyyyMMdd') }

$cabang    = 'tugas/' + $slugNama + '/' + $slugTugas
$adaCabang = Panggil 'git' @('-C', $AkarRepo, 'show-ref', '--verify', '--quiet', ('refs/heads/' + $cabang))

if ($adaCabang.Kode -eq 0) {
    $masuk = Panggil 'git' @('-C', $AkarRepo, 'checkout', $cabang)
    Tulis ('Melanjutkan cabang tugas yang sudah ada: ' + $cabang)
} else {
    $masuk = Panggil 'git' @('-C', $AkarRepo, 'checkout', '-b', $cabang)
    Tulis ('Cabang tugas dibuat: ' + $cabang)
}

if ($masuk.Kode -ne 0) {
    Tulis 'GAGAL: tidak bisa membuat/masuk cabang tugas.'
    foreach ($baris in @($masuk.Keluaran | Select-Object -Last 4)) { Write-Host ('       ' + ([string]$baris).Trim()) }
    exit 1
}

# ==== 4. Papan klaim tugas/<nama>.md ====
$folderKlaim = Join-Path $AkarRepo 'tugas'
$fileKlaim   = Join-Path $folderKlaim ($slugNama + '.md')

if (-not (Test-Path $folderKlaim)) { New-Item -ItemType Directory -Path $folderKlaim -Force | Out-Null }

$mulai      = (Get-Date).ToString('yyyy-MM-dd HH:mm')
$sebelumnya = ''

if (Test-Path $fileKlaim) {
    $isiLama = [string](Get-Content -LiteralPath $fileKlaim -Raw)
    $cocok   = [regex]::Match($isiLama, '- Cabang aktif:\s*`([^`]*)`[\s\S]*?- Mulai:\s*([^\r\n]*)')

    if ($cocok.Success) {
        $sebelumnya = $cocok.Groups[1].Value.Trim() + ' (mulai ' + $cocok.Groups[2].Value.Trim() + ')'
    }
}

$isi = New-Object System.Collections.Generic.List[string]

$isi.Add('# Papan Klaim: ' + $Nama)
$isi.Add('')
$isi.Add('Diperbarui: ' + $mulai + ' WIB')
$isi.Add('')
$isi.Add('- Cabang aktif: `' + $cabang + '`')
$isi.Add('- Mulai: ' + $mulai)
$isi.Add('- Status: SEDANG DIKERJAKAN')
$isi.Add('- Modul/berkas yang disentuh:')
$isi.Add('  - (WAJIB diisi AI sebelum menyunting: daftar berkas/modul yang akan diubah)')
$isi.Add('- Catatan: (kosongkan bila tidak ada)')
$isi.Add('')
$isi.Add('## Riwayat')

if ($sebelumnya -ne '') { $isi.Add('- ' + $sebelumnya + ' -> dilanjutkan ' + $mulai) }

# Ditulis dengan LF dan tanpa BOM supaya cocok dengan .gitattributes (eol=lf)
# dan tidak membuat berkas tampak berubah padahal isinya sama.
[IO.File]::WriteAllText($fileKlaim, (($isi -join "`n") + "`n"), (New-Object System.Text.UTF8Encoding($false)))
Tulis ('Papan klaim diperbarui: ' + $fileKlaim)

# ==== 5. Pekerjaan teknisi lain ====
$punyaOrangLain = @(Get-ChildItem -LiteralPath $folderKlaim -Filter '*.md' -File -ErrorAction SilentlyContinue |
    Where-Object { $_.BaseName -ne $slugNama -and $_.BaseName -ne 'README' })

Write-Host ''

if ($punyaOrangLain.Count -eq 0) {
    Tulis 'Belum ada teknisi lain yang mencatat pekerjaan di papan klaim.'
} else {
    Tulis 'Pekerjaan teknisi lain yang tercatat (HINDARI berkas yang sama):'

    foreach ($berkas in $punyaOrangLain) {
        $isiLain    = [string](Get-Content -LiteralPath $berkas.FullName -Raw)
        $cabangLain = [regex]::Match($isiLain, '- Cabang aktif:\s*`([^`]*)`')
        $statusLain = [regex]::Match($isiLain, '- Status:\s*([^\r\n]*)')
        $modulLain  = [regex]::Match($isiLain, '- Modul/berkas yang disentuh:([\s\S]*?)(?:\r?\n- Catatan:)')

        Write-Host ('   * ' + $berkas.BaseName + ' | cabang: ' + $cabangLain.Groups[1].Value.Trim() + ' | status: ' + $statusLain.Groups[1].Value.Trim())

        foreach ($bagian in ($modulLain.Groups[1].Value -split "`n")) {
            $teks = $bagian.Trim()

            if ($teks.StartsWith('-')) { Write-Host ('       ' + $teks) }
        }
    }
}

Write-Host ''
Tulis 'Langkah berikutnya:'
Write-Host '   1. Isi bagian "Modul/berkas yang disentuh" pada berkas papan klaim.'
Write-Host '   2. Kerjakan perubahan, uji dengan: php artisan test'
Write-Host '   3. Commit (hanya berkas yang relevan), lalu:'
Write-Host '      powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1'
Write-Host ''
Tulis ('CABANG_TUGAS=' + $cabang)
exit 0
