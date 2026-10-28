@php
    $tautanHari = $hari['jumlah_pekerjaan'] > 0
        ? route('reports.index', array_merge($parameterLaporan, ['tanggal' => $hari['tanggal']]))
        : null;

    $tanggalHari = \Illuminate\Support\Carbon::parse($hari['tanggal']);

    $warnaStatus = [
        'terpenuhi' => 'text-teal-700',
        'lebih'     => 'text-indigo-600',
        'kurang'    => 'text-amber-600',
        'libur'     => 'text-slate-400',
        'cuti'      => 'text-sky-600',
    ][$hari['status']] ?? 'text-slate-500';

    $warnaBar = [
        'terpenuhi' => 'bg-teal-500',
        'lebih'     => 'bg-indigo-500',
        'kurang'    => 'bg-amber-400',
        'libur'     => 'bg-slate-300',
        'cuti'      => 'bg-sky-400',
    ][$hari['status']] ?? 'bg-slate-300';

    $warnaBadge = $hari['kode'] === 'x'
        ? 'bg-slate-100 text-slate-500'
        : ($hari['kode'] === 'R'
            ? 'bg-sky-50 text-sky-700 border border-sky-100'
            : 'bg-teal-50 text-teal-700 border border-teal-100');
@endphp
<tr class="{{ $tautanHari ? 'hover:bg-slate-50 transition-colors cursor-pointer' : '' }}"
    data-tanggal="{{ $hari['tanggal'] }}"
    data-bulan="{{ $tanggalHari->format('Y-m') }}"
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
            @if(($hari['lembur'] ?? 0) > 0)
                <div class="mt-0.5 w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full bg-amber-500"
                         style="width: {{ $hari['target_menit'] > 0 ? max(0, min(100, (int) round($hari['lembur'] / $hari['target_menit'] * 100))) : 100 }}%"></div>
                </div>
            @endif
        @else
            <span class="text-xs {{ $hari['status'] === 'cuti' ? 'text-sky-600 font-medium' : 'text-slate-400' }}">
                {{ $hari['status'] === 'libur' ? 'Libur' : ($hari['status'] === 'cuti' ? 'Cuti' : 'Tanpa target') }}
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
    <td class="px-4 py-3 align-top whitespace-nowrap">
        @if(($hari['lembur'] ?? 0) > 0)
            <span class="text-xs font-semibold text-amber-600">
                {{ number_format($hari['lembur'] / 60, 1, ',', '.') }} j
            </span>
            <p class="text-xs text-slate-400">di luar jam kerja</p>
        @else
            <span class="text-xs text-slate-300">-</span>
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
