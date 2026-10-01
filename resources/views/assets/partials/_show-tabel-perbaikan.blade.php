{{-- Tabel perbaikan halaman detail equipment: SATU BARIS = SATU PERBAIKAN
     (satu pekerjaan). Salinan laporan milik tiap kolaborator (collaborator_of
     terisi) TIDAK ditampilkan sebagai baris tersendiri (lihat
     AssetController::show()) supaya satu pekerjaan tidak muncul berulang.
     Daftar kolaboratornya dibuka lewat tombol popup di kolom Teknisi.
     Kolom Kode Laporan & Jenis sengaja tidak ditampilkan.
     Dipakai oleh resources/views/assets/show.blade.php. --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Daftar Perbaikan</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Satu baris mewakili satu pekerjaan. Tekan Detail untuk membuka isi perbaikannya.
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
                   placeholder="Deskripsi / penyebab / teknisi"
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
                        $jumlahKolaborator = $laporan->collaboratorReports->count();
                    @endphp
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3 text-xs text-slate-400">{{ $perbaikan->firstItem() + $indeks }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                            {{ $laporan->report_date?->format('d M Y') ?? '&mdash;' }}
                            @if($laporan->tanggal_kejadian)
                                <span class="block text-[11px] text-slate-400">Kejadian {{ $laporan->tanggal_kejadian->format('d M Y') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700 max-w-[420px]">
                            <span class="block truncate" title="{{ $laporan->work_description }}">{{ $laporan->work_description ?? '&mdash;' }}</span>
                            @if($laporan->root_cause)
                                <span class="block text-[11px] text-slate-400 truncate" title="{{ $laporan->root_cause }}">Penyebab: {{ $laporan->root_cause }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-700 whitespace-nowrap">
                            <span class="block">{{ $laporan->technician?->name ?? 'Unknown' }}</span>
                            @if($jumlahKolaborator > 0)
                                <button type="button"
                                        onclick="tampilkanKolaborator({{ $laporan->id }})"
                                        title="Klik untuk melihat laporan para kolaborator"
                                        class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-teal-50 text-teal-700 border border-teal-100 hover:bg-teal-100 transition-colors">
                                    {{ $jumlahKolaborator }} KOLABORATOR
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            @endif
                        </td>
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
                        <td colspan="7" class="px-6 py-10 text-center text-sm text-slate-400">
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


{{-- Modal daftar laporan kolaborator (dibuka tombol "N KOLABORATOR" di kolom Teknisi) --}}
@push('modals')
    <div id="modal-kolaborator" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-4 px-5 py-3.5 border-b border-slate-100">
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Laporan Kolaborator</h3>
                    <p id="modal-kolaborator-sub" class="text-xs text-slate-400 mt-0.5"></p>
                </div>
                <button type="button" onclick="tutupKolaborator()" title="Tutup"
                        class="text-slate-400 hover:text-slate-600 shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="modal-kolaborator-isi" class="px-5 py-4 space-y-2 overflow-y-auto"></div>
            <div class="px-5 py-3 border-t border-slate-100 text-right">
                <button type="button" onclick="tutupKolaborator()"
                        class="px-4 py-1.5 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    <script>
        // Data laporan kolaborator per laporan induk, dipakai tombol "N KOLABORATOR".
        var dataKolaborator = {!! json_encode($kolaboratorPerLaporan ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};

        /**
         * Tampilkan popup daftar laporan kolaborator untuk satu perbaikan.
         * @param {number} idLaporan ID laporan induk
         */
        function tampilkanKolaborator(idLaporan) {
            var daftar = dataKolaborator[idLaporan] || [];
            var modal = document.getElementById('modal-kolaborator');
            var isi = document.getElementById('modal-kolaborator-isi');
            var sub = document.getElementById('modal-kolaborator-sub');

            if (!modal || !isi || !sub) {
                return;
            }

            sub.textContent = daftar.length + ' laporan kolaborator pada pekerjaan ini';
            isi.innerHTML = '';

            daftar.forEach(function (item) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2';

                var kiri = document.createElement('div');
                kiri.className = 'min-w-0';

                var nama = document.createElement('p');
                nama.className = 'text-sm font-semibold text-slate-700 truncate';
                nama.textContent = item.nama;

                var keterangan = document.createElement('p');
                keterangan.className = 'text-xs text-slate-400 truncate';
                keterangan.textContent = item.kode + ' - ' + item.tanggal;

                kiri.appendChild(nama);
                kiri.appendChild(keterangan);

                var tautanDetail = document.createElement('a');
                tautanDetail.href = item.url;
                tautanDetail.className = 'px-3 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700 shrink-0';
                tautanDetail.textContent = 'Detail';

                baris.appendChild(kiri);
                baris.appendChild(tautanDetail);
                isi.appendChild(baris);
            });

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        /**
         * Tutup popup daftar laporan kolaborator.
         */
        function tutupKolaborator() {
            var modal = document.getElementById('modal-kolaborator');

            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('keydown', function (peristiwa) {
            if (peristiwa.key === 'Escape') {
                tutupKolaborator();
            }
        });
    </script>
@endpush

