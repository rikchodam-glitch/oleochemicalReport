{{-- Unggah & hapus foto laporan dari halaman edit.
     Foto hanya boleh diubah dari laporan INDUK karena baris salinan (kolaborasi
     maupun multi alat) memakai PATH FOTO YANG SAMA — menghapus dari baris
     salinan akan ikut menghapus berkas milik laporan induk.
     Kotak berkas di bawah sengaja TIDAK diberi atribut `name` supaya tidak ikut
     terkirim saat form edit (PUT) disimpan; unggahan lewat fetch.
     Parameter: $report, $laporanInduk. --}}
@php
    $bolehUbahFoto = $laporanInduk->id === $report->id;
@endphp

<div class="border-t border-slate-100 pt-4" id="bagian_foto">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-medium text-slate-900">Foto Laporan</h3>
        <span id="foto_jumlah" class="text-xs text-slate-400">
            {{ count($report->photo_documentation ?? []) }} dokumentasi &middot;
            {{ count($report->photo_hygiene_clearance ?? []) }} hygiene
        </span>
    </div>

    @if(! $bolehUbahFoto)
        <p class="text-xs text-amber-600 mb-2">
            Laporan ini baris salinan (fotonya sama dengan laporan induk
            <a href="{{ route('reports.edit', $laporanInduk) }}"
               class="font-medium text-blue-600 hover:underline">{{ $laporanInduk->report_code ?? ('#' . $laporanInduk->id) }}</a>).
            Unggah dan hapus foto dilakukan dari laporan induk.
        </p>
    @endif

    {{-- Foto dokumentasi --}}
    <div class="mb-4">
        <p class="text-xs font-medium text-slate-700 mb-2">Foto Dokumentasi</p>
        <div id="foto_dokumentasi" class="grid grid-cols-3 sm:grid-cols-4 gap-3">
            @forelse($report->photo_documentation_urls as $posisi => $url)
                <div class="relative group" data-posisi="{{ $posisi }}">
                    <a href="{{ $url }}" target="_blank" rel="noopener"
                       class="block rounded-lg overflow-hidden border border-slate-200">
                        <img src="{{ $url }}" alt="Foto dokumentasi {{ $posisi + 1 }}" class="w-full h-24 object-cover">
                    </a>

                    @if($bolehUbahFoto)
                        <button type="button" data-tipe="documentation" data-posisi="{{ $posisi }}"
                                title="Hapus foto ini"
                                class="btn-hapus-foto absolute top-1 right-1 inline-flex items-center justify-center w-6 h-6 rounded-md bg-white/90 border border-red-300 text-red-600 text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity">
                            &times;
                        </button>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-400 col-span-full">Belum ada foto dokumentasi.</p>
            @endforelse
        </div>
    </div>

    {{-- Foto hygiene clearance --}}
    <div class="mb-4">
        <p class="text-xs font-medium text-slate-700 mb-2">Foto Hygiene Clearance</p>
        <div id="foto_hygiene" class="grid grid-cols-3 sm:grid-cols-4 gap-3">
            @forelse($report->photo_hygiene_urls as $posisi => $url)
                <div class="relative group" data-posisi="{{ $posisi }}">
                    <a href="{{ $url }}" target="_blank" rel="noopener"
                       class="block rounded-lg overflow-hidden border border-slate-200">
                        <img src="{{ $url }}" alt="Foto hygiene {{ $posisi + 1 }}" class="w-full h-24 object-cover">
                    </a>

                    @if($bolehUbahFoto)
                        <button type="button" data-tipe="hygiene" data-posisi="{{ $posisi }}"
                                title="Hapus foto ini"
                                class="btn-hapus-foto absolute top-1 right-1 inline-flex items-center justify-center w-6 h-6 rounded-md bg-white/90 border border-red-300 text-red-600 text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity">
                            &times;
                        </button>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-400 col-span-full">Belum ada foto hygiene clearance.</p>
            @endforelse
        </div>
    </div>

    @if($bolehUbahFoto)
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <label for="foto_berkas" class="block text-xs font-medium text-slate-700 mb-1">
                Unggah Foto (boleh beberapa berkas sekaligus)
            </label>
            <input type="file" id="foto_berkas" accept="image/*" multiple
                   class="w-full text-xs text-slate-600">

            <div class="flex flex-wrap items-end gap-2 mt-2">
                <div>
                    <label for="foto_jenis" class="block text-xs text-slate-500 mb-1">Jenis Foto</label>
                    <select id="foto_jenis"
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="documentation">Dokumentasi</option>
                        <option value="hygiene">Hygiene Clearance</option>
                    </select>
                </div>

                <button type="button" id="foto_unggah"
                        class="px-4 py-2 border border-slate-300 hover:bg-white text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Unggah
                </button>
            </div>

            <p id="foto_pesan" class="text-xs text-slate-400 mt-2">
                Berkas disimpan pada penyimpanan yang sama dengan foto yang dikirim teknisi lewat bot Telegram.
            </p>
        </div>
    @endif
</div>

@push('scripts')
<script>
    // Unggah (banyak berkas sekaligus) & hapus foto dari halaman edit laporan.
    //
    // Memakai fetch supaya isian form edit yang belum disimpan tidak hilang.
    // Setelah foto dihapus, nomor urut (data-posisi) gambar di grid itu
    // dinomori ulang karena server menghapus berdasarkan posisi dalam array.
    (function () {
        var wadahDokumentasi = document.getElementById('foto_dokumentasi');
        var wadahHygiene     = document.getElementById('foto_hygiene');
        var isian            = document.getElementById('foto_berkas');
        var jenis            = document.getElementById('foto_jenis');
        var tombolUnggah     = document.getElementById('foto_unggah');
        var pesan            = document.getElementById('foto_pesan');
        var jumlah           = document.getElementById('foto_jumlah');

        if (!wadahDokumentasi || !wadahHygiene) {
            return;
        }

        var urlUnggah = '{{ route('reports.add-photo', $report) }}';
        var polaHapus = '{{ route('reports.photos.delete', [$report, '__INDEX__']) }}';
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        var token     = metaToken ? metaToken.content : '';

        // Tulis pesan status di bawah kotak unggah.
        function tulisPesan(teks, jenisPesan) {
            if (!pesan) {
                return;
            }

            pesan.textContent = teks;

            if (jenisPesan === 'galat') {
                pesan.className = 'text-xs mt-2 text-red-600';
            } else if (jenisPesan === 'sukses') {
                pesan.className = 'text-xs mt-2 text-teal-700';
            } else {
                pesan.className = 'text-xs mt-2 text-slate-400';
            }
        }

        // Perbarui ringkasan jumlah foto di kanan judul bagian.
        function segarkanJumlah(jumlahDokumentasi, jumlahHygiene) {
            if (!jumlah) {
                return;
            }

            jumlah.textContent = jumlahDokumentasi + ' dokumentasi '
                + String.fromCharCode(183) + ' ' + jumlahHygiene + ' hygiene';
        }

        // Jumlah gambar yang sedang tampil pada satu grid.
        function hitungGrid(wadah) {
            return wadah.querySelectorAll('[data-posisi]').length;
        }

        // Bangun satu thumbnail beserta tombol hapusnya.
        function buatGambar(url, tipe, posisi, bolehHapus) {
            var wadah = document.createElement('div');
            wadah.className = 'relative group';
            wadah.dataset.posisi = posisi;

            var tautan = document.createElement('a');
            tautan.href = url;
            tautan.target = '_blank';
            tautan.rel = 'noopener';
            tautan.className = 'block rounded-lg overflow-hidden border border-slate-200';

            var gambar = document.createElement('img');
            gambar.src = url;
            gambar.alt = 'Foto ' + tipe + ' ' + (posisi + 1);
            gambar.className = 'w-full h-24 object-cover';

            tautan.appendChild(gambar);
            wadah.appendChild(tautan);

            if (bolehHapus) {
                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.dataset.tipe = tipe;
                tombol.dataset.posisi = posisi;
                tombol.title = 'Hapus foto ini';
                tombol.className = 'btn-hapus-foto absolute top-1 right-1 inline-flex items-center justify-center w-6 h-6 rounded-md bg-white/90 border border-red-300 text-red-600 text-xs font-bold opacity-0 group-hover:opacity-100 transition-opacity';
                tombol.innerHTML = '&times;';
                wadah.appendChild(tombol);
            }

            return wadah;
        }

        // Render ulang satu grid foto dari daftar URL.
        function gambarGrid(wadah, daftar, tipe, bolehHapus) {
            wadah.innerHTML = '';

            if (!daftar.length) {
                var kosong = document.createElement('p');
                kosong.className = 'text-xs text-slate-400 col-span-full';
                kosong.textContent = 'Belum ada foto '
                    + (tipe === 'hygiene' ? 'hygiene clearance' : 'dokumentasi') + '.';
                wadah.appendChild(kosong);

                return;
            }

            daftar.forEach(function (url, posisi) {
                wadah.appendChild(buatGambar(url, tipe, posisi, bolehHapus));
            });
        }

        // Nomori ulang data-posisi setelah satu gambar dibuang dari grid.
        function renomori(wadah) {
            var posisi = 0;

            Array.prototype.forEach.call(wadah.children, function (anak) {
                if (!anak.querySelector('a')) {
                    return;
                }

                anak.dataset.posisi = posisi;

                var tombol = anak.querySelector('.btn-hapus-foto');

                if (tombol) {
                    tombol.dataset.posisi = posisi;
                }

                posisi++;
            });
        }

        if (isian && tombolUnggah) {
            tombolUnggah.addEventListener('click', async function () {
                if (!isian.files || isian.files.length === 0) {
                    tulisPesan('Pilih berkas foto terlebih dahulu.', 'galat');

                    return;
                }

                var data = new FormData();
                data.append('type', jenis ? jenis.value : 'documentation');

                Array.prototype.forEach.call(isian.files, function (berkas) {
                    data.append('photos[]', berkas);
                });

                tombolUnggah.disabled = true;
                tulisPesan('Mengunggah...', 'biasa');

                try {
                    var balasan = await fetch(urlUnggah, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': token,
                        },
                        body: data,
                    });

                    var hasil = await balasan.json();

                    if (hasil.success) {
                        var daftarDokumentasi = hasil.foto && hasil.foto.documentation ? hasil.foto.documentation : [];
                        var daftarHygiene     = hasil.foto && hasil.foto.hygiene ? hasil.foto.hygiene : [];

                        gambarGrid(wadahDokumentasi, daftarDokumentasi, 'documentation', true);
                        gambarGrid(wadahHygiene, daftarHygiene, 'hygiene', true);
                        segarkanJumlah(daftarDokumentasi.length, daftarHygiene.length);

                        isian.value = '';
                        tulisPesan(hasil.message, 'sukses');
                    } else {
                        tulisPesan(hasil.message || 'Gagal mengunggah foto.', 'galat');
                    }
                } catch (galat) {
                    tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
                } finally {
                    tombolUnggah.disabled = false;
                }
            });
        }

        document.addEventListener('click', async function (kejadian) {
            var tombol = kejadian.target.closest('.btn-hapus-foto');

            if (!tombol) {
                return;
            }

            if (!window.confirm('Hapus foto ini dari laporan?')) {
                return;
            }

            var tipe  = tombol.dataset.tipe;
            var index = tombol.dataset.posisi;
            var wadah = tipe === 'hygiene' ? wadahHygiene : wadahDokumentasi;

            tombol.disabled = true;
            tulisPesan('Menghapus foto...', 'biasa');

            try {
                var balasan = await fetch(polaHapus.replace('__INDEX__', index) + '?type=' + tipe, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                });

                var hasil = await balasan.json();

                if (hasil.success) {
                    var kartu = tombol.closest('[data-posisi]');

                    if (kartu) {
                        kartu.remove();
                    }

                    if (hitungGrid(wadah) === 0) {
                        gambarGrid(wadah, [], tipe, true);
                    } else {
                        renomori(wadah);
                    }

                    segarkanJumlah(hitungGrid(wadahDokumentasi), hitungGrid(wadahHygiene));
                    tulisPesan('1 foto dihapus dari laporan.', 'sukses');
                } else {
                    tulisPesan(hasil.message || 'Gagal menghapus foto.', 'galat');
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
