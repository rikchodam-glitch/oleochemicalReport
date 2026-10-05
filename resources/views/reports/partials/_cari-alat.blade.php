{{-- Kotak pencarian alat lintas area pada form edit laporan.
     Dipakai admin ketika kode alat sudah diketahui tetapi areanya belum:
     memilih salah satu hasil akan mengisi Area, Functional Location, dan
     Kode Alat sekaligus (tanpa perlu menelusuri hirarki).
     Dipisah jadi partial supaya halaman blade yang besar tidak perlu ditulis
     ulang (lihat aturan edit blade di AGENTS.md).
     Parameter: $report = model Report yang sedang diedit. --}}
<div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
    <label for="cari_alat_lintas_area" class="block text-xs font-medium text-slate-700 mb-1">
        Cari Alat (semua area)
    </label>

    <input type="text" id="cari_alat_lintas_area" autocomplete="off"
           placeholder="Ketik minimal 2 huruf: kode alat, nomor equipment, atau deskripsi"
           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">

    <div class="flex items-center justify-between gap-3 mt-1">
        <p id="cari_alat_status" class="text-xs text-slate-400">
            Pilih salah satu hasil: Area, Functional Location, dan Kode Alat terisi otomatis.
        </p>

        <button type="button" id="kosongkan_lokasi"
                class="shrink-0 px-3 py-1.5 border border-slate-300 hover:bg-white text-slate-600 text-xs font-medium rounded-lg transition-colors">
            Kosongkan Lokasi &amp; Alat
        </button>
    </div>

    <div id="cari_alat_hasil" class="hidden mt-2 max-h-64 overflow-y-auto divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white"></div>
</div>

@push('scripts')
<script>
    // Pencarian alat lintas area (semua PT/Area) untuk form edit laporan.
    //
    // Aturan:
    //   - Minimal 2 huruf baru dicari, ditunda 250 ms supaya tidak membanjiri
    //     server saat admin masih mengetik.
    //   - Hasil dirender tanpa innerHTML (memakai textContent) supaya deskripsi
    //     alat dari database tidak bisa menyisipkan HTML.
    //   - Memilih hasil memanggil komponen Alpine reportLocationManager()
    //     (terapkanAset) agar Area, Functional Location, dan Kode Alat serentak
    //     terisi pada form.
    (function () {
        var kotak     = document.getElementById('cari_alat_lintas_area');
        var hasil     = document.getElementById('cari_alat_hasil');
        var status    = document.getElementById('cari_alat_status');
        var kosongkan = document.getElementById('kosongkan_lokasi');

        if (!kotak || !hasil || !status) {
            return;
        }

        var urlCari   = '{{ route('reports.locations.cari-alat') }}';
        var jedaCari  = null;
        var dataHasil = [];

        // Komponen Alpine pengelola lokasi; null bila Alpine belum siap.
        function komponenLokasi() {
            if (!window.Alpine || typeof window.Alpine.$data !== 'function') {
                return null;
            }

            var host = document.querySelector('[x-data="reportLocationManager()"], [x-data="reportDetail()"]');

            return host ? window.Alpine.$data(host) : null;
        }

        function kosongkanHasil() {
            hasil.innerHTML = '';
            hasil.classList.add('hidden');
            dataHasil = [];
        }

        // Render daftar hasil pencarian sebagai tombol yang bisa diklik.
        function gambarHasil(daftar) {
            dataHasil = daftar;
            hasil.innerHTML = '';

            if (!daftar.length) {
                kosongkanHasil();
                status.textContent = 'Alat tidak ditemukan. Coba kata kunci lain.';

                return;
            }

            daftar.forEach(function (item, posisi) {
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
                hasil.appendChild(baris);
            });

            hasil.classList.remove('hidden');
            status.textContent = daftar.length + ' alat ditemukan. Klik salah satu untuk mengisi lokasinya.';
        }

        // Ambil hasil pencarian dari server.
        async function cari() {
            var kunci = kotak.value.trim();

            if (kunci.length < 2) {
                kosongkanHasil();
                status.textContent = 'Ketik minimal 2 huruf untuk mencari alat.';

                return;
            }

            status.textContent = 'Mencari...';

            try {
                var balasan = await fetch(urlCari + '?q=' + encodeURIComponent(kunci), {
                    headers: { 'Accept': 'application/json' },
                });

                gambarHasil(await balasan.json());
            } catch (galat) {
                kosongkanHasil();
                status.textContent = 'Pencarian gagal. Coba lagi.';
            }
        }

        // Pakai satu hasil: isi Area, Functional Location, dan Kode Alat.
        async function pilih(item) {
            var komponen = komponenLokasi();

            if (komponen && typeof komponen.terapkanAset === 'function') {
                await komponen.terapkanAset(item);
            } else {
                // Jaring pengaman bila komponen Alpine belum tersedia.
                var area    = document.getElementById('area_id');
                var funcLoc = document.getElementById('funcloc_id');
                var aset    = document.getElementById('asset_id');

                if (area && item.area_id) {
                    area.value = item.area_id;
                    area.dispatchEvent(new Event('change'));
                }

                if (funcLoc) {
                    funcLoc.value = item.funcloc_id || '';
                }

                if (aset) {
                    aset.value = item.id;
                }
            }

            kotak.value = '';
            kosongkanHasil();
            status.textContent = 'Alat dipilih: ' + (item.tech_ident_no || item.equipment_no || '-')
                + '. Tekan "Simpan Perubahan" untuk menyimpan.';
        }

        kotak.addEventListener('input', function () {
            clearTimeout(jedaCari);
            jedaCari = setTimeout(cari, 250);
        });

        hasil.addEventListener('click', function (kejadian) {
            var tombol = kejadian.target.closest('button[data-posisi]');

            if (!tombol) {
                return;
            }

            var item = dataHasil[parseInt(tombol.dataset.posisi, 10)];

            if (item) {
                pilih(item);
            }
        });

        // Klik di luar kotak pencarian menutup daftar hasil.
        document.addEventListener('click', function (kejadian) {
            if (!kejadian.target.closest('#cari_alat_lintas_area') && !kejadian.target.closest('#cari_alat_hasil')) {
                kosongkanHasil();
            }
        });

        // Tombol "Kosongkan Lokasi & Alat": mengosongkan Area, FuncLoc, dan Alat.
        if (kosongkan) {
            kosongkan.addEventListener('click', function () {
                var komponen = komponenLokasi();

                if (komponen && typeof komponen.kosongkanLokasi === 'function') {
                    komponen.kosongkanLokasi();
                } else {
                    ['area_id', 'funcloc_id', 'asset_id'].forEach(function (id) {
                        var isian = document.getElementById(id);

                        if (isian) {
                            isian.value = '';
                        }
                    });
                }

                status.textContent = 'Lokasi & alat dikosongkan. Jangan lupa Simpan Perubahan.';
            });
        }
    })();
</script>
@endpush
