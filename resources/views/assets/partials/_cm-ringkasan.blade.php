@if($cmRingkasan !== null)
    @php
        $cm = $cmRingkasan['equipment'];
        $last = $cmRingkasan['last_reading'];
        $kondisi = $last?->kondisi;
        $warnaKondisi = [
            'good'       => 'text-emerald-700 bg-emerald-50 ring-emerald-200',
            'alarm'      => 'text-amber-700 bg-amber-50 ring-amber-200',
            'danger'     => 'text-rose-700 bg-rose-50 ring-rose-200',
            'visual_bad' => 'text-violet-700 bg-violet-50 ring-violet-200',
        ][$kondisi] ?? 'text-slate-600 bg-slate-50 ring-slate-200';
    @endphp

    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-400">Preview</p>
                <h3 class="text-base font-semibold text-slate-900">Ringkasan Equipment (CM)</h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    {{ $cm->pt_location ?? '-' }}
                    @if($cm->plant)
                        &middot; {{ $cm->plant }}
                    @endif
                </p>
            </div>
            <a href="{{ route('cm.equipment-show', $cm->equipment_tag) }}"
               class="inline-flex items-center gap-2 rounded-lg border border-teal-300 px-4 py-2 text-sm font-medium text-teal-700 transition-colors hover:bg-teal-50">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m8-3v-2a2 2 0 00-2-2h-2a2 2 0 00-2 2v2m0 0v6a2 2 0 002 2h2a2 2 0 002-2v-6"/>
                </svg>
                Buka Halaman CM
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Total Readings</p>
                <p class="text-lg font-bold text-slate-900 mt-1">{{ number_format($cmRingkasan['total_readings'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Findings</p>
                <p class="text-lg font-bold text-slate-900 mt-1">
                    {{ number_format($cmRingkasan['findings_open'] + $cmRingkasan['findings_closed'], 0, ',', '.') }}
                </p>
                <p class="text-[10px] text-slate-400">{{ $cmRingkasan['findings_open'] }} open · {{ $cmRingkasan['findings_closed'] }} closed</p>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Last Reading</p>
                @if($last)
                    <p class="text-sm font-bold text-slate-900 mt-1">{{ $last->tanggal ? \Carbon\Carbon::parse($last->tanggal)->format('d/m/Y') : '-' }}</p>
                    <span class="inline-flex mt-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold ring-1 {{ $warnaKondisi }}">
                        {{ ucfirst(str_replace('_', ' ', (string) $kondisi)) }}
                    </span>
                @else
                    <p class="text-sm font-bold text-slate-400 mt-1">-</p>
                @endif
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Vibrasi Maks</p>
                <p class="text-lg font-bold text-slate-900 mt-1">
                    {{ $cmRingkasan['vib_max'] !== null ? number_format((float) $cmRingkasan['vib_max'], 2, ',', '.') : '-' }}
                    <span class="text-[10px] font-normal text-slate-400">mm/s</span>
                </p>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Temp Maks</p>
                <p class="text-lg font-bold text-slate-900 mt-1">
                    {{ $cmRingkasan['temp_max'] !== null ? number_format((float) $cmRingkasan['temp_max'], 1, ',', '.') : '-' }}
                    <span class="text-[10px] font-normal text-slate-400">&deg;C</span>
                </p>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-100 p-3">
                <p class="text-[10px] uppercase tracking-wide text-slate-400">Tipe Lubrikasi</p>
                <p class="text-sm font-semibold text-slate-900 mt-1">{{ $cm->tipe_lubrikasi ?: '-' }}</p>
                <p class="text-[10px] text-slate-400">{{ $cm->equipment_tag }}</p>
            </div>
        </div>
    </div>
@endif
