@extends('layouts.app')

@section('title', 'Monitoring Ruang Panel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Monitoring Ruang Panel</span>
@endsection

@section('content')
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Monitoring Ruang Panel</h1>
            <p class="text-sm text-slate-500 mt-1">
                Suhu dan kelembapan tiap ruangan panel, diperbarui otomatis tiap
                {{ $intervalPolling }} detik. Data ditarik dari perangkat sensor oleh server
                (mode tarik), jadi tidak perlu membuka port di firewall.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="tombolTarikSemua"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Tarik Sekarang
            </button>
            <button type="button" id="tombolTambahRuangan"
                    class="px-4 py-2 text-white text-sm font-medium rounded-lg transition-colors"
                    style="background-color: #0E9E8E;">
                Tambah Ruangan
            </button>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6 flex flex-wrap gap-6 items-center">
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Ruangan terdaftar</p>
            <p class="text-2xl font-bold mt-1" id="ringkasTotal">{{ $daftarRuangan->count() }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Perangkat online</p>
            <p class="text-2xl font-bold mt-1" id="ringkasOnline">-</p>
        </div>
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Suhu di luar ambang</p>
            <p class="text-2xl font-bold mt-1" id="ringkasPeringatan">-</p>
        </div>
        <div class="ml-auto text-right">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Waktu server</p>
            <p class="text-sm font-medium text-slate-700 mt-1" id="waktuServer">-</p>
            <p class="text-xs text-slate-400 mt-1" id="statusPembaruan">Menunggu data pertama</p>
        </div>
    </div>

    <div id="peringatanGalat" class="hidden mb-6 rounded-xl border px-4 py-3 text-sm"
         style="background-color: #fdecec; border-color: #f6c9c9; color: #b91c1c;"></div>

    <div id="pesanSementara" class="hidden mb-6 rounded-xl border border-teal-200 px-4 py-3 text-sm"
         style="background-color: #e6f6f3; color: #0b7f72;"></div>

    <div id="daftarKartu" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-5 text-sm text-slate-500">
            Memuat data ruangan panel...
        </div>
    </div>

    <div id="panelGrafik" class="bg-white rounded-xl border border-slate-200 p-5 mb-6 hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">
                    Grafik {{ $titikGrafik }} pembacaan terakhir
                </p>
                <p class="text-lg font-semibold text-slate-900 mt-1" id="judulGrafik">-</p>
            </div>
            <select id="pilihRuanganGrafik"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500"></select>
        </div>
        <div style="position: relative; height: 320px;">
            <canvas id="grafikSuhu"></canvas>
        </div>
    </div>

    <div id="panelRiwayat" class="bg-white rounded-xl border border-slate-200 p-5 hidden">
        <p class="text-xs text-slate-400 uppercase tracking-wide font-medium mb-3">
            Riwayat {{ $barisRiwayat }} pembacaan terakhir
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-200">
                        <th class="py-2 pr-4">Waktu pengukuran</th>
                        <th class="py-2 pr-4">Suhu (C)</th>
                        <th class="py-2 pr-4">Kelembapan (%)</th>
                        <th class="py-2">Keterangan</th>
                    </tr>
                </thead>
                <tbody id="tabelRiwayat">
                    <tr><td colspan="4" class="py-3 text-slate-400">Belum ada data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="kosongTotal" class="hidden bg-white rounded-xl border border-slate-200 p-8 text-center">
        <p class="text-slate-700 font-medium">Belum ada ruangan panel yang terdaftar.</p>
        <p class="text-sm text-slate-500 mt-2 max-w-2xl mx-auto">
            Klik tombol Tambah Ruangan, isi nama ruangan dan alamat IP perangkat ESP32
            (bawaan firmware contoh: 192.168.41.240, port 80). Setelah disimpan, data akan
            mulai ditarik otomatis pada siklus berikutnya, atau langsung tekan Tarik Sekarang.
        </p>
    </div>

    {{-- Modal form tambah/ubah ruangan --}}
    <div id="modalRuangan" class="hidden fixed inset-0 z-50 overflow-y-auto" style="background-color: rgba(15, 23, 42, 0.5);">
        <div class="min-h-full flex items-start justify-center p-4">
            <div class="bg-white rounded-xl border border-slate-200 w-full max-w-2xl mt-10">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
                    <h2 class="text-base font-semibold text-slate-900" id="judulModalRuangan">Tambah Ruangan Panel</h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" data-tutup-modal-ruangan="1">&times;</button>
                </div>

                <form method="POST" action="{{ route('panel.ruangan.store') }}" id="formRuangan">
                    @csrf
                    <input type="hidden" name="_method" value="POST" id="metodeFormRuangan">

                    <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 mb-1">
                                Nama ruangan <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="nama_ruangan" id="isianNamaRuangan" required maxlength="100"
                                   placeholder="Contoh: Ruang Panel EPE 1"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">PT Location</label>
                            <input type="text" name="pt_location" id="isianPtLocation" maxlength="100"
                                   placeholder="Contoh: EPE"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Plant</label>
                            <input type="text" name="plant" id="isianPlant" maxlength="50"
                                   placeholder="Contoh: 6010"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">
                                Alamat IP perangkat <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="ip_address" id="isianIp" required
                                   placeholder="192.168.41.240"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">
                                Port HTTP perangkat <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="port" id="isianPort" required min="1" max="65535" value="80"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Alamat MAC (opsional)</label>
                            <input type="text" name="mac_address" id="isianMac" maxlength="17"
                                   placeholder="02:00:5A:41:00:01"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 mb-1">Cara baca perangkat</label>
                            <select name="protokol" id="isianProtokol"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                @foreach ($pilihanProtokol as $nilai => $label)
                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-400 mt-1">
                                Biarkan <strong>Otomatis</strong> untuk perangkat yang sudah berjalan: server mencoba JSON API firmware,
                                lalu JSON TCP port {{ config('panel.sketch.port_tcp') }}, lalu dashboard HTML. Pilih satu cara hanya bila
                                perangkat memang hanya menyediakan cara itu.
                            </p>
                        </div>


                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">
                                Ambang suhu bawah (C) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.1" name="ambang_suhu_bawah" id="isianAmbangBawah" required
                                   value="{{ $ambangBawaan['bawah'] }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">
                                Ambang suhu atas (C) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.1" name="ambang_suhu_atas" id="isianAmbangAtas" required
                                   value="{{ $ambangBawaan['atas'] }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 mb-1">Keterangan</label>
                            <textarea name="keterangan" id="isianKeterangan" rows="2" maxlength="255"
                                      class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="checkbox" name="aktif" id="isianAktif" value="1" checked class="rounded border-slate-300">
                            <label for="isianAktif" class="text-sm text-slate-700">Aktif (ikut ditarik data tiap menit)</label>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-200">
                        <button type="button" data-tutup-modal-ruangan="1"
                                class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            Batal
                        </button>
                        <button type="submit"
                                class="px-5 py-2 text-white text-sm font-medium rounded-lg transition-colors"
                                style="background-color: #0E9E8E;">
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal konfirmasi hapus ruangan --}}
    <div id="modalHapus" class="hidden fixed inset-0 z-50 overflow-y-auto" style="background-color: rgba(15, 23, 42, 0.5);">
        <div class="min-h-full flex items-start justify-center p-4">
            <div class="bg-white rounded-xl border border-slate-200 w-full max-w-md mt-24 p-5">
                <h2 class="text-base font-semibold text-slate-900">Hapus Ruangan Panel</h2>
                <p class="text-sm text-slate-600 mt-2" id="teksKonfirmasiHapus">
                    Riwayat pembacaan ruangan ini ikut terhapus dan tidak bisa dikembalikan.
                </p>
                <form method="POST" action="#" id="formHapus" class="mt-5 flex items-center justify-end gap-2">
                    @csrf
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="button" data-tutup-modal-hapus="1"
                            class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit"
                            class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal hasil uji koneksi perangkat --}}
    <div id="modalUji" class="hidden fixed inset-0 z-50 overflow-y-auto" style="background-color: rgba(15, 23, 42, 0.5);">
        <div class="min-h-full flex items-start justify-center p-4">
            <div class="bg-white rounded-xl border border-slate-200 w-full max-w-xl mt-20">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
                    <h2 class="text-base font-semibold text-slate-900">Hasil Uji Koneksi</h2>
                    <button type="button" class="text-slate-400 hover:text-slate-600" data-tutup-modal-uji="1">&times;</button>
                </div>
                <div class="p-5">
                    <p class="text-sm text-slate-800" id="pesanUji">Sedang memeriksa perangkat...</p>
                    <table class="w-full text-sm mt-4">
                        <tbody id="tabelUji">
                            <tr><td class="py-1 text-slate-400">Menunggu hasil...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end px-5 py-4 border-t border-slate-200">
                    <button type="button" data-tutup-modal-uji="1"
                            class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script>
    (function () {
        'use strict';

        // Alamat endpoint diambil dari Laravel supaya tidak ada URL yang ditulis manual.
        var URL_DATA = '{{ route('panel.data') }}';
        var URL_SIMPAN = '{{ route('panel.ruangan.store') }}';
        var URL_UBAH = '{{ route('panel.ruangan.update', ['panelRuangan' => '__ID__']) }}';
        var URL_HAPUS = '{{ route('panel.ruangan.destroy', ['panelRuangan' => '__ID__']) }}';
        var URL_UJI = '{{ route('panel.ruangan.uji-koneksi', ['panelRuangan' => '__ID__']) }}';
        var URL_TARIK = '{{ route('panel.ruangan.tarik', ['panelRuangan' => '__ID__']) }}';
        var TOKEN_CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var INTERVAL_MS = {{ $intervalPolling }} * 1000;

        var dataTerakhir = [];
        var idTerpilih = null;
        var grafik = null;

        var elDaftarKartu = document.getElementById('daftarKartu');
        var elRingkasTotal = document.getElementById('ringkasTotal');
        var elRingkasOnline = document.getElementById('ringkasOnline');
        var elRingkasPeringatan = document.getElementById('ringkasPeringatan');
        var elWaktuServer = document.getElementById('waktuServer');
        var elStatusPembaruan = document.getElementById('statusPembaruan');
        var elPeringatanGalat = document.getElementById('peringatanGalat');
        var elPesanSementara = document.getElementById('pesanSementara');
        var elKosongTotal = document.getElementById('kosongTotal');
        var elPanelGrafik = document.getElementById('panelGrafik');
        var elPanelRiwayat = document.getElementById('panelRiwayat');
        var elJudulGrafik = document.getElementById('judulGrafik');
        var elPilihRuangan = document.getElementById('pilihRuanganGrafik');
        var elTabelRiwayat = document.getElementById('tabelRiwayat');

        /**
         * Ubah angka menjadi teks dengan jumlah desimal tertentu.
         *
         * @param  {number|null} nilai
         * @param  {number} desimal
         * @return {string}
         */
        function formatAngka(nilai, desimal) {
            if (nilai === null || nilai === undefined) {
                return '-';
            }

            return Number(nilai).toFixed(desimal);
        }

        /**
         * Amankan teks dari data pengguna sebelum dimasukkan ke HTML.
         *
         * @param  {string|null} nilai
         * @return {string}
         */
        function keTeksAman(nilai) {
            return String(nilai === null || nilai === undefined ? '' : nilai)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        /**
         * Tampilkan pesan galat di bagian atas halaman.
         *
         * @param  {string} pesan
         * @return {void}
         */
        function tampilkanGalat(pesan) {
            elPeringatanGalat.textContent = pesan;
            elPeringatanGalat.classList.remove('hidden');
        }

        /**
         * Sembunyikan pesan galat.
         *
         * @return {void}
         */
        function sembunyikanGalat() {
            elPeringatanGalat.classList.add('hidden');
        }

        /**
         * Tampilkan pesan hasil aksi (berhasil atau gagal).
         *
         * @param  {string} pesan
         * @param  {boolean} berhasil
         * @return {void}
         */
        function tampilkanPesan(pesan, berhasil) {
            elPesanSementara.textContent = pesan;
            elPesanSementara.style.backgroundColor = berhasil ? '#e6f6f3' : '#fdecec';
            elPesanSementara.style.color = berhasil ? '#0b7f72' : '#b91c1c';
            elPesanSementara.classList.remove('hidden');
        }

        /**
         * Warna teks untuk tiap kondisi suhu.
         *
         * @param  {string} kondisi normal, tinggi, rendah, atau tanpa-data
         * @return {string}
         */
        function warnaKondisi(kondisi) {
            if (kondisi === 'tinggi') {
                return '#b91c1c';
            }
            if (kondisi === 'rendah') {
                return '#b45309';
            }
            if (kondisi === 'normal') {
                return '#0E9E8E';
            }

            return '#64748b';
        }

        /**
         * Susun HTML satu kartu ruangan panel.
         *
         * @param  {object} ruangan
         * @return {string}
         */
        function kartuRuangan(ruangan) {
            var warna = warnaKondisi(ruangan.kondisi);
            var pilOnline = ruangan.online
                ? '<span class="px-2 py-1 rounded-full text-xs font-semibold" style="background-color:#e6f6f3;color:#0b7f72;">ONLINE</span>'
                : '<span class="px-2 py-1 rounded-full text-xs font-semibold" style="background-color:#fdecec;color:#b91c1c;">OFFLINE</span>';
            var tombol = 'px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors';
            var catatan = ruangan.catatan
                ? '<p class="text-xs mt-1" style="color:#b45309;">' + keTeksAman(ruangan.catatan) + '</p>'
                : '';

            return ''
                + '<div class="bg-white rounded-xl border border-slate-200 p-5">'
                + '  <div class="flex items-start justify-between gap-3">'
                + '    <div>'
                + '      <p class="font-semibold text-slate-900">' + keTeksAman(ruangan.nama_ruangan) + '</p>'
                + '      <p class="text-xs text-slate-500 mt-0.5">' + keTeksAman(ruangan.lokasi) + '</p>'
                + '      <p class="text-xs text-slate-400 mt-0.5">IP ' + keTeksAman(ruangan.ip_address) + ':' + keTeksAman(ruangan.port) + '</p>'
                + '      <p class="text-xs text-slate-400 mt-0.5">Protokol: ' + keTeksAman(ruangan.protokol_label || '-') + '</p>'
                + '    </div>'
                + pilOnline
                + '  </div>'
                + '  <div class="mt-4 flex items-end gap-5">'
                + '    <div>'
                + '      <p class="text-xs text-slate-400 uppercase tracking-wide">Suhu</p>'
                + '      <p class="text-3xl font-bold mt-1" style="color:' + warna + ';">'
                +          formatAngka(ruangan.suhu, 1)
                +          + '<span class="text-sm font-semibold text-slate-400"> C</span>'
                + '      </p>'
                + '    </div>'
                + '    <div>'
                + '      <p class="text-xs text-slate-400 uppercase tracking-wide">Kelembapan</p>'
                + '      <p class="text-xl font-semibold text-slate-700 mt-1">' + formatAngka(ruangan.kelembapan, 1) + ' %</p>'
                + '    </div>'
                + '  </div>'
                + '  <p class="text-xs mt-3" style="color:' + warna + ';">'
                +      'Kondisi: ' + keTeksAman(ruangan.kondisi).toUpperCase()
                +      ' (ambang ' + formatAngka(ruangan.ambang_suhu_bawah, 0) + ' s/d ' + formatAngka(ruangan.ambang_suhu_atas, 0) + ' C)'
                + '  </p>'
                + '  <p class="text-xs text-slate-500 mt-1">Pembacaan: ' + keTeksAman(ruangan.waktu_pembacaan || 'belum ada data') + '</p>'
                + '  <p class="text-xs text-slate-400 mt-1">Ditarik terakhir: ' + keTeksAman(ruangan.terakhir_ditarik || '-') + '</p>'
                + catatan
                + '  <div class="mt-4 flex flex-wrap gap-2">'
                + '    <button type="button" data-aksi="uji" data-id="' + ruangan.id + '" class="' + tombol + '">Uji Koneksi</button>'
                + '    <button type="button" data-aksi="tarik" data-id="' + ruangan.id + '" class="' + tombol + '">Tarik</button>'
                + '    <button type="button" data-aksi="grafik" data-id="' + ruangan.id + '" class="' + tombol + '">Grafik</button>'
                + '    <button type="button" data-aksi="ubah" data-id="' + ruangan.id + '" class="' + tombol + '">Edit</button>'
                + '    <button type="button" data-aksi="hapus" data-id="' + ruangan.id + '" class="' + tombol + '" style="color:#b91c1c;">Hapus</button>'
                + '  </div>'
                + '</div>';
        }

        /**
         * Ambil data terbaru seluruh ruangan dari server.
         *
         * @return {void}
         */
        function ambilData() {
            fetch(URL_DATA, { headers: { 'Accept': 'application/json' } })
                .then(function (balasan) {
                    if (!balasan.ok) {
                        throw new Error('HTTP ' + balasan.status);
                    }
                    return balasan.json();
                })
                .then(function (hasil) {
                    sembunyikanGalat();
                    dataTerakhir = hasil.ruangan || [];
                    if (dataTerakhir.length > 0 && idTerpilih === null) {
                        idTerpilih = dataTerakhir[0].id;
                    }
                    renderSemua(hasil.waktu_server);
                })
                .catch(function (galat) {
                    tampilkanGalat('Gagal mengambil data dari server (' + galat.message
                        + '). Halaman akan mencoba lagi otomatis.');
                });
        }

        /**
         * Cari objek ruangan pada data terakhir berdasarkan id-nya.
         *
         * @param  {number} id
         * @return {object|null}
         */
        function cariRuangan(id) {
            for (var i = 0; i < dataTerakhir.length; i++) {
                if (String(dataTerakhir[i].id) === String(id)) {
                    return dataTerakhir[i];
                }
            }

            return null;
        }

        /**
         * Perbarui seluruh tampilan halaman dari data terakhir yang diterima.
         *
         * @param  {string} waktuServer
         * @return {void}
         */
        function renderSemua(waktuServer) {
            var total = dataTerakhir.length;
            var online = 0;
            var peringatan = 0;

            for (var i = 0; i < total; i++) {
                if (dataTerakhir[i].online) {
                    online++;
                }
                if (dataTerakhir[i].kondisi === 'tinggi' || dataTerakhir[i].kondisi === 'rendah') {
                    peringatan++;
                }
            }

            elRingkasTotal.textContent = total;
            elRingkasOnline.textContent = online + ' / ' + total;
            elRingkasPeringatan.textContent = peringatan;
            elWaktuServer.textContent = waktuServer || '-';
            elStatusPembaruan.textContent = 'Diperbarui ' + new Date().toLocaleTimeString('id-ID');

            if (total === 0) {
                elDaftarKartu.classList.add('hidden');
                elKosongTotal.classList.remove('hidden');
                elPanelGrafik.classList.add('hidden');
                elPanelRiwayat.classList.add('hidden');
                return;
            }

            elKosongTotal.classList.add('hidden');
            elDaftarKartu.classList.remove('hidden');

            var html = '';
            for (var j = 0; j < total; j++) {
                html += kartuRuangan(dataTerakhir[j]);
            }
            elDaftarKartu.innerHTML = html;

            perbaruiPilihanRuangan();
            renderGrafikDanRiwayat();
        }

        /**
         * Perbarui daftar pilihan ruangan pada bagian grafik.
         *
         * @return {void}
         */
        function perbaruiPilihanRuangan() {
            var opsi = '';
            var ketemu = false;

            for (var i = 0; i < dataTerakhir.length; i++) {
                var ruangan = dataTerakhir[i];
                var terpilih = String(ruangan.id) === String(idTerpilih);

                if (terpilih) {
                    ketemu = true;
                }

                opsi += '<option value="' + ruangan.id + '"' + (terpilih ? ' selected' : '') + '>'
                    + keTeksAman(ruangan.nama_ruangan) + '</option>';
            }

            // Bila ruangan yang dipilih sudah tidak ada, kembali ke ruangan pertama.
            if (!ketemu && dataTerakhir.length > 0) {
                idTerpilih = dataTerakhir[0].id;
                opsi = opsi.replace('value="' + idTerpilih + '"', 'value="' + idTerpilih + '" selected');
            }

            elPilihRuangan.innerHTML = opsi;
        }

        /**
         * Perbarui grafik dan tabel riwayat untuk ruangan yang sedang dipilih.
         *
         * @return {void}
         */
        function renderGrafikDanRiwayat() {
            var ruangan = cariRuangan(idTerpilih);

            if (ruangan === null) {
                elPanelGrafik.classList.add('hidden');
                elPanelRiwayat.classList.add('hidden');
                return;
            }

            elPanelGrafik.classList.remove('hidden');
            elPanelRiwayat.classList.remove('hidden');
            elJudulGrafik.textContent = ruangan.nama_ruangan + ' - ' + ruangan.lokasi;

            if (typeof window.Chart !== 'function') {
                elPanelGrafik.classList.add('hidden');
            } else {
                if (grafik === null) {
                    siapkanGrafik();
                }
                perbaruiGrafik(ruangan.grafik || []);
            }

            var baris = '';
            var riwayat = ruangan.riwayat || [];

            if (riwayat.length === 0) {
                baris = '<tr><td colspan="4" class="py-3 text-slate-400">Belum ada pembacaan yang tersimpan.</td></tr>';
            } else {
                for (var i = 0; i < riwayat.length; i++) {
                    baris += '<tr class="border-b border-slate-100">'
                        + '<td class="py-2 pr-4 text-slate-700">' + keTeksAman(riwayat[i].waktu) + '</td>'
                        + '<td class="py-2 pr-4 text-slate-700">' + formatAngka(riwayat[i].suhu, 1) + '</td>'
                        + '<td class="py-2 pr-4 text-slate-700">' + formatAngka(riwayat[i].kelembapan, 1) + '</td>'
                        + '<td class="py-2 text-slate-500">' + (riwayat[i].valid ? 'terbaca' : 'di luar rentang sensor') + '</td>'
                        + '</tr>';
                }
            }

            elTabelRiwayat.innerHTML = baris;
        }

        /**
         * Siapkan objek grafik Chart.js untuk suhu dan kelembapan.
         *
         * @return {void}
         */
        function siapkanGrafik() {
            var kanvas = document.getElementById('grafikSuhu');

            grafik = new window.Chart(kanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Suhu (C)',
                            data: [],
                            borderColor: '#e07a5f',
                            backgroundColor: 'rgba(224, 122, 95, 0.12)',
                            borderWidth: 2,
                            pointRadius: 0,
                            tension: 0.3,
                            fill: true,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Kelembapan (%)',
                            data: [],
                            borderColor: '#0E9E8E',
                            backgroundColor: 'rgba(14, 158, 142, 0.12)',
                            borderWidth: 2,
                            pointRadius: 0,
                            tension: 0.3,
                            fill: true,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom' } },
                    scales: {
                        x: { ticks: { maxTicksLimit: 8, autoSkip: true } },
                        y: {
                            position: 'left',
                            title: { display: true, text: 'Suhu (C)' },
                            grid: { color: '#eef2f7' }
                        },
                        y1: {
                            position: 'right',
                            title: { display: true, text: 'Kelembapan (%)' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }

        /**
         * Perbarui isi grafik dengan titik data satu ruangan.
         *
         * @param  {Array} titik
         * @return {void}
         */
        function perbaruiGrafik(titik) {
            if (grafik === null) {
                return;
            }

            var label = [];
            var suhu = [];
            var kelembapan = [];

            for (var i = 0; i < titik.length; i++) {
                label.push(titik[i].waktu);
                suhu.push(titik[i].suhu);
                kelembapan.push(titik[i].kelembapan);
            }

            grafik.data.labels = label;
            grafik.data.datasets[0].data = suhu;
            grafik.data.datasets[1].data = kelembapan;
            grafik.update('none');
        }

        /**
         * Kirim permintaan POST ke server beserta token CSRF.
         *
         * @param  {string} url
         * @return {Promise<object>}
         */
        function kirimAksi(url) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': TOKEN_CSRF,
                    'Accept': 'application/json'
                }
            }).then(function (balasan) {
                if (!balasan.ok) {
                    throw new Error('HTTP ' + balasan.status);
                }

                return balasan.json();
            });
        }

        /**
         * Buka modal form ruangan dalam mode tambah atau ubah.
         *
         * @param  {object|null} ruangan  null berarti mode tambah.
         * @return {void}
         */
        function bukaModalRuangan(ruangan) {
            var form = document.getElementById('formRuangan');
            var metode = document.getElementById('metodeFormRuangan');

            if (ruangan === null) {
                document.getElementById('judulModalRuangan').textContent = 'Tambah Ruangan Panel';
                form.action = URL_SIMPAN;
                metode.value = 'POST';
                form.reset();
            } else {
                document.getElementById('judulModalRuangan').textContent = 'Ubah Ruangan: ' + ruangan.nama_ruangan;
                form.action = URL_UBAH.replace('__ID__', ruangan.id);
                metode.value = 'PUT';
                document.getElementById('isianNamaRuangan').value = ruangan.nama_ruangan || '';
                document.getElementById('isianPtLocation').value = ruangan.pt_location || '';
                document.getElementById('isianPlant').value = ruangan.plant || '';
                document.getElementById('isianIp').value = ruangan.ip_address || '';
                document.getElementById('isianPort').value = ruangan.port || 80;
                document.getElementById('isianMac').value = ruangan.mac_address || '';
                document.getElementById('isianProtokol').value = ruangan.protokol || 'otomatis';
                document.getElementById('isianAmbangBawah').value = ruangan.ambang_suhu_bawah;
                document.getElementById('isianAmbangAtas').value = ruangan.ambang_suhu_atas;
                document.getElementById('isianKeterangan').value = ruangan.keterangan || '';
                document.getElementById('isianAktif').checked = !!ruangan.aktif;
            }

            document.getElementById('modalRuangan').classList.remove('hidden');
        }

        /**
         * Tutup modal form ruangan.
         *
         * @return {void}
         */
        function tutupModalRuangan() {
            document.getElementById('modalRuangan').classList.add('hidden');
        }

        /**
         * Buka modal konfirmasi hapus ruangan.
         *
         * @param  {object} ruangan
         * @return {void}
         */
        function bukaModalHapus(ruangan) {
            document.getElementById('teksKonfirmasiHapus').textContent =
                'Ruangan "' + ruangan.nama_ruangan + '" beserta seluruh riwayat pembacaannya '
                + 'akan dihapus dan tidak bisa dikembalikan.';
            document.getElementById('formHapus').action = URL_HAPUS.replace('__ID__', ruangan.id);
            document.getElementById('modalHapus').classList.remove('hidden');
        }

        /**
         * Tutup modal konfirmasi hapus.
         *
         * @return {void}
         */
        function tutupModalHapus() {
            document.getElementById('modalHapus').classList.add('hidden');
        }

        /**
         * Susun satu baris tabel dua kolom untuk modal uji koneksi.
         *
         * @param  {string} label
         * @param  {string} nilai
         * @return {string}
         */
        function barisTabel(label, nilai) {
            return '<tr>'
                + '<td class="py-1 pr-4 text-slate-500 align-top">' + keTeksAman(label) + '</td>'
                + '<td class="py-1 text-slate-800">' + keTeksAman(nilai) + '</td>'
                + '</tr>';
        }

        /**
         * Susun seluruh baris hasil uji koneksi perangkat.
         *
         * @param  {object} hasil  Balasan endpoint uji koneksi.
         * @return {string}
         */
        function barisHasilUji(hasil) {
            var perangkat = hasil.perangkat || {};
            var pembacaan = hasil.pembacaan || null;
            var ping = hasil.ping || {};
            var latensi = (ping.latency_ms === null || ping.latency_ms === undefined)
                ? ''
                : ' (' + ping.latency_ms + ' ms)';
            var uptime = (perangkat.uptime_s === undefined || perangkat.uptime_s === null)
                ? '-'
                : perangkat.uptime_s + ' detik';
            var statistikKirim = (perangkat.jumlah_kirim_sukses === undefined)
                ? '-'
                : perangkat.jumlah_kirim_sukses + ' sukses / ' + perangkat.jumlah_kirim_gagal + ' gagal';
            var nilaiPembacaan = pembacaan === null
                ? 'belum ada'
                : ((pembacaan.sensor_membaca === false) ? 'belum ada (sensor belum membaca)' : pembacaan.suhu + ' C / ' + pembacaan.kelembapan + ' %');

            return ''
                + barisTabel('Ping jaringan', (ping.status || '-') + latensi)
                + barisTabel('Modul W5500', perangkat.w5500_terdeteksi ? 'terdeteksi' : 'tidak terdeteksi')
                + barisTabel('Link kabel LAN', perangkat.link ? 'aktif' : 'mati')
                + barisTabel('Firmware', perangkat.firmware || '-')
                + barisTabel('Uptime perangkat', uptime)
                + barisTabel('Pembacaan terakhir', nilaiPembacaan)
                + barisTabel('Statistik pengiriman', statistikKirim)
                + barisTabel('Sesi boot', perangkat.boot || '-')
                + barisTabel('Sumber jawaban', hasil.sumber || '-');
        }

        /**
         * Buka modal hasil uji koneksi dan langsung memeriksa perangkat.
         *
         * @param  {number} id
         * @return {void}
         */
        function bukaModalUji(id) {
            document.getElementById('pesanUji').textContent = 'Sedang memeriksa perangkat...';
            document.getElementById('tabelUji').innerHTML =
                '<tr><td class="py-1 text-slate-400">Menunggu hasil pemeriksaan.</td></tr>';
            document.getElementById('modalUji').classList.remove('hidden');

            kirimAksi(URL_UJI.replace('__ID__', id))
                .then(function (hasil) {
                    document.getElementById('pesanUji').textContent = hasil.pesan || 'Pemeriksaan selesai.';
                    document.getElementById('tabelUji').innerHTML = barisHasilUji(hasil);
                })
                .catch(function (galat) {
                    document.getElementById('pesanUji').textContent =
                        'Gagal menguji koneksi: ' + galat.message
                        + '. Pastikan halaman masih terhubung ke server.';
                });
        }

        /**
         * Tutup modal hasil uji koneksi.
         *
         * @return {void}
         */
        function tutupModalUji() {
            document.getElementById('modalUji').classList.add('hidden');
        }

        /**
         * Tarik data satu ruangan sekarang juga.
         *
         * @param  {number} id
         * @return {void}
         */
        function tarikSatu(id) {
            tampilkanPesan('Menarik data dari perangkat...', true);

            kirimAksi(URL_TARIK.replace('__ID__', id))
                .then(function (hasil) {
                    tampilkanPesan(hasil.pesan, hasil.status === 'ok');
                    ambilData();
                })
                .catch(function (galat) {
                    tampilkanPesan('Gagal menarik data: ' + galat.message, false);
                });
        }

        /**
         * Tarik data seluruh ruangan aktif secara berurutan.
         *
         * @return {void}
         */
        function tarikSemua() {
            if (dataTerakhir.length === 0) {
                tampilkanPesan('Belum ada ruangan panel yang terdaftar.', false);
                return;
            }

            tampilkanPesan('Menarik data dari ' + dataTerakhir.length + ' ruangan...', true);

            var antrean = Promise.resolve();
            var baru = 0;
            var gagal = 0;

            dataTerakhir.forEach(function (ruangan) {
                antrean = antrean.then(function () {
                    return kirimAksi(URL_TARIK.replace('__ID__', ruangan.id))
                        .then(function (hasil) {
                            baru += hasil.baru || 0;
                            if (hasil.status !== 'ok') {
                                gagal++;
                            }
                        })
                        .catch(function () {
                            gagal++;
                        });
                });
            });

            antrean.then(function () {
                var pesan = 'Penarikan selesai: ' + baru + ' pembacaan baru, ' + gagal + ' ruangan gagal dihubungi.';
                tampilkanPesan(pesan, gagal === 0);
                ambilData();
            });
        }

        /**
         * Tangani klik tombol aksi di dalam kartu ruangan.
         *
         * @param  {MouseEvent} peristiwa
         * @return {void}
         */
        function tanganiKlikKartu(peristiwa) {
            var tombol = peristiwa.target.closest('button[data-aksi]');

            if (tombol === null) {
                return;
            }

            var aksi = tombol.getAttribute('data-aksi');
            var ruangan = cariRuangan(tombol.getAttribute('data-id'));

            if (ruangan === null) {
                return;
            }

            if (aksi === 'uji') {
                bukaModalUji(ruangan.id);
                return;
            }

            if (aksi === 'tarik') {
                tarikSatu(ruangan.id);
                return;
            }

            if (aksi === 'ubah') {
                bukaModalRuangan(ruangan);
                return;
            }

            if (aksi === 'hapus') {
                bukaModalHapus(ruangan);
                return;
            }

            if (aksi === 'grafik') {
                idTerpilih = ruangan.id;
                perbaruiPilihanRuangan();
                renderGrafikDanRiwayat();
                elPanelGrafik.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        /**
         * Pasang seluruh penanganan kejadian pada halaman.
         *
         * @return {void}
         */
        function pasangKejadian() {
            elDaftarKartu.addEventListener('click', tanganiKlikKartu);

            document.getElementById('tombolTambahRuangan').addEventListener('click', function () {
                bukaModalRuangan(null);
            });

            document.getElementById('tombolTarikSemua').addEventListener('click', tarikSemua);

            elPilihRuangan.addEventListener('change', function () {
                idTerpilih = this.value;
                renderGrafikDanRiwayat();
            });

            Array.prototype.forEach.call(document.querySelectorAll('[data-tutup-modal-ruangan]'), function (tombol) {
                tombol.addEventListener('click', tutupModalRuangan);
            });

            Array.prototype.forEach.call(document.querySelectorAll('[data-tutup-modal-hapus]'), function (tombol) {
                tombol.addEventListener('click', tutupModalHapus);
            });

            Array.prototype.forEach.call(document.querySelectorAll('[data-tutup-modal-uji]'), function (tombol) {
                tombol.addEventListener('click', tutupModalUji);
            });

            // Klik di luar kotak modal menutup modal yang bersangkutan.
            ['modalRuangan', 'modalHapus', 'modalUji'].forEach(function (idModal) {
                document.getElementById(idModal).addEventListener('click', function (peristiwa) {
                    if (peristiwa.target !== this) {
                        return;
                    }

                    if (idModal === 'modalRuangan') {
                        tutupModalRuangan();
                    } else if (idModal === 'modalHapus') {
                        tutupModalHapus();
                    } else {
                        tutupModalUji();
                    }
                });
            });

            document.addEventListener('keydown', function (peristiwa) {
                if (peristiwa.key === 'Escape') {
                    tutupModalRuangan();
                    tutupModalHapus();
                    tutupModalUji();
                }
            });
        }

        pasangKejadian();
        ambilData();
        setInterval(ambilData, INTERVAL_MS);
    })();
</script>
@endpush
