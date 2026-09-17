@extends('layouts.app')

@section('title', 'Storage')

@section('content')
<style>
    .storage-page { display: flex; flex-direction: column; gap: 1.25rem; max-width: 1200px; }
    .storage-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
    .storage-head h1 { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
    .storage-head p { color: #64748b; font-size: .875rem; margin-top: .25rem; }
    .storage-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 1rem; }
    .storage-card { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1rem; }
    .storage-card .label { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .storage-card .value { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: .25rem; }
    .storage-card .sub { font-size: .75rem; color: #94a3b8; margin-top: .125rem; word-break: break-all; }
    .storage-section { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1.25rem; }
    .storage-section h2 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: .75rem; }
    .storage-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .storage-table th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: .5rem; border-bottom: 1px solid #e2e8f0; }
    .storage-table td { padding: .5rem; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .storage-badge { display: inline-block; padding: .125rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
    .badge-ssd { background: #ccfbf1; color: #0f766e; }
    .badge-hdd { background: #e0e7ff; color: #3730a3; }
    .badge-other { background: #f1f5f9; color: #475569; }
    .badge-teal { background: #0E9E8E; color: #fff; }
    .storage-bar { height: .5rem; border-radius: 999px; background: #f1f5f9; overflow: hidden; min-width: 90px; }
    .storage-bar span { display: block; height: 100%; background: #0E9E8E; }
    .storage-bar.warn span { background: #f59e0b; }
    .storage-bar.danger span { background: #ef4444; }
    .storage-btn { display: inline-flex; align-items: center; gap: .375rem; padding: .5rem .875rem; border-radius: .5rem; font-size: .8125rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; }
    .btn-teal { background: #0E9E8E; color: #fff; }
    .btn-teal:hover { background: #0b8578; }
    .btn-ghost { background: #fff; border-color: #cbd5e1; color: #334155; }
    .btn-danger { background: #dc2626; color: #fff; }
    .storage-alert { border-radius: .5rem; padding: .75rem 1rem; font-size: .875rem; }
    .alert-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .alert-fail { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .storage-log { background: #0f172a; color: #e2e8f0; border-radius: .5rem; padding: 1rem; font-size: .8125rem; white-space: pre-wrap; overflow-x: auto; }
    .storage-form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: flex-end; margin-top: .75rem; }
    .storage-field { display: flex; flex-direction: column; gap: .25rem; }
    .storage-field label { font-size: .75rem; color: #64748b; font-weight: 600; }
    .storage-field input, .storage-field select { border: 1px solid #cbd5e1; border-radius: .5rem; padding: .5rem .625rem; font-size: .8125rem; }
    .storage-hint { font-size: .75rem; color: #64748b; margin-top: .5rem; }
    .storage-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; word-break: break-all; }
</style>

@php
    $targets = $report['targets'];
    $snap = $report['snapshots'];
    $activeDb = $report['databases'][0] ?? null;
    $storageDrive = $targets['app_real_path'] !== null ? strtoupper(substr($targets['app_real_path'], 0, 1)) : null;
    $storageDisk = null;

    foreach ($report['disks'] as $disk) {
        if ($disk['letter'] === $storageDrive) {
            $storageDisk = $disk;
        }
    }
@endphp

<div class="storage-page">
    <div class="storage-head">
        <div>
            <h1>Storage &amp; Penyimpanan</h1>
            <p>Deteksi drive, pemakaian folder dan database, pembersih snapshot otomatis, serta pemindahan data ke HDD.</p>
        </div>
        <a href="{{ route('storage.index', ['fresh' => 1]) }}" class="storage-btn btn-teal">Segarkan data</a>
    </div>

    @if (session('storage_title'))
        <div class="storage-alert {{ session('storage_status') === 'fail' ? 'alert-fail' : 'alert-ok' }}">
            <strong>{{ session('storage_title') }}</strong>
            @if (session('storage_error'))
                <div>{{ session('storage_error') }}</div>
            @endif
        </div>
    @endif

    @if (session('storage_log'))
        <pre class="storage-log">@foreach (session('storage_log') as $line){{ $line }}
@endforeach</pre>
    @endif

    @if ($plan)
        <div class="storage-alert {{ $plan['ok'] ? 'alert-ok' : 'alert-fail' }}">
            <strong>Pemeriksaan kelayakan pemindahan ({{ $plan['ok'] ? 'siap dijalankan' : 'belum bisa dijalankan' }})</strong>
            <div class="storage-mono">Sumber: {{ $plan['source'] }}</div>
            <div class="storage-mono">Tujuan: {{ $plan['target'] }}</div>
            @foreach ($plan['problems'] as $problem)
                <div>- {{ $problem }}</div>
            @endforeach
            @foreach (($plan['notes'] ?? []) as $note)
                <div>- {{ $note }}</div>
            @endforeach
        </div>
    @endif

    <div class="storage-cards">
        <div class="storage-card">
            <div class="label">Data aplikasi</div>
            <div class="value">{{ $targets['app_is_linked'] ? 'Di drive lain' : 'Di drive project' }}</div>
            <div class="sub">{{ $targets['app_real_path'] ?? 'belum terdeteksi' }}</div>
        </div>
        <div class="storage-card">
            <div class="label">Sisa ruang drive data</div>
            <div class="value">{{ $storageDisk ? number_format($storageDisk['free'] / 1073741824, 1) . ' GB' : '-' }}</div>
            <div class="sub">Drive {{ $storageDrive ?? '-' }}: {{ $storageDisk['model'] ?? '' }}</div>
        </div>
        <div class="storage-card">
            <div class="label">Snapshot CCTV</div>
            <div class="value">{{ number_format($snap['total_bytes'] / 1048576, 0) }} MB</div>
            <div class="sub">{{ number_format($snap['total_files']) }} berkas &middot; retensi {{ $snap['retention_days'] }} hari</div>
        </div>
        <div class="storage-card">
            <div class="label">Database terbesar</div>
            <div class="value">{{ $activeDb ? number_format($activeDb['bytes'] / 1048576, 1) . ' MB' : '-' }}</div>
            <div class="sub">{{ $activeDb['name'] ?? '-' }} &middot; {{ $activeDb['tables'] ?? 0 }} tabel</div>
        </div>
    </div>

    <div class="storage-section">
        <h2>Drive pada PC ini</h2>
        <table class="storage-table">
            <thead>
                <tr>
                    <th>Drive</th>
                    <th>Jenis</th>
                    <th>Model</th>
                    <th>Kapasitas</th>
                    <th>Sisa</th>
                    <th>Terpakai</th>
                    <th>Data aplikasi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['disks'] as $disk)
                    <tr>
                        <td><strong>{{ $disk['letter'] }}:</strong> {{ $disk['label'] }}</td>
                        <td>
                            <span class="storage-badge {{ $disk['media'] === 'SSD' ? 'badge-ssd' : ($disk['media'] === 'HDD' ? 'badge-hdd' : 'badge-other') }}">{{ $disk['media'] }}</span>
                        </td>
                        <td>{{ $disk['model'] !== '' ? $disk['model'] : '-' }}</td>
                        <td>{{ number_format($disk['size'] / 1073741824, 1) }} GB</td>
                        <td>{{ number_format($disk['free'] / 1073741824, 1) }} GB</td>
                        <td>
                            <div class="storage-bar {{ $disk['used_pct'] >= 90 ? 'danger' : ($disk['used_pct'] >= 75 ? 'warn' : '') }}">
                                <span style="width: {{ $disk['used_pct'] }}%"></span>
                            </div>
                            <div class="storage-hint">{{ $disk['used_pct'] }}%</div>
                        </td>
                        <td>
                            @if ($disk['letter'] === $storageDrive)
                                <span class="storage-badge badge-teal">aktif</span>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="storage-section">
        <h2>Pemakaian folder penyimpanan</h2>
        <table class="storage-table">
            <thead>
                <tr>
                    <th>Folder</th>
                    <th>Ukuran</th>
                    <th>Berkas</th>
                    <th>Lokasi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['folders'] as $label => $folder)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ $folder['bytes'] >= 1073741824
                            ? number_format($folder['bytes'] / 1073741824, 2) . ' GB'
                            : number_format($folder['bytes'] / 1048576, 1) . ' MB' }}</td>
                        <td>{{ number_format($folder['files']) }}</td>
                        <td class="storage-mono">{{ $folder['path'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="storage-section">
        <h2>Database MySQL</h2>
        <table class="storage-table">
            <thead>
                <tr>
                    <th>Database</th>
                    <th>Ukuran</th>
                    <th>Tabel</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['databases'] as $database)
                    <tr>
                        <td>{{ $database['name'] }}</td>
                        <td>{{ number_format($database['bytes'] / 1048576, 1) }} MB</td>
                        <td>{{ number_format($database['tables']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="storage-hint">
            my.ini: {{ $targets['mysql_ini'] ?? 'tidak ditemukan' }} &middot;
            datadir: {{ $targets['mysql_datadir'] ?? 'tidak ditemukan' }}
        </div>
    </div>

    <div class="storage-section">
        <h2>Snapshot CCTV &amp; pembersih otomatis</h2>
        <p class="storage-hint">
            Lokasi: <span class="storage-mono">{{ $snap['root'] }}</span><br>
            Total {{ number_format($snap['total_files']) }} berkas ({{ number_format($snap['total_bytes'] / 1048576, 1) }} MB).
            Retensi aktif {{ $snap['retention_days'] }} hari, berkas yang melewati batas dihapus otomatis setiap hari pukul 02:30.
            Saat ini ada {{ number_format($snap['old_files']) }} berkas ({{ number_format($snap['old_bytes'] / 1048576, 1) }} MB) yang melewati batas retensi.
        </p>

        @if ($snap['per_day'] !== [])
            <table class="storage-table" style="margin-top: .75rem;">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Berkas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($snap['per_day'], -7, 7, true) as $date => $count)
                        <tr>
                            <td>{{ $date }}</td>
                            <td>{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <form method="POST" action="{{ route('storage.clean') }}" class="storage-form">
            @csrf
            <div class="storage-field">
                <label for="clean-days">Retensi (hari)</label>
                <input type="number" id="clean-days" name="days" min="1" max="3650" value="{{ $snap['retention_days'] }}">
            </div>
            <button type="submit" name="mode" value="dry" class="storage-btn btn-ghost">Uji coba (tidak menghapus)</button>
            <button type="submit" name="mode" value="force" class="storage-btn btn-danger"
                    onclick="return confirm('Hapus permanen semua snapshot yang lebih tua dari batas retensi?')">Hapus sekarang</button>
        </form>
    </div>

    <div class="storage-section">
        <h2>Lokasi penyimpanan &amp; pemindahan ke HDD</h2>
        <table class="storage-table">
            <tbody>
                <tr>
                    <td>storage/app</td>
                    <td class="storage-mono">{{ $targets['app_path'] }}</td>
                </tr>
                <tr>
                    <td>Menunjuk ke</td>
                    <td class="storage-mono">
                        {{ $targets['app_real_path'] ?? '-' }}
                        @if ($targets['app_is_linked'])
                            <span class="storage-badge badge-teal">junction</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>public/storage</td>
                    <td class="storage-mono">{{ $targets['public_target'] ?? '-' }}</td>
                </tr>
            </tbody>
        </table>

        <p class="storage-hint">
            Pemindahan memindahkan seluruh isi storage/app (snapshot CCTV, foto Telegram, berkas denah) ke drive tujuan,
            lalu mengganti storage/app dengan junction sehingga tidak ada kode maupun URL yang perlu diubah.
            Prosesnya menyalin data terlebih dahulu dan hanya menghapus data lama setelah hasil salinan diverifikasi.
        </p>

        <form method="POST" action="{{ route('storage.relocate') }}" class="storage-form">
            @csrf
            <div class="storage-field">
                <label for="relocate-target">Folder induk tujuan</label>
                <input type="text" id="relocate-target" name="target" value="{{ $target }}"
                       placeholder="D:/oleochemical-data" list="storage-drives" style="min-width: 320px;">
                <datalist id="storage-drives">
                    @foreach ($report['disks'] as $disk)
                        @if ($disk['letter'] !== $storageDrive)
                            <option value="{{ $disk['letter'] }}:/oleochemical-data">
                                {{ $disk['letter'] }}: ({{ $disk['media'] }}, sisa {{ number_format($disk['free'] / 1073741824, 1) }} GB)
                            </option>
                        @endif
                    @endforeach
                </datalist>
            </div>
            <button type="submit" name="mode" value="plan" class="storage-btn btn-ghost">Periksa kelayakan</button>
            <button type="submit" name="mode" value="force" class="storage-btn btn-teal"
                    onclick="return confirm('Pindahkan seluruh data penyimpanan aplikasi ke drive tujuan sekarang?')">Pindahkan sekarang</button>
        </form>
        <div class="storage-hint">
            Setelah selesai, kartu di atas otomatis menunjuk ke drive baru. Perintah yang sama bisa dijalankan ulang kapan pun
            saat mengganti atau menambah HDD, termasuk lewat terminal: php artisan storage:relocate &lt;tujuan&gt; --force
        </div>
    </div>
</div>
@endsection


