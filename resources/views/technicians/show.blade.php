@extends('layouts.app')

@section('title', 'Detail Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.index') }}" class="hover:text-slate-700">Teknisi</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $technician->name }}</span>
@endsection

@section('header-actions')
    <button onclick="broadcastMessage()"
            class="inline-flex items-center gap-2 px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
        </svg>
        Kirim Pesan Telegram
    </button>
    <a href="{{ route('technicians.edit', $technician) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors">
        Edit Teknisi
    </a>
    <form method="POST" action="{{ route('technicians.destroy', $technician) }}" class="inline"
          onsubmit="return confirm('Hapus teknisi ini? Semua laporan terkait akan tetap tersimpan.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 border border-red-300 hover:bg-red-50 text-red-700 text-sm font-medium rounded-lg transition-colors">
            Hapus
        </button>
    </form>
@endsection

@section('content')
    <div x-data="technicianDetail()" class="space-y-6">

        <!-- Profile + Stats Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile -->
            <div>
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="text-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto">
                            <span class="text-2xl font-bold text-blue-600">{{ substr($technician->name, 0, 1) }}</span>
                        </div>
                        <h3 class="font-semibold text-slate-900 mt-3">{{ $technician->name }}</h3>
                        <x-status-badge :status="$technician->status" class="mt-1" />
                    </div>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">NIK</dt>
                            <dd class="font-medium text-slate-900 font-mono">{{ $technician->nik ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Group</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->group ? (\App\Models\Technician::GROUPS[$technician->group] ?? $technician->group) : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Bagian</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->section ? (\App\Models\Technician::SECTIONS[$technician->section] ?? $technician->section) : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Departemen</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->department?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Telegram</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->telegram_username ? '@' . $technician->telegram_username : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Telegram ID</dt>
                            <dd class="font-medium text-slate-900 font-mono">{{ $technician->telegram_id ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Terakhir Aktif</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->last_active_at ? $technician->last_active_at->diffForHumans() : '-' }}</dd>
                        </div>
                        @if($technician->approved_at)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Disetujui</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->approved_at->format('d/m/Y') }}</dd>
                        </div>
                        @endif
                    </dl>
                </div>
            </div>

            <!-- Stats -->
            <div class="lg:col-span-2 space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-blue-600">{{ $stats['total_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Total Laporan</p>
                    </div>
                    <div class="bg-white rounded-xl border border-green-200 p-4 text-center">
                        <p class="text-2xl font-bold text-green-600">{{ $stats['completed_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Selesai</p>
                    </div>
                    <div class="bg-white rounded-xl border border-amber-200 p-4 text-center">
                        <p class="text-2xl font-bold text-amber-600">{{ $stats['pending_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Draft</p>
                    </div>
                    <div class="bg-white rounded-xl border border-violet-200 p-4 text-center">
                        <p class="text-2xl font-bold text-violet-600">{{ $stats['last_report'] ? $stats['last_report']->format('d/m') : '-' }}</p>
                        <p class="text-xs text-slate-500 mt-1">Laporan Terakhir</p>
                    </div>
                    <div class="bg-white rounded-xl border border-teal-200 p-4 text-center">
                        <p class="text-2xl font-bold text-teal-600">{{ $stats['reports_this_month'] ?? 0 }}</p>
                        <p class="text-xs text-slate-500 mt-1">Bulan Ini</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned Assets -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Asset yang Ditugaskan</h3>
            </div>
            <div class="px-6 py-4">
                @php
                    $assignedAssets = $technician->assignedAssets()->with(['company', 'area', 'subArea'])->get();
                @endphp
                @if($assignedAssets->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($assignedAssets as $asset)
                            <div class="flex items-start gap-3 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-xs font-bold shrink-0 mt-0.5">
                                    {{ substr($asset->description ?? $asset->tech_ident_no, 0, 1) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('assets.show', $asset) }}" class="text-sm font-medium text-slate-900 hover:text-blue-600 truncate block">
                                        {{ $asset->tech_ident_no ?? $asset->equipment_no ?? '-' }}
                                    </a>
                                    <p class="text-xs text-slate-500 truncate">{{ $asset->description }}</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">
                                        {{ $asset->area?->code ?? '-' }} / {{ $asset->subArea?->code ?? '-' }}
                                    </p>
                                    @if($asset->pivot->note)
                                        <p class="text-xs text-slate-500 italic mt-1">Catatan: {{ $asset->pivot->note }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-6 text-sm text-slate-400">Belum ditugaskan ke asset mana pun.</div>
                @endif
            </div>
        </div>

        <!-- Koneksi Bot Telegram -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Koneksi Bot Telegram</h3>
                @if($technician->telegram_id)
                    <a href="{{ route('bot.index') }}#teknisi-aktif"
                       class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                        Lihat di Panel Bot
                    </a>
                @endif
            </div>
            <div class="px-6 py-4">
                @if($technician->telegram_id)
                    @php
                        $lastActive = $technician->last_active_at;
                        if ($lastActive && $lastActive->gt(now()->subDays(7))) {
                            $badgeKelas = 'bg-green-100 text-green-700';
                            $badgeLabel = 'Terhubung & Aktif';
                        } elseif ($lastActive) {
                            $badgeKelas = 'bg-amber-100 text-amber-700';
                            $badgeLabel = 'Terhubung, Tidak Aktif';
                        } else {
                            $badgeKelas = 'bg-slate-100 text-slate-500';
                            $badgeLabel = 'Terhubung, Belum Pernah Aktif';
                        }
                    @endphp
                    <div class="flex items-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium {{ $badgeKelas }}">
                            <span class="w-1.5 h-1.5 rounded-full
                                {{ $lastActive && $lastActive->gt(now()->subDays(7)) ? 'bg-green-500' : ($lastActive ? 'bg-amber-400' : 'bg-slate-400') }}">
                            </span>
                            {{ $badgeLabel }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Telegram ID</dt>
                            <dd class="font-mono text-slate-900">{{ $technician->telegram_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Username</dt>
                            <dd class="text-slate-900">{{ $technician->telegram_username ? '@' . $technician->telegram_username : '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Terakhir Aktif</dt>
                            <dd class="text-slate-900">
                                @if($lastActive)
                                    {{ $lastActive->format('d/m/Y H:i') }}
                                    <span class="text-xs text-slate-400">({{ $lastActive->diffForHumans() }})</span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Laporan via Bot</dt>
                            <dd class="font-semibold text-slate-900">{{ $botStats['reports_via_bot'] }}</dd>
                        </div>
                        @if($botStats['laporan_terakhir_via_bot'])
                        <div class="col-span-2">
                            <dt class="text-slate-500 text-xs mb-0.5">Laporan Terakhir via Bot</dt>
                            <dd class="text-slate-900">{{ $botStats['laporan_terakhir_via_bot']->format('d/m/Y') }}</dd>
                        </div>
                        @endif
                    </dl>
                @else
                    <div class="flex items-center gap-3 py-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                            Belum Terhubung
                        </span>
                        <span class="text-xs text-slate-400">Teknisi belum pernah mendaftar via bot.</span>
                    </div>
                @endif
            </div>
        </div>

        <!-- Man Hour: target 8 jam per hari kerja sesuai jadwal shift -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="font-medium text-slate-900">Man Hour</h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Periode {{ $labelPeriodeManHour }} — target {{ (int) config('cm.man_hour.target_minutes', 480) / 60 }} jam per hari kerja,
                        hari OFF tidak dihitung. Baris salinan pekerjaan multi alat dikecualikan agar tidak dobel.
                    </p>
                </div>
                @if($shiftHariIni)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold {{ $shiftHariIni->isOff() ? 'bg-slate-100 text-slate-600' : 'bg-teal-50 text-teal-700 border border-teal-100' }}">
                        Hari ini: {{ $shiftHariIni->label() }}
                    </span>
                @endif
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 px-5 pt-4">
                <div class="rounded-xl border border-teal-200 bg-teal-50/40 p-3 text-center">
                    <p class="text-2xl font-bold text-teal-700">
                        {{ number_format(($manHourRingkasan['total_menit'] ?? 0) / 60, 1, ',', '.') }} j
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        dari {{ number_format(($manHourRingkasan['total_target_menit'] ?? 0) / 60, 0, ',', '.') }} j target
                    </p>
                    @if(($manHourRingkasan['persen'] ?? null) !== null)
                        <p class="text-xs font-semibold mt-1 {{ $manHourRingkasan['persen'] >= 100 ? 'text-teal-700' : ($manHourRingkasan['persen'] >= 75 ? 'text-amber-600' : 'text-rose-600') }}">
                            Pencapaian {{ $manHourRingkasan['persen'] }}%
                        </p>
                    @endif
                </div>
                <div class="rounded-xl border border-slate-200 p-3 text-center">
                    <p class="text-2xl font-bold text-slate-800">{{ $manHourRingkasan['hari_kerja'] ?? 0 }}</p>
                    <p class="text-xs text-slate-500 mt-1">Hari kerja terjadwal</p>
                    <p class="text-xs text-slate-400 mt-1">
                        {{ $manHourRingkasan['hari_terpenuhi'] ?? 0 }} terpenuhi,
                        {{ $manHourRingkasan['hari_lebih'] ?? 0 }} lebih
                    </p>
                </div>
                <div class="rounded-xl border {{ ($manHourRingkasan['hari_kurang'] ?? 0) > 0 ? 'border-amber-200 bg-amber-50/40' : 'border-slate-200' }} p-3 text-center">
                    <p class="text-2xl font-bold {{ ($manHourRingkasan['hari_kurang'] ?? 0) > 0 ? 'text-amber-600' : 'text-slate-400' }}">
                        {{ $manHourRingkasan['hari_kurang'] ?? 0 }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">Hari kurang dari 8 jam</p>
                    <p class="text-xs text-slate-400 mt-1">{{ $manHourRingkasan['hari_libur'] ?? 0 }} hari OFF</p>
                </div>
                <div class="rounded-xl border {{ (($manHourRingkasan['hari_kerja_off'] ?? 0) + ($manHourRingkasan['laporan_luar_shift'] ?? 0)) > 0 ? 'border-rose-200 bg-rose-50/40' : 'border-slate-200' }} p-3 text-center">
                    <p class="text-2xl font-bold {{ (($manHourRingkasan['hari_kerja_off'] ?? 0) + ($manHourRingkasan['laporan_luar_shift'] ?? 0)) > 0 ? 'text-rose-600' : 'text-slate-400' }}">
                        {{ ($manHourRingkasan['hari_kerja_off'] ?? 0) + ($manHourRingkasan['laporan_luar_shift'] ?? 0) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">Anomali jam kerja</p>
                    <p class="text-xs text-slate-400 mt-1">
                        {{ $manHourRingkasan['hari_kerja_off'] ?? 0 }} kerja di hari OFF,
                        {{ $manHourRingkasan['laporan_luar_shift'] ?? 0 }} di luar jam shift
                    </p>
                </div>
            </div>

            <div class="px-5 py-4">
                <canvas id="grafikManHour" height="110"></canvas>
            </div>
        </div>

        <!-- Rincian man hour harian -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-medium text-slate-900">Rincian Man Hour Harian</h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Periode {{ $labelPeriodeManHour }}. Klik baris untuk melihat laporan pada hari itu.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tanggal</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Shift</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Target</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Realisasi</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Pencapaian</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Pekerjaan</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($manHourHarian as $hari)
                            @php
                                $tautanHari = $hari['jumlah_pekerjaan'] > 0
                                    ? route('reports.index', ['technician_id' => $technician->id, 'tanggal' => $hari['tanggal']])
                                    : null;

                                $tanggalHari = \Illuminate\Support\Carbon::parse($hari['tanggal']);

                                $warnaStatus = [
                                    'terpenuhi' => 'text-teal-700',
                                    'lebih'     => 'text-indigo-600',
                                    'kurang'    => 'text-amber-600',
                                    'libur'     => 'text-slate-400',
                                ][$hari['status']] ?? 'text-slate-500';

                                $warnaBar = [
                                    'terpenuhi' => 'bg-teal-500',
                                    'lebih'     => 'bg-indigo-500',
                                    'kurang'    => 'bg-amber-400',
                                    'libur'     => 'bg-slate-300',
                                ][$hari['status']] ?? 'bg-slate-300';

                                $warnaBadge = $hari['kode'] === 'x'
                                    ? 'bg-slate-100 text-slate-500'
                                    : ($hari['kode'] === 'R'
                                        ? 'bg-sky-50 text-sky-700 border border-sky-100'
                                        : 'bg-teal-50 text-teal-700 border border-teal-100');
                            @endphp
                            <tr class="{{ $tautanHari ? 'hover:bg-slate-50 transition-colors cursor-pointer' : '' }}"
                                @if($tautanHari)
                                    title="Lihat laporan {{ $tanggalHari->format('d/m/Y') }}"
                                    onclick="window.location='{{ $tautanHari }}'"
                                @endif>
                                <td class="px-5 py-3 align-top whitespace-nowrap">
                                    <p class="text-slate-700 font-medium">{{ $tanggalHari->format('d/m/Y') }}</p>
                                    <p class="text-xs text-slate-400">{{ $hari['hari'] }}</p>
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $warnaBadge }}">
                                        {{ $hari['label_shift'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap text-xs text-slate-600">
                                    {{ $hari['target_menit'] > 0 ? number_format($hari['target_menit'] / 60, 1, ',', '.') . ' j' : '-' }}
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap text-sm font-semibold text-slate-800">
                                    {{ number_format($hari['menit'] / 60, 1, ',', '.') }} j
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    @if($hari['persen'] !== null)
                                        <p class="text-sm font-semibold {{ $warnaStatus }}">{{ $hari['persen'] }}%</p>
                                        <div class="mt-1 w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $warnaBar }}"
                                                 style="width: {{ max(0, min(100, $hari['persen'])) }}%"></div>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">
                                            {{ $hari['status'] === 'libur' ? 'Libur' : 'Tanpa target' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                    @if($hari['jumlah_pekerjaan'] > 0)
                                        <a href="{{ $tautanHari }}" onclick="event.stopPropagation()"
                                           class="text-teal-700 hover:underline font-medium">
                                            {{ $hari['jumlah_pekerjaan'] }} laporan
                                        </a>
                                    @else
                                        <span class="text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    @forelse($hari['catatan'] as $catatan)
                                        <p class="text-xs text-rose-600">{{ $catatan }}</p>
                                    @empty
                                        <span class="text-xs text-slate-300">-</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada data man hour.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pekerjaan Harian -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="font-medium text-slate-900">Pekerjaan Harian</h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $laporanTerbaru->count() }} pekerjaan terbaru. Klik baris untuk membuka detail laporan.
                    </p>
                </div>
                <a href="{{ route('reports.index', ['technician_id' => $technician->id]) }}"
                   class="inline-flex items-center gap-1 text-xs font-medium text-teal-700 hover:text-teal-800">
                    Lihat semua di halaman Laporan
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tanggal</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Jam</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Durasi</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Pekerjaan</th>
                            <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Jenis</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Lokasi / Alat</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Foto</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($laporanTerbaru as $report)
                            @php
                                $jenisWarna = [
                                    'EI'        => 'bg-sky-50 text-sky-700 border-sky-100',
                                    'Mekanik'   => 'bg-amber-50 text-amber-700 border-amber-100',
                                    'CM/PM'     => 'bg-teal-50 text-teal-700 border-teal-100',
                                    'Fabrikasi' => 'bg-violet-50 text-violet-700 border-violet-100',
                                    'Sipil'     => 'bg-lime-50 text-lime-700 border-lime-100',
                                    'IT'        => 'bg-indigo-50 text-indigo-700 border-indigo-100',
                                    'Project'   => 'bg-rose-50 text-rose-700 border-rose-100',
                                ][$report->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-500 border-slate-200';

                                $jumlahDok = count($report->photo_documentation ?? []);
                                $jumlahHc  = count($report->photo_hygiene_clearance ?? []);
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                                title="Buka {{ $report->report_code ?? 'laporan' }}"
                                onclick="window.location='{{ route('reports.show', $report) }}'">
                                <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                    <p class="text-slate-700 font-medium">{{ $report->report_date?->format('d/m/Y') ?? '-' }}</p>
                                    <p class="text-xs text-slate-400">{{ $report->report_date?->locale('id')->translatedFormat('l') ?? '' }}</p>
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap text-xs text-slate-600">
                                    {{ $report->time_range ?? '-' }}
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    @if($report->duration_label)
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-teal-50 text-teal-700 border border-teal-100">
                                            {{ $report->duration_label }}
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top max-w-sm">
                                    <p class="text-slate-800 text-sm font-medium leading-snug whitespace-normal break-words">
                                        {{ $report->work_description ?: '(tanpa deskripsi pekerjaan)' }}
                                    </p>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        {{ $report->report_code ?? '-' }}
                                        @if($report->status === 'continue')
                                            <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                                                CONTINUE
                                            </span>
                                        @endif
                                        @if($report->continued_from_id)
                                            <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-teal-50 text-teal-700 border border-teal-100">
                                                LANJUTAN
                                            </span>
                                        @endif
                                        @if($report->collaborator_of)
                                            <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-100">
                                                KOLABORASI
                                            </span>
                                        @endif
                                    </p>
                                    @if($report->root_cause)
                                        <p class="text-xs text-slate-400 mt-0.5">
                                            Penyebab: {{ \Illuminate\Support\Str::limit($report->root_cause, 60) }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top text-center whitespace-nowrap">
                                    @if($report->jenis_pekerjaan)
                                        <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium border {{ $jenisWarna }}">
                                            {{ $report->jenis_pekerjaan }}
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-300">-</span>
                                    @endif
                                    @if($report->work_classification)
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ \App\Enums\WorkClassification::tryFrom($report->work_classification)?->label() ?? $report->work_classification }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap" onclick="event.stopPropagation()">
                                    @if($report->functionalLocation)
                                        <a href="{{ route('locations.show', $report->functionalLocation) }}"
                                           title="Detail lokasi {{ $report->functionalLocation->name }}"
                                           class="text-xs font-medium font-mono text-teal-700 hover:underline">
                                            {{ $report->functionalLocation->code }}
                                        </a>
                                    @elseif($report->area)
                                        <a href="{{ route('assets.index', ['area_id' => $report->area->id]) }}"
                                           title="Lihat alat di area {{ $report->area->code }}"
                                           class="text-xs font-medium text-slate-700 hover:text-teal-700 hover:underline">
                                            {{ $report->area->code }}
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-300">Tanpa lokasi</span>
                                    @endif
                                    <p class="text-xs text-slate-400 mt-0.5 font-mono">
                                        @if($report->asset)
                                            <a href="{{ route('assets.show', $report->asset) }}"
                                               title="Detail alat {{ $report->asset->description }}"
                                               class="text-teal-700 hover:underline">
                                                {{ $report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id) }}
                                            </a>
                                        @else
                                            {{ $report->equipment_tag ?: '-' }}
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    @if($jumlahDok > 0 || $jumlahHc > 0)
                                        <p class="text-xs text-slate-600">Dokumentasi: <span class="font-semibold">{{ $jumlahDok }}</span></p>
                                        <p class="text-xs text-slate-600">HC: <span class="font-semibold">{{ $jumlahHc }}</span></p>
                                    @else
                                        <span class="text-xs text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    <x-status-badge :status="$report->status" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada pekerjaan tercatat.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Kirim Pesan -->
        <div x-show="showBroadcastModal"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             x-cloak>
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden"
                 @@click.away="showBroadcastModal = false">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">Kirim Pesan ke {{ $technician->name }}</h3>
                    <button @@click="showBroadcastModal = false" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    @if(!$technician->telegram_id)
                        <div class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <p class="text-xs text-amber-700">Teknisi belum memiliki Telegram ID. Pesan akan disimpan sebagai log.</p>
                        </div>
                    @elseif($technician->status !== 'active')
                        <div class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <p class="text-xs text-amber-700">Teknisi tidak aktif. Pesan tetap dikirim jika Telegram ID tersedia.</p>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Pesan <span class="text-red-500">*</span></label>
                        <textarea x-model="broadcastMessage" rows="4"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Tulis pesan untuk teknisi..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2">
                    <button @@click="showBroadcastModal = false" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">Batal</button>
                    <button @@click="sendMessage" :disabled="!broadcastMessage.trim()"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg">
                        <span x-show="!sending">Kirim Pesan</span>
                        <span x-show="sending" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Mengirim...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function technicianDetail() {
    return {
        showBroadcastModal: false,
        broadcastMessage: '',
        sending: false,

        openBroadcast() {
            this.showBroadcastModal = true;
            this.broadcastMessage = '';
        },

        sendMessage() {
            if (!this.broadcastMessage.trim()) return;
            this.sending = true;

            fetch('{{ route('technicians.broadcast', $technician) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ message: this.broadcastMessage }),
            })
            .then(res => res.json())
            .then(data => {
                this.showBroadcastModal = false;
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Gagal: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Terjadi kesalahan jaringan.'))
            .finally(() => { this.sending = false; });
        },
    };
}

// Untuk tombol Kirim Pesan di header (non-Alpine)
function broadcastMessage() {
    const el = document.querySelector('[x-data]');
    if (el && el.__x) {
        el.__x.$data.openBroadcast();
    }
}
</script>
@endpush

{{-- Grafik man hour harian: realisasi vs target 8 jam --}}
@push('scripts')
@php
    // Data grafik disiapkan di server agar tidak bergantung pada parsing directive.
    $dataGrafik = array_map(function (array $hari): array {
        return [
            'tanggal' => \Illuminate\Support\Carbon::parse($hari['tanggal'])->format('d/m'),
            'menit'   => (int) $hari['menit'],
            'target'  => (int) $hari['target_menit'],
            'shift'   => (string) $hari['label_shift'],
        ];
    }, $manHourHarian);

    $dataGrafikJson = json_encode($dataGrafik, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var kanvas = document.getElementById('grafikManHour');

    if (!kanvas || typeof Chart === 'undefined') {
        return;
    }

    // Data harian berasal dari perhitungan man hour di server.
    var dataHarian = {!! $dataGrafikJson !!};

    new Chart(kanvas, {
        type: 'bar',
        data: {
            labels: dataHarian.map(function (baris) { return baris.tanggal; }),
            datasets: [
                {
                    label: 'Realisasi (jam)',
                    data: dataHarian.map(function (baris) { return Math.round(baris.menit / 6) / 10; }),
                    backgroundColor: '#0E9E8E',
                    borderRadius: 4,
                    maxBarThickness: 26
                },
                {
                    label: 'Target (jam)',
                    data: dataHarian.map(function (baris) { return Math.round(baris.target / 6) / 10; }),
                    backgroundColor: '#CBD5E1',
                    borderRadius: 4,
                    maxBarThickness: 26
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        afterLabel: function (konteks) {
                            var baris = dataHarian[konteks.dataIndex];

                            return baris ? 'Shift: ' + baris.shift : '';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (nilai) { return nilai + ' j'; }
                    }
                },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>
@endpush
