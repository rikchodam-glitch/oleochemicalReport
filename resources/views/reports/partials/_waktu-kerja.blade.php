{{-- Field jam kerja laporan (jam mulai & jam selesai) sekaligus TAMPILAN hasil
     durasi pengerjaan. Dipisah jadi partial supaya halaman blade yang besar
     tidak perlu ditulis ulang (lihat aturan edit blade di AGENTS.md).

     Durasi TIDAK lagi diisi manual: nilainya diturunkan dari rentang jam.
     Kolom "work_duration_minutes" tetap ada di halaman edit sebagai input
     tersembunyi supaya angka man hour tetap terkirim saat laporan disimpan;
     skrip di bawah ini yang mengisinya setiap kali jam diubah.

     Parameter: $report = model Report yang sedang diedit. --}}
<div class="border-t border-slate-100 pt-4">
    <h3 class="text-sm font-medium text-slate-900 mb-3">Waktu Pengerjaan</h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="start_time" class="block text-sm font-medium text-slate-700 mb-1">Jam Mulai</label>
            <input type="time" id="start_time" name="start_time" step="60"
                   value="{{ old('start_time', $report->start_time) }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            @error('start_time')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="end_time" class="block text-sm font-medium text-slate-700 mb-1">Jam Selesai</label>
            <input type="time" id="end_time" name="end_time" step="60"
                   value="{{ old('end_time', $report->end_time) }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            @error('end_time')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" id="kosongkan_jam"
                class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 text-xs font-medium rounded-lg transition-colors">
            Kosongkan Jam
        </button>
    </div>

    @php
        // Teks durasi awal (dipakai sebelum JavaScript jalan) memakai accessor
        // model supaya formatnya sama dengan yang dihitung JavaScript.
        $durasiTersimpan = $report->work_duration_minutes;
        $durasiAwalTeks  = $durasiTersimpan === null
            ? '-'
            : ($report->duration_label ?? '0 Menit') . ' (' . $durasiTersimpan . ' menit)';
    @endphp

    <div class="mt-3 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
        <p class="text-sm text-slate-700">
            Durasi laporan:
            <span id="durasi_teks" class="font-medium text-slate-900">{{ $durasiAwalTeks }}</span>
        </p>
        <p id="durasi_catatan" class="text-xs text-slate-400 mt-0.5">
            Dihitung otomatis dari jam mulai dan jam selesai, jadi tidak ada isian durasi manual.
        </p>
    </div>

    <p id="peringatan_lintas_malam" class="hidden text-xs text-amber-600 mt-2">
        Jam selesai lebih kecil dari jam mulai: dihitung sebagai pekerjaan yang melewati tengah malam (ditambah 24 jam).
    </p>
</div>
@push('scripts')
<script>
    // Tampilan durasi pengerjaan dari rentang jam.
    //
    // Halaman edit tidak lagi menyediakan isian durasi manual. Kolom
    // "work_duration_minutes" tetap ada (input tersembunyi) supaya nilainya ikut
    // terkirim saat laporan disimpan, dan skrip inilah yang mengisinya:
    //   - Saat jam mulai/jam selesai DIUBAH admin, durasi mengikuti hitungan jam.
    //   - Tampilan awal tidak menimpa durasi tersimpan; bila laporan belum punya
    //     durasi sama sekali, nilai dari jam langsung dipakai.
    //   - Jam selesai lebih kecil dari jam mulai dihitung lintas tengah malam
    //     (ditambah 24 jam) dan memunculkan peringatan.
    (function () {
        var mulai      = document.getElementById('start_time');
        var selesai    = document.getElementById('end_time');
        var durasi     = document.getElementById('work_duration_minutes');
        var teks       = document.getElementById('durasi_teks');
        var catatan    = document.getElementById('durasi_catatan');
        var peringatan = document.getElementById('peringatan_lintas_malam');
        var kosongkan  = document.getElementById('kosongkan_jam');

        if (!mulai || !selesai || !teks) {
            return;
        }

        // Ubah "HH:MM" menjadi menit sejak tengah malam; null bila belum lengkap.
        function menitDariJam(nilai) {
            var cocok = /^(\d{1,2}):(\d{2})$/.exec(nilai || '');

            if (!cocok) {
                return null;
            }

            return (parseInt(cocok[1], 10) * 60) + parseInt(cocok[2], 10);
        }

        // Durasi yang saat ini ada di kolom tersembunyi (null bila kosong).
        function nilaiTersimpan() {
            if (!durasi) {
                return null;
            }

            var angka = parseInt(durasi.value, 10);

            return isNaN(angka) ? null : angka;
        }

        // Format durasi mengikuti accessor Report::getDurationLabelAttribute
        // ("2 Jam 30 Menit") ditambah jumlah menitnya.
        function teksDurasi(menit) {
            if (menit === null || isNaN(menit)) {
                return '-';
            }

            var jam   = Math.floor(menit / 60);
            var sisa  = menit % 60;
            var label = [];

            if (jam > 0) {
                label.push(jam + ' Jam');
            }

            if (sisa > 0) {
                label.push(sisa + ' Menit');
            }

            if (!label.length) {
                label.push('0 Menit');
            }

            return label.join(' ') + ' (' + menit + ' menit)';
        }

        // Tulis catatan kecil di bawah nilai durasi.
        function tulisCatatan(isi) {
            if (catatan) {
                catatan.textContent = isi;
            }
        }

        // Hitung ulang tampilan durasi.
        // terapkan true = admin baru mengubah jam, jadi kolom tersembunyi ikut diisi.
        function segarkan(terapkan) {
            var menitMulai   = menitDariJam(mulai.value);
            var menitSelesai = menitDariJam(selesai.value);

            if (menitMulai === null || menitSelesai === null) {
                peringatan.classList.add('hidden');
                teks.textContent = teksDurasi(nilaiTersimpan());
                tulisCatatan('Isi jam mulai dan jam selesai supaya durasi dihitung otomatis.');

                return;
            }

            var lintasMalam = menitSelesai < menitMulai;
            var total       = lintasMalam ? (menitSelesai + 1440) - menitMulai : menitSelesai - menitMulai;
            var tersimpan   = nilaiTersimpan();

            if (lintasMalam) {
                peringatan.classList.remove('hidden');
            } else {
                peringatan.classList.add('hidden');
            }

            // Nilai yang AKAN tersimpan: hasil hitungan jam bila admin baru
            // mengubah jam atau bila laporan belum punya durasi sama sekali.
            var pakaiHitungan = terapkan || tersimpan === null;

            if (pakaiHitungan && durasi) {
                durasi.value = total;
            }

            teks.textContent = teksDurasi(pakaiHitungan ? total : tersimpan);

            if (pakaiHitungan) {
                tulisCatatan('Dihitung dari jam ' + mulai.value + ' - ' + selesai.value
                    + ' dan akan tersimpan saat laporan disimpan.');

                return;
            }

            if (tersimpan === total) {
                tulisCatatan('Sesuai hitungan jam ' + mulai.value + ' - ' + selesai.value + ' (' + total + ' menit).');

                return;
            }

            tulisCatatan('Hitungan jam ' + mulai.value + ' - ' + selesai.value + ' = ' + total
                + ' menit; ubah salah satu jam untuk memakai hitungan itu.');
        }

        mulai.addEventListener('change', function () { segarkan(true); });
        selesai.addEventListener('change', function () { segarkan(true); });

        if (kosongkan) {
            kosongkan.addEventListener('click', function () {
                mulai.value   = '';
                selesai.value = '';
                segarkan(false);
            });
        }

        // Tampilan awal: durasi tersimpan tidak diubah, hanya dihitung ulang
        // sebagai informasi.
        segarkan(false);
    })();
</script>
@endpush

