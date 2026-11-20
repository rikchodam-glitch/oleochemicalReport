<form method="POST" action="{{ route('tools.form.pinjam', ['token' => $asli]) }}" id="form-pinjam-alat" class="space-y-4">
    @csrf
    <input type="hidden" name="tool_id" id="pinjam-alat-id" value="{{ $prefillToolId }}">

    <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
            <label for="cari-alat-form" class="block text-xs font-medium text-slate-600 mb-1">Pencarian alat</label>
            <input type="search" id="cari-alat-form" placeholder="Nama atau kode alat..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <div>
            <label for="filter-lokasi-form" class="block text-xs font-medium text-slate-600 mb-1">Lokasi</label>
            <select id="filter-lokasi-form" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($grup as $lokasi)
                    <option value="{{ $lokasi['id'] }}">{{ $lokasi['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-rak-form" class="block text-xs font-medium text-slate-600 mb-1">Rak</label>
            <select id="filter-rak-form" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($grup as $lokasi)
                    @foreach($lokasi['rak'] as $rak)
                        <option value="{{ $rak['id'] }}" data-lokasi="{{ $lokasi['id'] }}">{{ $rak['name'] }}</option>
                    @endforeach
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status-form" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select id="filter-status-form" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                <option value="tersedia">Tersedia</option>
                <option value="dipakai">Dipakai</option>
                <option value="rusak">Rusak</option>
            </select>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" data-collapse-all="buka"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Buka semua
        </button>
        <button type="button" data-collapse-all="tutup"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Tutup semua
        </button>
    </div>

    <div id="wadah-grup-form" class="space-y-4">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-grup-lokasi="{{ $lokasi['id'] }}">
                <button type="button"
                        class="w-full px-4 py-3 bg-slate-50 flex items-center justify-between text-left hover:bg-slate-100"
                        data-collapse-toggle
                        aria-expanded="true"
                        aria-controls="form-lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['tools'])) }} alat</span>
                </button>

                <div id="form-lokasi-{{ $lokasi['id'] }}" class="px-4 py-3 space-y-4">
                    @foreach($lokasi['rak'] as $rak)
                        <div data-grup-rak="{{ $rak['id'] }}" class="space-y-2">
                            <button type="button"
                                    class="w-full flex items-center justify-between gap-2 text-left"
                                    data-collapse-toggle
                                    aria-expanded="true"
                                    aria-controls="form-rak-{{ $rak['id'] }}">
                                <span class="flex items-center gap-2">
                                    <svg data-collapse-chevron class="w-3.5 h-3.5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</span>
                                </span>
                                <span class="text-[11px] text-slate-400">{{ count($rak['tools']) }} alat</span>
                            </button>

                            <div id="form-rak-{{ $rak['id'] }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                @foreach($rak['tools'] as $alat)
                                    @php
                                        $warna = [
                                            'tersedia' => 'bg-green-100 text-green-700',
                                            'dipakai'  => 'bg-amber-100 text-amber-700',
                                            'rusak'    => 'bg-red-100 text-red-700',
                                        ][$alat['status']] ?? 'bg-slate-100 text-slate-600';
                                    @endphp

                                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col"
                                         data-kartu-tool
                                         data-cari="{{ strtolower($alat['name'] . ' ' . $alat['code']) }}"
                                         data-lokasi="{{ $lokasi['id'] }}"
                                         data-rak="{{ $rak['id'] }}"
                                         data-status="{{ $alat['status'] }}">
                                        <div class="relative aspect-square bg-slate-100">
                                            @if($alat['foto_url'])
                                                <img src="{{ $alat['foto_url'] }}" alt="{{ $alat['name'] }}"
                                                     class="w-full h-full object-cover" loading="lazy">
                                            @else
                                                <div class="w-full h-full flex items-center justify-center text-slate-300">
                                                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                    </svg>
                                                </div>
                                            @endif
                                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $warna }}">
                                                {{ $alat['status_label'] }}
                                            </span>
                                        </div>

                                        <div class="p-3 space-y-2 flex-1 flex flex-col">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900">{{ $alat['name'] }}</p>
                                                <p class="text-xs text-slate-500">{{ $alat['code'] }}</p>
                                            </div>

                                            <div class="text-xs text-slate-600 flex-1">
                                                @if($alat['status'] === 'dipakai')
                                                    <p>Dipakai oleh <span class="font-semibold">{{ $alat['peminjam'] ?? '-' }}</span></p>
                                                @elseif($alat['peminjam_terakhir'])
                                                    <p>Peminjam terakhir: <span class="font-medium">{{ $alat['peminjam_terakhir'] }}</span></p>
                                                @endif
                                            </div>

                                            @if($alat['bisa_dipinjam'])
                                                <button type="button"
                                                        class="pilih-alat w-full px-3 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700"
                                                        data-id="{{ $alat['id'] }}"
                                                        data-nama="{{ $alat['name'] }} ({{ $alat['code'] }})">
                                                    Pilih Alat Ini
                                                </button>
                                            @else
                                                <button type="button" disabled
                                                        class="w-full px-3 py-2 rounded-lg bg-slate-100 text-slate-400 text-xs font-semibold">
                                                    Tidak tersedia
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
                Belum ada alat yang tersimpan.
            </div>
        @endforelse
    </div>

    <p id="pesan-kosong-form" class="hidden text-center text-sm text-slate-500 py-4">
        Tidak ada alat yang cocok dengan pencarian/filter.
    </p>

    <div id="panel-pinjam" class="hidden bg-white rounded-xl border-2 border-teal-300 p-4 space-y-4 shadow-lg">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400 font-semibold">Alat dipilih</p>
                <p id="pinjam-alat-nama" class="text-sm font-semibold text-slate-900">-</p>
            </div>
            <button type="button" id="batal-pinjam" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Batal</button>
        </div>

        @if($modeKeeper)
            <div class="flex flex-wrap gap-2">
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-sm cursor-pointer">
                    <input type="radio" name="borrower_type" value="teknisi" class="text-teal-600" checked>
                    Teknisi
                </label>
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 text-sm cursor-pointer">
                    <input type="radio" name="borrower_type" value="non_teknisi" class="text-teal-600">
                    Non-Teknisi
                </label>
            </div>

            <div id="form-bagian-teknisi" class="space-y-2">
                <input type="search" id="cari-teknisi-form" placeholder="Cari nama atau NIK teknisi..."
                       class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">

                <div class="max-h-56 overflow-y-auto border border-slate-200 rounded-lg p-2 space-y-1">
                    @forelse($daftarTeknisi as $bagian)
                        <details class="grup-teknisi-form" open>
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500 px-1 py-1">
                                {{ $bagian['label'] }}
                            </summary>
                            <div class="mt-1 space-y-1">
                                @foreach($bagian['items'] as $teknisi)
                                    <label class="pemilih-teknisi-form flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer"
                                           data-cari="{{ strtolower(($teknisi['name'] ?? '') . ' ' . ($teknisi['nik'] ?? '') . ' ' . ($teknisi['department'] ?? '')) }}">
                                        <input type="radio" name="technician_id" value="{{ $teknisi['id'] }}" class="text-teal-600">
                                        <span class="text-sm text-slate-800">{{ $teknisi['name'] }}</span>
                                        <span class="text-[11px] text-slate-400">
                                            {{ $teknisi['nik'] ?: '-' }}{{ $teknisi['department'] ? ' / ' . $teknisi['department'] : '' }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <p class="text-xs text-slate-500 px-2 py-2">Belum ada teknisi aktif.</p>
                    @endforelse
                </div>
            </div>

            <div id="form-bagian-non-teknisi" class="hidden space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Nama peminjam</label>
                    <input type="text" name="borrower_name" maxlength="150"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                           placeholder="Nama lengkap peminjam">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Departemen</label>
                    <select name="borrower_department_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">- Pilih departemen -</option>
                        @foreach($daftarDepartemen as $departemen)
                            <option value="{{ $departemen->id }}">{{ $departemen->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @else
            <p class="text-sm text-slate-600">
                Alat akan dipinjam atas nama <span class="font-semibold text-slate-900">{{ $pemilik->name }}</span>.
            </p>
        @endif

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
            {{ $modeKeeper ? 'Pinjamkan Alat' : 'Pinjam Alat Ini' }}
        </button>
    </div>
</form>

@include('tools.partials._collapse-script')

<script>
(function () {
    const form = document.getElementById('form-pinjam-alat');
    if (!form) return;

    const hiddenAlat = document.getElementById('pinjam-alat-id');
    const panel = document.getElementById('panel-pinjam');
    const namaAlat = document.getElementById('pinjam-alat-nama');
    const batal = document.getElementById('batal-pinjam');
    const cariAlat = document.getElementById('cari-alat-form');
    const filterLokasi = document.getElementById('filter-lokasi-form');
    const filterRak = document.getElementById('filter-rak-form');
    const filterStatus = document.getElementById('filter-status-form');
    const pesanKosong = document.getElementById('pesan-kosong-form');
    const kartu = Array.from(document.querySelectorAll('[data-kartu-tool]'));
    const grupRak = Array.from(document.querySelectorAll('[data-grup-rak]'));
    const grupLokasi = Array.from(document.querySelectorAll('[data-grup-lokasi]'));

    function saringRak() {
        const lokasi = filterLokasi ? filterLokasi.value : '';
        Array.from(filterRak ? filterRak.options : []).forEach(function (opsi, index) {
            if (index === 0) return;
            const cocok = !lokasi || opsi.dataset.lokasi === lokasi;
            opsi.hidden = !cocok;
            if (!cocok && filterRak.value === opsi.value) filterRak.value = '';
        });
    }

    function terapkan() {
        const q = (cariAlat ? cariAlat.value : '').toLowerCase().trim();
        const lokasi = filterLokasi ? filterLokasi.value : '';
        const rak = filterRak ? filterRak.value : '';
        const status = filterStatus ? filterStatus.value : '';
        let jumlah = 0;

        kartu.forEach(function (el) {
            const cocok = (!q || el.dataset.cari.includes(q))
                && (!lokasi || el.dataset.lokasi === lokasi)
                && (!rak || el.dataset.rak === rak)
                && (!status || el.dataset.status === status);
            el.style.display = cocok ? '' : 'none';
            if (cocok) jumlah++;
        });

        grupRak.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        grupLokasi.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        if (pesanKosong) pesanKosong.classList.toggle('hidden', jumlah > 0);

        if ((q || lokasi || rak || status) && typeof window.bukaSemuaCollapse === 'function') {
            window.bukaSemuaCollapse();
        }
    }

    [cariAlat, filterStatus].forEach(function (el) {
        if (el) el.addEventListener('input', terapkan);
    });

    if (filterLokasi) {
        filterLokasi.addEventListener('change', function () {
            saringRak();
            terapkan();
        });
    }

    if (filterRak) filterRak.addEventListener('change', terapkan);

    function pilihAlat(tombol) {
        hiddenAlat.value = tombol.dataset.id;
        namaAlat.textContent = tombol.dataset.nama;
        panel.classList.remove('hidden');
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.querySelectorAll('.pilih-alat').forEach(function (tombol) {
        tombol.addEventListener('click', function () { pilihAlat(tombol); });
    });

    if (batal) {
        batal.addEventListener('click', function () {
            hiddenAlat.value = '';
            panel.classList.add('hidden');
        });
    }

    @if($prefillToolId)
        const prefill = document.querySelector('.pilih-alat[data-id="{{ $prefillToolId }}"]');
        if (prefill) pilihAlat(prefill);
    @endif

    const jenis = document.querySelectorAll('input[name="borrower_type"]');
    const bagianTeknisi = document.getElementById('form-bagian-teknisi');
    const bagianNon = document.getElementById('form-bagian-non-teknisi');

    function aturJenis() {
        if (!bagianTeknisi || !bagianNon) return;
        const terpilih = document.querySelector('input[name="borrower_type"]:checked');
        const teknisi = !terpilih || terpilih.value === 'teknisi';
        bagianTeknisi.classList.toggle('hidden', !teknisi);
        bagianNon.classList.toggle('hidden', teknisi);
    }

    jenis.forEach(function (radio) { radio.addEventListener('change', aturJenis); });
    aturJenis();

    const cariTeknisi = document.getElementById('cari-teknisi-form');

    if (cariTeknisi) {
        cariTeknisi.addEventListener('input', function () {
            const q = cariTeknisi.value.toLowerCase().trim();

            document.querySelectorAll('.grup-teknisi-form').forEach(function (grup) {
                let ada = false;

                grup.querySelectorAll('.pemilih-teknisi-form').forEach(function (baris) {
                    const cocok = !q || baris.dataset.cari.includes(q);
                    baris.style.display = cocok ? '' : 'none';
                    if (cocok) ada = true;
                });

                grup.style.display = ada ? '' : 'none';
                if (q !== '' && ada) grup.open = true;
            });
        });
    }

    form.addEventListener('submit', function (event) {
        if (!hiddenAlat.value) {
            event.preventDefault();
            alert('Pilih alat lebih dulu dengan menekan tombol Pilih Alat Ini.');
            return;
        }

        const tombol = form.querySelector('button[type="submit"]');
        if (tombol) {
            tombol.disabled = true;
            tombol.textContent = 'Menyimpan...';
        }
    });

    saringRak();
    terapkan();
})();
</script>
