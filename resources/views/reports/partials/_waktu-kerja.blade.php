{{-- Field jam kerja laporan (jam mulai & jam selesai).
     Dipisah jadi partial supaya halaman blade yang besar tidak perlu ditulis
     ulang (lihat aturan edit blade di AGENTS.md).
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
        <label class="inline-flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" id="durasi_otomatis" checked
                   class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            Hitung durasi otomatis dari jam
        </label>

        <button type="button" id="kosongkan_jam"
                class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 text-xs font-medium rounded-lg transition-colors">
            Kosongkan Jam
        </button>

        <span id="pratinjau_durasi" class="text-xs text-slate-500"></span>
    </div>

    <p id="peringatan_lintas_malam" class="hidden text-xs text-amber-600 mt-2">
        Jam selesai lebih kecil dari jam mulai: dihitung sebagai pekerjaan yang melewati tengah malam (ditambah 24 jam).
    </p>

    <p class="text-xs text-slate-400 mt-2">
        Jam boleh dikosongkan. Bila jam diisi, kolom "Durasi Pengerjaan (menit)" di bawah akan diisi otomatis
        selama kotak "Hitung durasi otomatis dari jam" masih dicentang.
    </p>
</div>

@push('scripts')
<script>
    // Sinkronisasi jam kerja dengan kolom Durasi Pengerjaan (menit).
    //
    // Aturan:
    //   - Saat jam mulai/selesai DIUBAH admin, durasi dihitung ulang dan diisi
    //     ke kolom "work_duration_minutes" (bila kotak otomatis dicentang).
    //   - Jam selesai lebih kecil dari jam mulai dihitung lintas tengah malam
    //     (ditambah 24 jam) dan memunculkan peringatan.
    //   - Saat halaman pertama kali dibuka, durasi yang sudah tersimpan TIDAK
    //     ditimpa; pratinjau hanya ditampilkan sebagai informasi.
    (function () {
        var mulai      = document.getElementById('start_time');
        var selesai    = document.getElementById('end_time');
        var durasi     = document.getElementById('work_duration_minutes');
        var otomatis   = document.getElementById('durasi_otomatis');
        var pratinjau  = document.getElementById('pratinjau_durasi');
        var peringatan = document.getElementById('peringatan_lintas_malam');
        var kosongkan  = document.getElementById('kosongkan_jam');

        if (!mulai || !selesai || !durasi || !pratinjau) {
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

        // Hitung ulang pratinjau; $terapkan true = ikut mengisi kolom durasi.
        function segarkan(terapkan) {
            var menitMulai   = menitDariJam(mulai.value);
            var menitSelesai = menitDariJam(selesai.value);

            if (menitMulai === null || menitSelesai === null) {
                pratinjau.textContent = '';
                peringatan.classList.add('hidden');

                return;
            }

            var lintasMalam = menitSelesai < menitMulai;
            var total       = lintasMalam ? (menitSelesai + 1440) - menitMulai : menitSelesai - menitMulai;

            pratinjau.textContent = 'Durasi dari jam: '
                + Math.floor(total / 60) + ' jam ' + (total % 60) + ' menit (' + total + ' menit)';

            if (lintasMalam) {
                peringatan.classList.remove('hidden');
            } else {
                peringatan.classList.add('hidden');
            }

            if (terapkan && (!otomatis || otomatis.checked)) {
                durasi.value = total;
            }
        }

        mulai.addEventListener('change', function () { segarkan(true); });
        selesai.addEventListener('change', function () { segarkan(true); });

        if (otomatis) {
            otomatis.addEventListener('change', function () { segarkan(otomatis.checked); });
        }

        if (kosongkan) {
            kosongkan.addEventListener('click', function () {
                mulai.value   = '';
                selesai.value = '';
                segarkan(false);
            });
        }

        // Tampilan awal: informasi saja, durasi tersimpan tidak diubah.
        segarkan(false);
    })();
</script>
@endpush
