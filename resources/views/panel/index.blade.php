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
            <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                Ringkasan suhu dan kelembapan tiap ruangan panel, diperbarui otomatis tiap
                {{ $intervalPolling }} detik. Tiap kartu memuat kurva singkat
                {{ $titikGrafik }} pembacaan terakhir sebagai gambaran cepat, sedangkan grafik
                lengkap, riwayat harian, dan ekspor Excel ada di halaman detail ruangan.
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

    <div id="kosongTotal" class="hidden bg-white rounded-xl border border-slate-200 p-8 text-center">
        <p class="text-slate-700 font-medium">Belum ada ruangan panel yang terdaftar.</p>
        <p class="text-sm text-slate-500 mt-2 max-w-2xl mx-auto">
            Klik tombol Tambah Ruangan untuk mencatat ruangan cepat, lalu lengkapi pengaturan
            (lokasi, kode alat, dan penanggung jawab) dari halaman detailnya. Setelah disimpan,
            data mulai ditarik otomatis pada siklus berikutnya, atau langsung tekan Tarik Sekarang.
        </p>
    </div>

    {{-- Modal tambah cepat ruangan panel. Pengaturan lengkap (lokasi, kode alat,
         sumber data perangkat, penanggung jawab) dikelola di halaman tersendiri
         supaya tidak ada dua tempat penyuntingan data yang sama. --}}
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

    <p class="text-xs text-slate-400 mb-6">
        Catatan: pengaturan lengkap tiap ruangan (lokasi, kode alat, dan penanggung jawab panel)
        dikelola lewat tombol Detail pada kartu ruangan.
    </p>

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
        var URL_DETAIL = '{{ url('panel/ruangan') }}/';
        var TOKEN_CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var INTERVAL_MS = {{ $intervalPolling }} * 1000;

        var dataTerakhir = [];

        // Objek kurva singkat tiap kartu. Kartu digambar ulang pada setiap
        // polling, jadi kurva lama wajib dibuang lebih dulu supaya tidak
        // menumpuk di memori browser.
        var kurvaKartu = {};

        var elDaftarKartu = document.getElementById('daftarKartu');
        var elRingkasTotal = document.getElementById('ringkasTotal');
        var elRingkasOnline = document.getElementById('ringkasOnline');
        var elRingkasPeringatan = document.getElementById('ringkasPeringatan');
        var elWaktuServer = document.getElementById('waktuServer');
        var elStatusPembaruan = document.getElementById('statusPembaruan');
        var elPeringatanGalat = document.getElementById('peringatanGalat');
        var elPesanSementara = document.getElementById('pesanSementara');
        var elKosongTotal = document.getElementById('kosongTotal');

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
         * Warna garis kurva singkat untuk tiap kondisi suhu.
         *
         * @param  {string} kondisi
         * @return {string}
         */
        function warnaKurva(kondisi) {
            if (kondisi === 'tinggi') {
                return '#dc2626';
            }
            if (kondisi === 'rendah') {
                return '#d97706';
            }
            if (kondisi === 'normal') {
                return '#0E9E8E';
            }

            return '#94a3b8';
        }

        /**
         * Lencana indikator suhu: terlalu panas, terlalu dingin, normal, atau
         * belum ada data. Ambang batasnya diatur per ruangan pada halaman
         * pengaturan ruangan.
         *
         * @param  {object} ruangan
         * @return {string}
         */
        function lencanaKondisi(ruangan) {
            var gaya = 'display:inline-block;margin-top:12px;padding:4px 12px;'
                + 'border-radius:9999px;font-size:11px;font-weight:600;letter-spacing:.02em;';

            if (ruangan.kondisi === 'tinggi') {
                return '<span style="' + gaya + 'background-color:#fdecec;color:#b91c1c;">TERLALU PANAS</span>';
            }
            if (ruangan.kondisi === 'rendah') {
                return '<span style="' + gaya + 'background-color:#fef6e7;color:#b45309;">TERLALU DINGIN</span>';
            }
            if (ruangan.kondisi === 'normal') {
                return '<span style="' + gaya + 'background-color:#e6f6f3;color:#0b7f72;">SUHU NORMAL</span>';
            }

            return '<span style="' + gaya + 'background-color:#f1f5f9;color:#475569;">BELUM ADA DATA</span>';
        }


        /**
         * Susun HTML satu kartu ruangan panel: nilai terkini, lencana kondisi,
         * kurva singkat, penanggung jawab, dan tombol aksi.
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
            var kanvasKurva = '<div style="width:132px;height:60px;"><canvas id="kurva-'
                + ruangan.id + '"></canvas></div>';

            return ''
                + '<div class="bg-white rounded-xl border border-slate-200 p-5">'
                + '  <div class="flex items-start justify-between gap-3">'
                + '    <div>'
                + '      <a href="' + URL_DETAIL + ruangan.id + '" class="font-semibold text-slate-900 hover:underline">' + keTeksAman(ruangan.nama_ruangan) + '</a>'
                + '      <p class="text-xs text-slate-500 mt-0.5">' + keTeksAman(ruangan.lokasi) + '</p>'
                + '      <p class="text-xs text-slate-400 mt-0.5">IP ' + keTeksAman(ruangan.ip_address) + ':' + keTeksAman(ruangan.port) + '</p>'
                + '    </div>'
                + pilOnline
                + '  </div>'
                + '  <div class="mt-4 flex items-end justify-between gap-4">'
                + '    <div>'
                + '      <p class="text-xs text-slate-400 uppercase tracking-wide">Suhu</p>'
                + '      <p class="text-3xl font-bold mt-1" style="color:' + warna + ';">'
                +          formatAngka(ruangan.suhu, 1)
                +          + '<span class="text-sm font-semibold text-slate-400"> C</span>'
                + '      </p>'
                + '      <p class="text-xs text-slate-400 uppercase tracking-wide mt-2">Kelembapan</p>'
                + '      <p class="text-lg font-semibold text-slate-700">' + formatAngka(ruangan.kelembapan, 1) + ' %</p>'
                + '    </div>'
                + kanvasKurva
                + '  </div>'
                + lencanaKondisi(ruangan)
                + '  <p class="text-[11px] text-slate-400 mt-1">'
                +      'Ambang ' + formatAngka(ruangan.ambang_suhu_bawah, 0) + ' s/d ' + formatAngka(ruangan.ambang_suhu_atas, 0) + ' C'
                + '  </p>'
                + '  <p class="text-xs text-slate-500 mt-2">Penanggung jawab: ' + keTeksAman(ruangan.penanggung_jawab || 'belum ditentukan') + '</p>'
                + '  <p class="text-xs text-slate-500 mt-1">Pembacaan: ' + keTeksAman(ruangan.waktu_pembacaan || 'belum ada data') + '</p>'
                + '  <p class="text-xs text-slate-400 mt-1">Ditarik terakhir: ' + keTeksAman(ruangan.terakhir_ditarik || '-') + '</p>'
                + catatan
                + '  <div class="mt-4 flex flex-wrap gap-2">'
                + '    <a href="' + URL_DETAIL + ruangan.id + '" class="' + tombol + '">Detail</a>'
                + '    <button type="button" data-aksi="uji" data-id="' + ruangan.id + '" class="' + tombol + '">Uji Koneksi</button>'
                + '    <button type="button" data-aksi="tarik" data-id="' + ruangan.id + '" class="' + tombol + '">Tarik</button>'
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
                return;
            }

            elKosongTotal.classList.add('hidden');
            elDaftarKartu.classList.remove('hidden');

            // Kartu digambar ulang, jadi kanvas kurva lama tidak lagi terpakai:
            // buang objek grafiknya dulu supaya tidak menumpuk di memori.
            bersihkanKurva();

            var html = '';
            for (var j = 0; j < total; j++) {
                html += kartuRuangan(dataTerakhir[j]);
            }
            elDaftarKartu.innerHTML = html;

            gambarKurvaSemua();
        }


        /**
         * Buang seluruh objek kurva singkat yang sudah ada.
         *
         * @return {void}
         */
        function bersihkanKurva() {
            Object.keys(kurvaKartu).forEach(function (kunci) {
                if (kurvaKartu[kunci] && typeof kurvaKartu[kunci].destroy === 'function') {
                    kurvaKartu[kunci].destroy();
                }
            });

            kurvaKartu = {};
        }

        /**
         * Gambar kurva singkat untuk seluruh kartu yang sedang tampil.
         *
         * @return {void}
         */
        function gambarKurvaSemua() {
            if (typeof window.Chart !== 'function') {
                return;
            }

            for (var i = 0; i < dataTerakhir.length; i++) {
                gambarKurvaKartu(dataTerakhir[i]);
            }
        }

        /**
         * Gambar kurva suhu singkat satu ruangan: tanpa sumbu, label, maupun
         * legenda, hanya garis tren sebagai gambaran cepat.
         *
         * @param  {object} ruangan
         * @return {void}
         */
        function gambarKurvaKartu(ruangan) {
            var kanvas = document.getElementById('kurva-' + ruangan.id);

            if (kanvas === null) {
                return;
            }

            var titik = ruangan.grafik || [];
            var nilai = [];
            var label = [];

            for (var i = 0; i < titik.length; i++) {
                nilai.push(titik[i].suhu);
                label.push('');
            }

            if (nilai.length === 0) {
                return;
            }

            kurvaKartu[ruangan.id] = new window.Chart(kanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: label,
                    datasets: [{
                        data: nilai,
                        borderColor: warnaKurva(ruangan.kondisi),
                        backgroundColor: 'rgba(14, 158, 142, 0.10)',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    },
                    scales: {
                        x: { display: false },
                        y: { display: false }
                    }
                }
            });
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
         * Buka modal tambah cepat ruangan dalam mode tambah atau ubah.
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
         * Tutup modal tambah cepat ruangan.
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
                // Pengaturan lengkap ruangan (lokasi, kode alat, sumber data
                // perangkat, penanggung jawab) dikelola di halaman tersendiri
                // supaya tidak ada dua tempat penyuntingan data yang sama.
                window.location.href = URL_DETAIL + ruangan.id + '/edit';
                return;
            }

            if (aksi === 'hapus') {
                bukaModalHapus(ruangan);
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

