{{-- Tabel perbaikan halaman detail equipment: SATU BARIS = SATU PERBAIKAN
     (satu laporan), BUKAN per teknisi. Teknisi hanya menjadi kolom informasi,
     dan tiap baris punya tombol Detail menuju halaman laporan (reports.show).
     Dilengkapi filter tanggal/rentang, teknisi, pencarian, dan pagination.
     Dipakai oleh resources/views/assets/show.blade.php. --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Daftar Perbaikan</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Satu baris mewakili satu perbaikan (laporan). Tekan Detail untuk membuka isi perbaikannya.
            </p>
        </div>
        <p class="text-xs text-slate-500 shrink-0">
            Menampilkan {{ number_format($filterPerbaikan['jumlah']) }} dari {{ number_format($totalReports) }} perbaikan
        </p>
    </div>

    {{-- Filter tabel perbaikan: tanggal per-hari / rentang, teknisi, pencarian --}}
    <form method="GET" action="{{ route('assets.show', $asset) }}"
          class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 px-6 py-4 border-b border-slate-100 bg-slate-50/60 items-end">
        <div>
            <label for="filterPerbaikanTanggal" class="block text-xs text-slate-500 mb-1">Tanggal</label>
            <input type="date" id="filterPerbaikanTanggal" name="tanggal" value="{{ $filterPerbaikan['tanggal'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label for="filterPerbaikanDari" class="block text-xs text-slate-500 mb-1">Rentang - Dari</label>
            <input type="date" id="filterPerbaikanDari" name="date_from" value="{{ $filterPerbaikan['date_from'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label for="filterPerbaikanSampai" class="block text-xs text-slate-500 mb-1">Rentang - Sampai</label>
            <input type="date" id="filterPerbaikanSampai" name="date_to" value="{{ $filterPerbaikan['date_to'] }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label for="filterPerbaikanTeknisi" class="block text-xs text-slate-500 mb-1">Teknisi</label>
            <select id="filterPerbaikanTeknisi" name="teknisi"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="">Semua Teknisi</option>
                @foreach($teknisiPernahRepair as $opsiTeknisi)
                    <option value="{{ $opsiTeknisi->id }}"
                        {{ (int) $filterPerbaikan['teknisi'] === $opsiTeknisi->id ? 'selected' : '' }}>
                        {{ $opsiTeknisi->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterPerbaikanCari" class="block text-xs text-slate-500 mb-1">Cari</label>
            <input type="text" id="filterPerbaikanCari" name="q" value="{{ $filterPerbaikan['q'] }}"
                   placeholder="Kode / deskripsi / teknisi"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
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

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">No</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Tanggal</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Kode Laporan</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Jenis</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Deskripsi Pekerjaan</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Teknisi</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Durasi</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Status</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($perbaikan as $indeks => $laporan)
                    @php
                        $durasiMenit = (int) ($laporan->work_duration_minutes ?? 0);
                        $durasiLabel = $durasiMenit <= 0
                            ? '&mdash;'
                            : ($durasiMenit >= 60
                                ? intdiv($durasiMenit, 60) . 'j ' . ($durasiMenit % 60) . 'm'
                                : $durasiMenit . 'm');
                    @endphp
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3 text-xs text-slate-400">{{ $perbaikan->firstItem() + $indeks }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                            {{ $laporan->report_date?->format('d M Y') ?? '&mdash;' }}
                            @if($laporan->tanggal_kejadian)
                                <span class="block text-[11px] text-slate-400">Kejadian {{ $laporan->tanggal_kejadian->format('d M Y') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-mono text-xs text-slate-700">{{ $laporan->report_code ?? '&mdash;' }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">{{ $labelTipe[$laporan->report_type ?? ''] ?? 'Tanpa Tipe' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-700 max-w-[320px]">
                            <span class="block truncate" title="{{ $laporan->work_description }}">{{ $laporan->work_description ?? '&mdash;' }}</span>
                            @if($laporan->root_cause)
                                <span class="block text-[11px] text-slate-400 truncate" title="{{ $laporan->root_cause }}">Penyebab: {{ $laporan->root_cause }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">{{ $laporan->technician?->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-center text-xs text-slate-600 whitespace-nowrap">{!! $durasiLabel !!}</td>
                        <td class="px-4 py-3 text-center"><x-status-badge :status="$laporan->status" /></td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <a href="{{ route('reports.show', $laporan) }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-teal-300 text-teal-700 hover:bg-teal-50 text-xs font-medium rounded-lg transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                Detail
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-6 py-10 text-center text-sm text-slate-400">
                            Belum ada perbaikan untuk filter yang dipilih.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($perbaikan->hasPages())
        <div class="px-6 py-3 border-t border-slate-100">
            {{ $perbaikan->links() }}
        </div>
    @endif
</div>

