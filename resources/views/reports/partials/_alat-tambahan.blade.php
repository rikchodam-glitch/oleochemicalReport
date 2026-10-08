{{-- Daftar alat/lokasi tambahan (pekerjaan multi item) pada mode edit laporan.

     Setiap item menjadi baris salinan laporan sendiri (multi_of) supaya riwayat
     alat dan halaman lokasi tetap menampilkan pekerjaan ini; baris salinan itu
     TIDAK dihitung pada man hour.

     Dua perubahan besar dibanding versi lama:
     1. Jalur menambah item disatukan. Dulu ada dua kotak terpisah (cari alat dan
        dropdown Functional Location per Area). Sekarang keduanya menjadi SATU
        panel: penelusuran hierarki (PT -> Departemen -> Area -> Section -> Alat,
        memakai widget bersama _pilih-lokasi-hirarki) plus satu kotak pencarian
        alat lintas area sebagai jalan pintas.
     2. Daftar item DIKELOMPOKKAN per lokasi (Section) dan tiap baris diberi
        lencana ALAT/LOKASI supaya pekerjaan yang menyentuh banyak alat terbaca
        rapi.

     Dipisah jadi partial supaya blade halaman detail yang besar tidak perlu
     ditulis ulang (lihat aturan edit blade di AGENTS.md).

     Parameter: $report, $laporanInduk, $alatTambahan. --}}
@php
    // Kelompokkan item per lokasi (Section alat/lokasi itu). Baris salinan alat
    // menyimpan funcloc_id alatnya, jadi alat dan lokasi memakai penanda grup
    // yang sama seperti balasan JSON ReportMultiItemController::daftarItem().
    $kelompokItem = $alatTambahan->groupBy(function ($item) {
        return $item->functionalLocation?->code ?? $item->asset?->functional_loc ?? 'Tanpa lokasi';
    });
@endphp

<div class="border-t border-slate-100 pt-4" id="bagian_alat_tambahan">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-medium text-slate-900">Alat / Lokasi Tambahan</h3>
        <span class="text-xs text-slate-400">{{ $alatTambahan->count() }} item tambahan</span>
    </div>

    @if($laporanInduk->id !== $report->id)
        <p class="text-xs text-amber-600 mb-2">
            Laporan ini baris salinan. Daftar alat tambahan dikelola dari halaman detail laporan induk
            <a href="{{ route('reports.show', $laporanInduk) }}"
               class="font-medium text-blue-600 hover:underline">{{ $laporanInduk->report_code ?? ('#' . $laporanInduk->id) }}</a>.
        </p>
    @endif

    <div id="alat_tambahan_daftar" class="space-y-3">
        @forelse($kelompokItem as $grup => $daftar)
            <div class="rounded-lg border border-slate-200">
                <p class="px-3 py-2 bg-slate-50 border-b border-slate-200 text-xs font-medium text-slate-600">
                    {{ $grup }}
                </p>

                <div class="divide-y divide-slate-100">
                    @foreach($daftar as $item)
                        <div class="flex items-center justify-between gap-3 px-3 py-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-700 truncate">
                                    <span class="inline-flex px-1.5 py-0.5 mr-1 align-middle text-[10px] font-semibold rounded border
                                        {{ $item->asset ? 'bg-teal-50 text-teal-700 border-teal-100' : 'bg-slate-100 text-slate-600 border-slate-200' }}">
                                        {{ $item->asset ? 'ALAT' : 'LOKASI' }}
                                    </span>
                                    {{ $item->multi_item_label }}
                                </p>

                                {{-- Tautan menuju detail ALAT/LOKASI (sama seperti bagian
                                     "Mencakup N alat/lokasi lain"), BUKAN ke halaman baris
                                     salinannya: satu pekerjaan bersama tetap tampil sebagai
                                     satu laporan. Kode baris salinannya menjadi keterangan. --}}
                                <a href="{{ $item->asset
                                        ? route('assets.show', $item->asset)
                                        : ($item->functionalLocation
                                            ? route('locations.show', $item->functionalLocation)
                                            : route('reports.show', $laporanInduk)) }}"
                                   title="{{ $item->asset ? 'Detail equipment' : 'Detail lokasi' }}"
                                   class="text-xs font-medium font-mono text-teal-700 hover:underline">
                                    {{ $item->asset?->tech_ident_no ?? $item->functionalLocation?->code ?? 'Detail lokasi' }}
                                </a>
                                <span class="block text-xs text-slate-400 font-mono">
                                    {{ $item->report_code ?? ('#' . $item->id) }}
                                </span>
                            </div>

                            @if($laporanInduk->id === $report->id)
                                <button type="button" data-id="{{ $item->id }}"
                                        class="btn-hapus-item shrink-0 px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-600 text-xs font-medium rounded-lg transition-colors">
                                    Hapus
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-xs text-slate-400">Belum ada alat/lokasi tambahan pada pekerjaan ini.</p>
        @endforelse
    </div>

    @if($laporanInduk->id === $report->id)
        <div class="mt-4 space-y-3">
            {{-- Panel tunggal: telusuri hierarki lokasi, lalu pilih alat atau lokasinya. --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"
                 data-pilih-hirarki
                 data-mode="item"
                 data-url="{{ route('reports.locations.hirarki') }}"
                 data-url-tambah="{{ route('reports.multi-items.store', $report) }}"
                 data-jalur="[]"
                 data-label-alat="Tambah sebagai alat"
                 data-label-lokasi="Tambah lokasi ini">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-medium text-slate-700">Tambah dari Hirarki Lokasi</p>
                    <span data-peran="status" class="text-xs text-slate-400">
                        PT &rarr; Departemen &rarr; Area &rarr; Section &rarr; Alat
                    </span>
                </div>

                @include('reports.partials._pilih-lokasi-hirarki-badan')

                @include('reports.partials._pilih-lokasi-hirarki-script')
            </div>

            {{-- Jalan pintas: kode alatnya sudah diketahui, areanya belum. --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <label for="item_cari_alat" class="block text-xs font-medium text-slate-700 mb-1">
                    Tambah dari alat (pencarian cepat semua area)
                </label>
                <input type="text" id="item_cari_alat" autocomplete="off"
                       placeholder="Ketik minimal 2 huruf: kode alat, nomor equipment, atau deskripsi"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <div id="item_hasil_alat" class="hidden mt-2 max-h-48 overflow-y-auto divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white"></div>
            </div>

            <p id="alat_tambahan_pesan" class="text-xs text-slate-400">
                Setiap item dibuat sebagai baris laporan tersendiri agar riwayat alat/lokasi lengkap; baris itu tidak dihitung pada man hour.
            </p>
        </div>
    @endif
</div>

@push('scripts')
<script>
    // Panel alat/lokasi tambahan (pekerjaan multi item).
    //
    // Dua jalur yang tersedia sekarang memakai widget hierarki BERSAMA
    // (window.pilihLokasiHirarki pada _pilih-lokasi-hirarki-script) sehingga
    // penambahan lewat hierarki tidak perlu logika sendiri di sini. Skrip ini
    // hanya menangani daftar item (gambar ulang + hapus) dan kotak pencarian
    // cepat alat lintas area.
    //
    // Memakai fetch supaya isian form yang belum disimpan tidak hilang saat
    // daftar item berubah. Hasil pencarian dirender dengan textContent (bukan
    // innerHTML) agar deskripsi alat dari database tidak bisa menyisipkan HTML.
    (function () {
        var daftar    = document.getElementById('alat_tambahan_daftar');
        var cariAlat  = document.getElementById('item_cari_alat');
        var hasilAlat = document.getElementById('item_hasil_alat');
        var pesan     = document.getElementById('alat_tambahan_pesan');

        if (!daftar) {
            return;
        }

        var urlHapus = '{{ route('reports.multi-items.destroy', [$report, '__ID__']) }}';
        var urlCari  = '{{ route('reports.locations.cari-alat') }}';
        var urlTambahItem = '{{ route('reports.multi-items.store', $laporanInduk) }}';
        // Tombol hapus hanya berarti di laporan INDUK; baris salinan memakai
        // berkas/daftar yang dikelola dari induknya.
        var bolehHapus = {{ $laporanInduk->id === $report->id ? 'true' : 'false' }};
        var meta     = document.querySelector('meta[name="csrf-token"]');
        var token    = meta ? meta.content : '';
        var jedaCari = null;
        var dataHasil = [];

        // Tulis pesan status (sukses/galat/biasa) di bagian bawah panel.
        function tulisPesan(teks, jenis) {
            if (!pesan) {
                return;
            }

            pesan.textContent = teks;

            if (jenis === 'galat') {
                pesan.className = 'text-xs text-red-600';
            } else if (jenis === 'sukses') {
                pesan.className = 'text-xs text-teal-700';
            } else {
                pesan.className = 'text-xs text-slate-400';
            }
        }

        // Kirim permintaan ke server dan kembalikan status + isi JSON-nya.
        async function kirim(url, metode, body) {
            var opsi = {
                method: metode,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
            };

            if (body) {
                opsi.headers['Content-Type'] = 'application/json';
                opsi.body = JSON.stringify(body);
            }

            var balasan = await fetch(url, opsi);

            return { status: balasan.status, data: await balasan.json() };
        }

        // Satu baris item: lencana ALAT/LOKASI, label, tautan detail alat/lokasi,
        // kode baris salinan, dan tombol hapus.
        //
        // @param {object} item Data dari server: id, label, kode_alat, code, jenis, url
        function barisItem(item) {
            var baris = document.createElement('div');
            baris.className = 'flex items-center justify-between gap-3 px-3 py-2';

            var info = document.createElement('div');
            info.className = 'min-w-0';

            var nama = document.createElement('p');
            nama.className = 'text-sm font-medium text-slate-700 truncate';

            var lencana = document.createElement('span');
            lencana.className = 'inline-flex px-1.5 py-0.5 mr-1 align-middle text-[10px] font-semibold rounded border '
                + (item.jenis === 'alat'
                    ? 'bg-teal-50 text-teal-700 border-teal-100'
                    : 'bg-slate-100 text-slate-600 border-slate-200');
            lencana.textContent = item.jenis === 'alat' ? 'ALAT' : 'LOKASI';

            nama.appendChild(lencana);
            nama.appendChild(document.createTextNode(item.label));
            info.appendChild(nama);

            var tautan = document.createElement('a');
            tautan.href = item.url;
            tautan.className = 'text-xs font-medium font-mono text-teal-700 hover:underline';
            tautan.textContent = item.kode_alat;
            info.appendChild(tautan);

            var kode = document.createElement('span');
            kode.className = 'block text-xs text-slate-400 font-mono';
            kode.textContent = item.code;
            info.appendChild(kode);

            baris.appendChild(info);

            if (bolehHapus) {
                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.className = 'btn-hapus-item shrink-0 px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-600 text-xs font-medium rounded-lg transition-colors';
                tombol.dataset.id = item.id;
                tombol.textContent = 'Hapus';
                baris.appendChild(tombol);
            }

            return baris;
        }

        // Gambar ulang daftar item dari balasan server, DIKELOMPOKKAN per lokasi
        // supaya pekerjaan yang menyentuh banyak alat tetap mudah dibaca.
        //
        // @param {Array} daftarItem Data item dari ReportMultiItemController
        function gambarDaftar(daftarItem) {
            daftar.innerHTML = '';

            if (!daftarItem || !daftarItem.length) {
                var kosong = document.createElement('p');
                kosong.className = 'text-xs text-slate-400';
                kosong.textContent = 'Belum ada alat/lokasi tambahan pada pekerjaan ini.';
                daftar.appendChild(kosong);

                return;
            }

            var urutan   = [];
            var kelompok = {};

            daftarItem.forEach(function (item) {
                var grup = item.grup || 'Tanpa lokasi';

                if (!kelompok[grup]) {
                    kelompok[grup] = [];
                    urutan.push(grup);
                }

                kelompok[grup].push(item);
            });

            urutan.forEach(function (grup) {
                var kotak = document.createElement('div');
                kotak.className = 'rounded-lg border border-slate-200';

                var judul = document.createElement('p');
                judul.className = 'px-3 py-2 bg-slate-50 border-b border-slate-200 text-xs font-medium text-slate-600';
                judul.textContent = grup;
                kotak.appendChild(judul);

                var isi = document.createElement('div');
                isi.className = 'divide-y divide-slate-100';

                kelompok[grup].forEach(function (item) {
                    isi.appendChild(barisItem(item));
                });

                kotak.appendChild(isi);
                daftar.appendChild(kotak);
            });
        }

        // Dipakai skrip pemilih hierarki (mode item) supaya daftar langsung
        // diperbarui tanpa memuat ulang halaman.
        window.gambarDaftarAlatTambahan = gambarDaftar;

        // --- Pencarian cepat alat lintas area -------------------------------

        // Kosongkan daftar hasil pencarian.
        function kosongkanHasil() {
            if (!hasilAlat) {
                return;
            }

            hasilAlat.innerHTML = '';
            hasilAlat.classList.add('hidden');
            dataHasil = [];
        }

        // Render hasil pencarian sebagai tombol yang bisa diklik.
        function gambarHasil(data) {
            if (!hasilAlat) {
                return;
            }

            dataHasil = data || [];
            hasilAlat.innerHTML = '';

            if (!dataHasil.length) {
                var kosong = document.createElement('p');
                kosong.className = 'px-3 py-2 text-xs text-slate-400';
                kosong.textContent = 'Alat tidak ditemukan.';
                hasilAlat.appendChild(kosong);
                hasilAlat.classList.remove('hidden');

                return;
            }

            dataHasil.forEach(function (item, posisi) {
                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.dataset.posisi = posisi;
                tombol.className = 'w-full text-left px-3 py-2 hover:bg-slate-50 transition-colors';

                var kode = document.createElement('p');
                kode.className = 'text-sm font-medium text-slate-700 font-mono';
                kode.textContent = item.tech_ident_no || item.equipment_no || ('#' + item.id);
                tombol.appendChild(kode);

                var ket = document.createElement('p');
                ket.className = 'text-xs text-slate-400 truncate';
                ket.textContent = (item.description || 'Tanpa deskripsi')
                    + (item.area_label ? ' - ' + item.area_label : '');
                tombol.appendChild(ket);

                hasilAlat.appendChild(tombol);
            });

            hasilAlat.classList.remove('hidden');
        }

        // Tambah satu alat (dipakai pencarian cepat maupun jalur lain).
        async function tambahAlat(assetId) {
            tulisPesan('Menambahkan...', 'biasa');

            try {
                var hasil = await kirim(urlTambahItem, 'POST', { asset_id: assetId });

                if (hasil.data.success) {
                    gambarDaftar(hasil.data.item);
                    tulisPesan(hasil.data.message || 'Item tambahan ditambahkan.', 'sukses');
                } else {
                    tulisPesan(hasil.data.message || 'Gagal menambah item tambahan.', 'galat');
                }
            } catch (galat) {
                tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
            }
        }

        if (cariAlat && hasilAlat) {
            cariAlat.addEventListener('input', function () {
                clearTimeout(jedaCari);
                jedaCari = setTimeout(async function () {
                    var kunci = cariAlat.value.trim();

                    if (kunci.length < 2) {
                        kosongkanHasil();
                        tulisPesan('Ketik minimal 2 huruf untuk mencari alat.', 'biasa');

                        return;
                    }

                    try {
                        var balasan = await fetch(urlCari + '?q=' + encodeURIComponent(kunci), {
                            headers: { 'Accept': 'application/json' },
                        });

                        gambarHasil(await balasan.json());
                    } catch (galat) {
                        kosongkanHasil();
                        tulisPesan('Pencarian gagal. Coba lagi.', 'galat');
                    }
                }, 250);
            });

            hasilAlat.addEventListener('click', function (kejadian) {
                var tombol = kejadian.target.closest('button[data-posisi]');

                if (!tombol) {
                    return;
                }

                var item = dataHasil[parseInt(tombol.dataset.posisi, 10)];

                if (!item) {
                    return;
                }

                cariAlat.value = '';
                kosongkanHasil();
                tambahAlat(item.id);
            });
        }

        // --- Hapus item -----------------------------------------------------

        daftar.addEventListener('click', async function (kejadian) {
            var tombol = kejadian.target.closest('.btn-hapus-item');

            if (!tombol) {
                return;
            }

            if (!window.confirm('Hapus alat/lokasi tambahan ini? Baris laporannya juga ikut terhapus.')) {
                return;
            }

            tombol.disabled = true;
            tulisPesan('Menghapus...', 'biasa');

            try {
                var hasil = await kirim(urlHapus.replace('__ID__', tombol.dataset.id), 'DELETE');

                if (hasil.data.success) {
                    gambarDaftar(hasil.data.item);
                    tulisPesan(hasil.data.message || 'Item tambahan dihapus.', 'sukses');
                } else {
                    tulisPesan(hasil.data.message || 'Gagal menghapus item tambahan.', 'galat');
                    tombol.disabled = false;
                }
            } catch (galat) {
                tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
                tombol.disabled = false;
            }
        });
    })();
</script>
@endpush
