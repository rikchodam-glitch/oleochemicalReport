{{-- Daftar alat/lokasi tambahan (pekerjaan multi item) pada form edit laporan.
     Setiap item menjadi baris salinan laporan sendiri (multi_of) supaya riwayat
     alat dan halaman lokasi tetap menampilkan pekerjaan ini; baris salinan itu
     TIDAK dihitung pada man hour.
     Dipisah jadi partial supaya blade halaman edit tidak perlu ditulis ulang
     (lihat aturan edit blade di AGENTS.md).
     Parameter: $report, $laporanInduk, $alatTambahan, $funcLocs. --}}
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

    <div id="alat_tambahan_daftar" class="space-y-2">
        @forelse($alatTambahan as $item)
            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-slate-200">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-700 truncate">{{ $item->multi_item_label }}</p>
                    {{-- Tautan menuju detail ALAT/LOKASI (sama seperti bagian
                         "Mencakup N alat/lokasi lain"), BUKAN ke halaman baris
                         salinannya: satu pekerjaan bersama tetap tampil sebagai
                         satu laporan. Kode baris salinannya ditampilkan sebagai
                         keterangan di bawah tautan. --}}
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
        @empty
            <p class="text-xs text-slate-400">Belum ada alat/lokasi tambahan pada pekerjaan ini.</p>
        @endforelse
    </div>

    @if($laporanInduk->id === $report->id)
        <div class="mt-3 space-y-3">
            {{-- Tambah lewat pencarian alat --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <label for="item_cari_alat" class="block text-xs font-medium text-slate-700 mb-1">Tambah dari alat</label>
                <input type="text" id="item_cari_alat" autocomplete="off"
                       placeholder="Ketik minimal 2 huruf: kode alat, nomor equipment, atau deskripsi"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <div id="item_hasil_alat" class="hidden mt-2 max-h-48 overflow-y-auto divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white"></div>
            </div>

            {{-- Tambah lewat node lokasi (Functional Location) --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <label for="item_funcloc" class="block text-xs font-medium text-slate-700 mb-1">
                    Tambah dari lokasi (Functional Location)
                </label>

                <div class="flex flex-wrap items-end gap-2">
                    <select id="item_funcloc"
                            class="flex-1 min-w-[16rem] border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">- Pilih Functional Location -</option>
                        @foreach($funcLocs as $node)
                            <option value="{{ $node->id }}">{{ $node->code }} - {{ $node->name }}</option>
                        @endforeach
                    </select>

                    <button type="button" id="item_tambah_lokasi"
                            class="px-4 py-2 border border-slate-300 hover:bg-white text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Tambah Lokasi
                    </button>
                </div>

                <p class="text-xs text-slate-400 mt-1">
                    Pilihan lokasi mengikuti Area laporan ini ({{ $report->area?->code ?? 'area belum dipilih' }}).
                </p>
            </div>

            <p id="alat_tambahan_pesan" class="text-xs text-slate-400">
                Setiap item dibuat sebagai baris laporan tersendiri agar riwayat alat/lokasi lengkap; baris itu tidak dihitung pada man hour.
            </p>
        </div>
    @endif
</div>

@push('scripts')
<script>
    // Tambah/hapus alat atau lokasi tambahan (pekerjaan multi item).
    //
    // Memakai fetch seperti bagian kolaborasi supaya isian form yang belum
    // disimpan tidak hilang saat daftar item berubah. Hasil pencarian alat
    // dirender dengan textContent (bukan innerHTML) agar deskripsi alat dari
    // database tidak bisa menyisipkan HTML.
    (function () {
        var daftar       = document.getElementById('alat_tambahan_daftar');
        var cariAlat     = document.getElementById('item_cari_alat');
        var hasilAlat    = document.getElementById('item_hasil_alat');
        var funcloc      = document.getElementById('item_funcloc');
        var tombolLokasi = document.getElementById('item_tambah_lokasi');
        var pesan        = document.getElementById('alat_tambahan_pesan');

        if (!daftar) {
            return;
        }

        var urlTambah = '{{ route('reports.multi-items.store', $report) }}';
        var polaHapus = '{{ route('reports.multi-items.destroy', [$report, '__ID__']) }}';
        var urlCari   = '{{ route('reports.locations.cari-alat') }}';
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        var token     = metaToken ? metaToken.content : '';
        var jedaCari  = null;
        var dataHasil = [];

        // Tulis pesan status (sukses/galat/biasa) di bagian bawah.
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

        // Kirim permintaan ke server dan kembalikan isi JSON-nya.
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

        // Render ulang daftar item dari balasan server.
        function gambarDaftar(daftarItem) {
            daftar.innerHTML = '';

            if (!daftarItem.length) {
                var kosong = document.createElement('p');
                kosong.className = 'text-xs text-slate-400';
                kosong.textContent = 'Belum ada alat/lokasi tambahan pada pekerjaan ini.';
                daftar.appendChild(kosong);

                return;
            }

            daftarItem.forEach(function (item) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-slate-200';

                var info = document.createElement('div');
                info.className = 'min-w-0';

                var nama = document.createElement('p');
                nama.className = 'text-sm font-medium text-slate-700 truncate';
                nama.textContent = item.label;

                var kode = document.createElement('a');
                kode.className = 'text-xs font-medium font-mono text-teal-700 hover:underline';
                kode.href = item.url;
                kode.textContent = item.kode_alat || item.code;

                var kodeLaporan = document.createElement('span');
                kodeLaporan.className = 'block text-xs text-slate-400 font-mono';
                kodeLaporan.textContent = item.code;

                info.appendChild(nama);
                info.appendChild(kode);
                info.appendChild(kodeLaporan);

                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.className = 'btn-hapus-item shrink-0 px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-600 text-xs font-medium rounded-lg transition-colors';
                tombol.dataset.id = item.id;
                tombol.textContent = 'Hapus';

                baris.appendChild(info);
                baris.appendChild(tombol);
                daftar.appendChild(baris);
            });
        }

        // Kosongkan daftar hasil pencarian alat.
        function kosongkanHasil() {
            hasilAlat.innerHTML = '';
            hasilAlat.classList.add('hidden');
            dataHasil = [];
        }

        // Render hasil pencarian alat sebagai tombol yang bisa diklik.
        function gambarHasil(daftarItem) {
            dataHasil = daftarItem;
            hasilAlat.innerHTML = '';

            if (!daftarItem.length) {
                kosongkanHasil();
                tulisPesan('Alat tidak ditemukan. Coba kata kunci lain.', 'biasa');

                return;
            }

            daftarItem.forEach(function (item, posisi) {
                var baris = document.createElement('button');
                baris.type = 'button';
                baris.dataset.posisi = posisi;
                baris.className = 'w-full text-left px-3 py-2 hover:bg-slate-50 transition-colors';

                var kode = document.createElement('span');
                kode.className = 'block text-sm font-medium text-slate-800 font-mono';
                kode.textContent = (item.tech_ident_no || item.equipment_no || '-') + ' - ' + (item.description || '');

                var lokasi = document.createElement('span');
                lokasi.className = 'block text-xs text-slate-400';
                lokasi.textContent = (item.area_label || 'area belum diisi') + ' | ' + (item.functional_loc || 'tanpa funcloc');

                baris.appendChild(kode);
                baris.appendChild(lokasi);
                hasilAlat.appendChild(baris);
            });

            hasilAlat.classList.remove('hidden');
            tulisPesan(daftarItem.length + ' alat ditemukan. Klik salah satu untuk menambahkannya.', 'biasa');
        }

        // Tambah item lewat server, lalu perbarui daftar dari balasannya.
        async function tambahItem(body) {
            tulisPesan('Menyimpan...', 'biasa');

            try {
                var hasil = await kirim(urlTambah, 'POST', body);

                if (hasil.data.success) {
                    gambarDaftar(hasil.data.item);
                    tulisPesan(hasil.data.message, 'sukses');
                } else {
                    tulisPesan(hasil.data.message || 'Gagal menambah item.', 'galat');
                }
            } catch (galat) {
                tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
            }
        }

        // Cari alat ke server (ditunda 250 ms agar tidak membanjiri server).
        async function cariAlatKeServer() {
            var kunci = cariAlat.value.trim();

            if (kunci.length < 2) {
                kosongkanHasil();
                tulisPesan('Ketik minimal 2 huruf untuk mencari alat.', 'biasa');

                return;
            }

            tulisPesan('Mencari...', 'biasa');

            try {
                var balasan = await fetch(urlCari + '?q=' + encodeURIComponent(kunci), {
                    headers: { 'Accept': 'application/json' },
                });

                gambarHasil(await balasan.json());
            } catch (galat) {
                kosongkanHasil();
                tulisPesan('Pencarian gagal. Coba lagi.', 'galat');
            }
        }

        if (cariAlat && hasilAlat) {
            cariAlat.addEventListener('input', function () {
                clearTimeout(jedaCari);
                jedaCari = setTimeout(cariAlatKeServer, 250);
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
                tambahItem({ asset_id: item.id });
            });
        }

        if (tombolLokasi && funcloc) {
            tombolLokasi.addEventListener('click', function () {
                if (!funcloc.value) {
                    tulisPesan('Pilih lokasi terlebih dahulu.', 'galat');

                    return;
                }

                tambahItem({ funcloc_id: parseInt(funcloc.value, 10) });
            });
        }

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
                var hasil = await kirim(polaHapus.replace('__ID__', tombol.dataset.id), 'DELETE');

                if (hasil.data.success) {
                    gambarDaftar(hasil.data.item);
                    tulisPesan(hasil.data.message, 'sukses');
                } else {
                    tulisPesan(hasil.data.message || 'Gagal menghapus item.', 'galat');
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
