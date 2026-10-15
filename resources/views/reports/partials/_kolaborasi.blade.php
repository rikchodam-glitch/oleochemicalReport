{{-- Bagian kolaborasi pada form edit laporan.
     Menambah/menghapus teknisi kolaborator laporan INDUK. Setiap kolaborator
     disimpan sebagai baris salinan laporan tersendiri, jadi perubahan di sini
     ikut memengaruhi man hour teknisi kolaborator tersebut.
     Dipisah jadi partial supaya blade halaman edit tidak perlu ditulis ulang
     (lihat aturan edit blade di AGENTS.md).
     Parameter: $report, $laporanInduk, $kolaborator, $teknisiPilihan. --}}
<div class="border-t border-slate-100 pt-4" id="bagian_kolaborasi">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-medium text-slate-900">Kolaborasi Teknisi</h3>
        <span class="text-xs text-slate-400">
            {{ $kolaborator->count() }} kolaborator
        </span>
    </div>

    @if($laporanInduk->id !== $report->id)
        <p class="text-xs text-amber-600 mb-2">
            Laporan ini baris salinan. Kolaborator dikelola dari halaman detail laporan induk
            <a href="{{ route('reports.show', $laporanInduk) }}"
               class="font-medium text-blue-600 hover:underline">{{ $laporanInduk->report_code ?? ('#' . $laporanInduk->id) }}</a>.
        </p>
    @endif

    <div id="kolaborasi_daftar" class="space-y-2">
        @forelse($kolaborator as $baris)
            <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-slate-200" data-kolaborator="{{ $baris->id }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-700 truncate">
                        {{ $baris->technician?->name ?? ('Teknisi #' . $baris->technician_id) }}
                    </p>
                    <a href="{{ route('reports.show', $baris) }}"
                       class="text-xs text-blue-600 hover:text-blue-700 font-mono">{{ $baris->report_code ?? ('#' . $baris->id) }}</a>
                </div>

                @if($laporanInduk->id === $report->id)
                    <button type="button" data-id="{{ $baris->id }}"
                            class="btn-hapus-kolaborator shrink-0 px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-600 text-xs font-medium rounded-lg transition-colors">
                        Hapus
                    </button>
                @endif
            </div>
        @empty
            <p class="text-xs text-slate-400" id="kolaborasi_kosong">Belum ada teknisi kolaborator pada laporan ini.</p>
        @endforelse
    </div>

    @if($laporanInduk->id === $report->id)
        <div class="mt-3 flex flex-wrap items-end gap-2">
            <div class="flex-1 min-w-[16rem]">
                <label for="kolaborator_teknisi" class="block text-xs font-medium text-slate-700 mb-1">Tambah Kolaborator</label>
                <select id="kolaborator_teknisi"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">- Pilih Teknisi -</option>
                    @foreach($teknisiPilihan as $teknisi)
                        @if((int) $teknisi->id !== (int) $report->technician_id)
                            <option value="{{ $teknisi->id }}">
                                {{ $teknisi->name }}{{ $teknisi->nik ? ' (' . $teknisi->nik . ')' : '' }}
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>

            <button type="button" id="kolaborator_tambah"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Tambah
            </button>
        </div>

        <p id="kolaborasi_pesan" class="text-xs mt-2 text-slate-400">
            Kolaborator dibuat sebagai baris laporan tersendiri, sehingga ikut terhitung pada man hour teknisi tersebut.
        </p>
    @endif
</div>

@push('scripts')
<script>
    // Tambah/hapus kolaborator laporan lewat fetch ke rute kolaborator.
    //
    // Kenapa fetch (bukan form biasa): halaman edit ini satu form besar untuk
    // seluruh isian laporan. Kalau memakai submit biasa, isian yang belum
    // disimpan akan hilang setiap kali kolaborator ditambah/dihapus. Dengan
    // fetch, daftar kolaborator diperbarui dari balasan server dan isian form
    // tetap utuh.
    (function () {
        var wadah  = document.getElementById('kolaborasi_daftar');
        var pilih  = document.getElementById('kolaborator_teknisi');
        var tambah = document.getElementById('kolaborator_tambah');
        var pesan  = document.getElementById('kolaborasi_pesan');

        if (!wadah) {
            return;
        }

        var urlTambah = '{{ route('reports.collaborators.store', $report) }}';
        var polaHapus = '{{ route('reports.collaborators.destroy', [$report, '__ID__']) }}';
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        var token     = metaToken ? metaToken.content : '';

        // Tulis pesan status (sukses/galat/biasa) di bawah kotak tambah.
        function tulisPesan(teks, jenis) {
            if (!pesan) {
                return;
            }

            pesan.textContent = teks;

            if (jenis === 'galat') {
                pesan.className = 'text-xs mt-2 text-red-600';
            } else if (jenis === 'sukses') {
                pesan.className = 'text-xs mt-2 text-teal-700';
            } else {
                pesan.className = 'text-xs mt-2 text-slate-400';
            }
        }

        // Render ulang daftar kolaborator dari data balasan server.
        function gambarDaftar(daftar) {
            wadah.innerHTML = '';

            if (!daftar.length) {
                var kosong = document.createElement('p');
                kosong.className = 'text-xs text-slate-400';
                kosong.textContent = 'Belum ada teknisi kolaborator pada laporan ini.';
                wadah.appendChild(kosong);

                return;
            }

            daftar.forEach(function (item) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-slate-200';
                baris.dataset.kolaborator = item.id;

                var info = document.createElement('div');
                info.className = 'min-w-0';

                var nama = document.createElement('p');
                nama.className = 'text-sm font-medium text-slate-700 truncate';
                nama.textContent = item.name;

                var kode = document.createElement('a');
                kode.className = 'text-xs text-blue-600 hover:text-blue-700 font-mono';
                kode.href = item.url;
                kode.textContent = item.code;

                info.appendChild(nama);
                info.appendChild(kode);

                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.className = 'btn-hapus-kolaborator shrink-0 px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-600 text-xs font-medium rounded-lg transition-colors';
                tombol.dataset.id = item.id;
                tombol.textContent = 'Hapus';

                baris.appendChild(info);
                baris.appendChild(tombol);
                wadah.appendChild(baris);
            });
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

        if (tambah && pilih) {
            tambah.addEventListener('click', async function () {
                if (!pilih.value) {
                    tulisPesan('Pilih teknisi terlebih dahulu.', 'galat');

                    return;
                }

                tambah.disabled = true;
                tulisPesan('Menyimpan...', 'biasa');

                try {
                    var hasil = await kirim(urlTambah, 'POST', { technician_id: parseInt(pilih.value, 10) });

                    if (hasil.data.success) {
                        gambarDaftar(hasil.data.kolaborator);
                        pilih.value = '';
                        tulisPesan(hasil.data.message, 'sukses');
                    } else {
                        tulisPesan(hasil.data.message || 'Gagal menambah kolaborator.', 'galat');
                    }
                } catch (galat) {
                    tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
                } finally {
                    tambah.disabled = false;
                }
            });
        }

        wadah.addEventListener('click', async function (kejadian) {
            var tombol = kejadian.target.closest('.btn-hapus-kolaborator');

            if (!tombol) {
                return;
            }

            if (!window.confirm('Hapus kolaborator ini? Baris laporannya juga ikut terhapus.')) {
                return;
            }

            var id = tombol.dataset.id;
            tombol.disabled = true;
            tulisPesan('Menghapus...', 'biasa');

            try {
                var hasil = await kirim(polaHapus.replace('__ID__', id), 'DELETE');

                if (hasil.data.success) {
                    gambarDaftar(hasil.data.kolaborator);
                    tulisPesan(hasil.data.message, 'sukses');
                } else {
                    tulisPesan(hasil.data.message || 'Gagal menghapus kolaborator.', 'galat');
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
