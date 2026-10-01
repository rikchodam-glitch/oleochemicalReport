{{-- Grafik tren perbaikan equipment (lebar penuh) + komposisi jenis + ringkasan.
     Dipakai oleh resources/views/assets/show.blade.php (halaman detail equipment).
     Acuan tampilan: PER PERBAIKAN (bukan per teknisi yang memperbaiki). --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Tren Perbaikan per Bulan</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Seluruh riwayat perbaikan equipment ini &middot; {{ number_format($trenPerbaikan['total']) }} perbaikan
            </p>
        </div>
        <div class="flex items-center gap-2">
            <label for="trenRentang" class="text-xs text-slate-500">Tampilkan</label>
            <select id="trenRentang"
                    class="px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400">
                <option value="12">12 bulan terakhir</option>
                <option value="24">24 bulan terakhir</option>
                <option value="0" selected>Semua</option>
            </select>
        </div>
    </div>
    <div class="p-6">
        <div id="trenPerbaikanLegend" class="flex items-center gap-4 text-xs flex-wrap mb-4"></div>
        @if($trenPerbaikan['total'] > 0 && count($trenPerbaikan['labels']) > 0)
            <div class="relative" style="height: 380px;">
                <canvas id="trenPerbaikanChart" name="trenPerbaikanChart"></canvas>
            </div>
        @else
            <div class="flex items-center justify-center h-40 text-sm text-slate-400">Belum ada data perbaikan</div>
        @endif
    </div>
</div>

{{-- Komposisi jenis perbaikan + ringkasan angka perbaikan --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="text-sm font-semibold text-slate-900">Komposisi Jenis Perbaikan</h3>
        <p class="text-xs text-slate-400 mt-0.5">Proporsi seluruh perbaikan</p>
        @if($komposisiTipe->isNotEmpty())
            <div class="relative mt-4" style="height: 260px;">
                <canvas id="komposisiTipeChart" name="komposisiTipeChart"></canvas>
            </div>
        @else
            <div class="flex items-center justify-center h-40 text-sm text-slate-400">Belum ada data perbaikan</div>
        @endif
    </div>
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="text-sm font-semibold text-slate-900">Ringkasan Perbaikan</h3>
        <p class="text-xs text-slate-400 mt-0.5">Dihitung dari seluruh riwayat perbaikan equipment ini</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 mt-4">
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Total Perbaikan</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ number_format($ringkasanTren['total']) }}</p>
            </div>
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Perbaikan Terakhir</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">
                    {{ $ringkasanTren['terakhir'] ? \Illuminate\Support\Carbon::parse($ringkasanTren['terakhir'])->format('d M Y') : '&mdash;' }}
                </p>
            </div>
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Rata-rata per Bulan</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ number_format($ringkasanTren['rata_per_bulan'], 1) }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">dari {{ $ringkasanTren['jumlah_bulan'] }} bulan berisi perbaikan</p>
            </div>
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Interval Rata-rata</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">
                    {{ $ringkasanTren['interval_rata'] !== null ? number_format($ringkasanTren['interval_rata'], 1) . ' hari' : '&mdash;' }}
                </p>
                <p class="text-[11px] text-slate-400 mt-0.5">jarak antar hari ada perbaikan</p>
            </div>
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Bulan Terbanyak</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ $ringkasanTren['bulan_tersibuk'] ?? '&mdash;' }}</p>
            </div>
            <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium">Teknisi Pernah Menangani</p>
                <p class="text-xl font-bold text-slate-900 mt-0.5">{{ $teknisiPernahRepair->count() }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">hanya info, tabel tetap per perbaikan</p>
            </div>
        </div>
    </div>
</div>
