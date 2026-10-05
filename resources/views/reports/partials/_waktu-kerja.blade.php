{{-- Field jam kerja laporan: JAM MULAI dan JAM SELESAI.

     Durasi pengerjaan BUKAN isian form dan tidak ditampilkan sebagai nilai
     tersendiri di halaman edit. Angka man hour (kolom work_duration_minutes)
     dihitung SERVER dari rentang jam ini saat laporan disimpan
     (Report::durasiDariJam() dipakai ReportController::update()), jadi form
     cukup meminta jamnya saja. Padanan aturan itu juga berlaku untuk laporan
     yang masuk lewat wizard Telegram.

     Dipisah jadi partial supaya blade halaman edit yang besar tidak perlu
     ditulis ulang (lihat aturan edit blade di AGENTS.md).

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
        <p class="text-xs text-slate-400">
            Durasi man hour laporan dihitung otomatis dari jam mulai sampai jam selesai saat laporan disimpan.
        </p>
    </div>

    <p id="peringatan_lintas_malam" class="hidden text-xs text-amber-600 mt-2">
        Jam selesai lebih kecil dari jam mulai: pekerjaan dihitung melewati tengah malam (ditambah 24 jam).
    </p>
</div>

@push('scripts')
<script>
    // Keterangan jam kerja pada form edit.
    //
    // Halaman edit TIDAK punya isian durasi lagi: durasi man hour dihitung
    // server dari rentang jam (lihat Report::durasiDariJam()). Skrip di sini
    // hanya membantu admin: menandai rentang yang melewati tengah malam dan
    // mengosongkan kedua jam dengan satu tombol.
    (function () {
        var mulai      = document.getElementById('start_time');
        var selesai    = document.getElementById('end_time');
        var peringatan = document.getElementById('peringatan_lintas_malam');
        var kosongkan  = document.getElementById('kosongkan_jam');

        if (!mulai || !selesai || !peringatan) {
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

        // Peringatan hanya muncul bila jam selesai lebih kecil dari jam mulai,
        // karena rentang seperti itu dihitung melewati tengah malam oleh server.
        function segarkanPeringatan() {
            var menitMulai   = menitDariJam(mulai.value);
            var menitSelesai = menitDariJam(selesai.value);

            if (menitMulai !== null && menitSelesai !== null && menitSelesai < menitMulai) {
                peringatan.classList.remove('hidden');

                return;
            }

            peringatan.classList.add('hidden');
        }

        mulai.addEventListener('change', segarkanPeringatan);
        selesai.addEventListener('change', segarkanPeringatan);

        if (kosongkan) {
            kosongkan.addEventListener('click', function () {
                mulai.value   = '';
                selesai.value = '';
                segarkanPeringatan();
            });
        }

        segarkanPeringatan();
    })();
</script>
@endpush
