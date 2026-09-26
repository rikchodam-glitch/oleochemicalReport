{{-- Riwayat perbaikan satu asset: dikelompokkan per hari + filter tanggal/teknisi.
     Dipakai oleh resources/views/assets/show.blade.php (halaman detail equipment). --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Riwayat Perbaikan</h3>
        <p class="text-xs text-slate-400 mt-0.5">
            Menampilkan {{ number_format($filterRiwayat['jumlah']) }} dari {{ number_format($totalReports) }} laporan
            @if($filterRiwayat['teknisi'] || $filterRiwayat['tanggal'] || $filterRiwayat['date_from'] || $filterRiwayat['date_to'])
                &middot; difilter
            @endif
            &middot; dikelompokkan per hari seperti halaman Laporan.
        </p>
    </div>

    {{-- Filter riwayat: tanggal per-hari / rentang, dan teknisi --}}
    <form method="GET" action="{{ route('assets.show', $asset) }}"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 px-6 py-4 border-b border-slate-100 bg-slate-50/60 items-end">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Tanggal</label>
            <input type="date" name="tanggal" value="{{ $filterRiwayat['tanggal'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Rentang - Dari</label>
            <input type="date" name="date_from" value="{{ $filterRiwayat['date_from'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Rentang - Sampai</label>
            <input type="date" name="date_to" value="{{ $filterRiwayat['date_to'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Teknisi</label>
            <select name="teknisi"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="">Semua Teknisi</option>
                @foreach($technicians as $opsiTeknisi)
                    <option value="{{ $opsiTeknisi->id }}"
                        {{ (int) $filterRiwayat['teknisi'] === $opsiTeknisi->id ? 'selected' : '' }}>
                        {{ $opsiTeknisi->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Terapkan
            </button>
            <a href="{{ route('assets.show', $asset) }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
        </div>
    </form>

    @if($riwayat->isNotEmpty())
        @php
            // Nama hari & bulan Bahasa Indonesia (locale aplikasi masih 'en').
            $hariId = [
                'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
                'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
            ];
            $bulanId = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];
        @endphp
        <div class="divide-y divide-slate-50">
            @foreach($riwayatPerHari as $tanggalHari => $laporanHari)
                @php
                    $tanggalObj   = $tanggalHari === 'tanpa-tanggal' ? null : \Illuminate\Support\Carbon::parse($tanggalHari);
                    $labelTanggal = $tanggalObj
                        ? ($hariId[$tanggalObj->format('l')] ?? '') . ', ' . $tanggalObj->format('d') . ' '
                            . ($bulanId[(int) $tanggalObj->format('n')] ?? '') . ' ' . $tanggalObj->format('Y')
                        : 'Tanpa Tanggal';
                    $tautanHari   = route('assets.show', array_filter(array_merge(request()->query(), [
                        'asset'   => $asset->id,
                        'tanggal' => $tanggalObj?->toDateString(),
                    ])));
                @endphp
                {{-- Judul hari: pemisah riwayat per hari --}}
                <div class="flex flex-wrap items-center justify-between gap-2 px-6 py-2 bg-slate-50/80 border-y border-slate-100">
                    <p class="text-xs font-semibold text-slate-600 uppercase tracking-wide">{{ $labelTanggal }}</p>
                    <span class="flex items-center gap-2 text-xs text-slate-500">
                        {{ $laporanHari->count() }} laporan
                        @if($tanggalObj)
                            <a href="{{ $tautanHari }}" class="text-teal-700 hover:underline">Fokus hari ini</a>
                        @endif
                    </span>
                </div>
                @foreach($laporanHari as $report)
                    <div class="px-6 py-4 hover:bg-slate-50 transition-colors cursor-pointer"
                         onclick="window.location='{{ route('reports.show', $report) }}'">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-sm font-medium text-slate-900">{{ $report->technician?->name ?? 'Unknown' }}</span>
                                    <span class="text-xs text-slate-400">&middot;</span>
                                    <span class="text-xs text-slate-500 font-mono">{{ $report->report_code ?? '-' }}</span>
                                    @if($report->report_type)
                                        <span class="text-xs text-slate-400">&middot;</span>
                                        <span class="inline-flex px-2 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-600 rounded">{{ ucfirst($report->report_type) }}</span>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-700 leading-relaxed">{{ $report->work_description }}</p>
                                @if($report->status === 'completed' && $report->completed_at)
                                    <p class="text-xs text-green-600 mt-1">Selesai {{ $report->completed_at->format('d M Y H:i') }}</p>
                                @endif
                            </div>
                            <div class="shrink-0"><x-status-badge :status="$report->status" /></div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    @else
        <div class="px-6 py-10 text-center text-sm text-slate-400">
            Belum ada riwayat perbaikan untuk filter yang dipilih.
        </div>
    @endif
</div>
