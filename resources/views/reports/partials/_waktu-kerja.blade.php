{{-- Field jam kerja laporan: JAM MULAI, JAM SELESAI, dan DURASI.

     Durasi BUKAN isian: angkanya dihitung server dari rentang jam ini saat
     laporan disimpan (JamKerjaService::durasiKerja() dipakai
     ReportController::update()), jadi yang bisa diedit tetap jamnya. Angka yang
     DITAMPILKAN di sini dihitung ulang dari jam laporan memakai
     JamKerjaService::ringkasJamKerja() -- bukan dibaca dari kolom durasi
     tersimpan -- supaya laporan lama yang durasinya masih memuat jam istirahat
     atau jam di luar jam kerja tidak tampak dobel bersama lembur.

     Aturan yang dipakai (sama persis dengan server):
       - jam selesai lebih kecil dari jam mulai = melewati tengah malam (+24 jam)
       - jam selesai 24:00 = akhir hari (mis. 23:00 - 24:00 = 60 menit)
       - jam selesai SAMA dengan jam mulai = bukan rentang, ditolak server
       - kedua jam dikosongkan = durasi lama laporan dipertahankan server
       - ISTIRAHAT hari itu DIKURANGI untuk pekerja non-shift (reguler &
         reguler staff): Senin-Sabtu 12:00-13:00, Jumat 11:00-13:00 menurut
         config('cm.man_hour.jam_kerja'). Pekerja shift (grub A-D) dan hari
         libur tidak dipotong.
       - LEMBUR = jam di LUAR jendela kerja hari itu (sebelum jam masuk dan
         sesudah jam pulang) serta SELURUH jam pada hari libur/OFF. Jam
         istirahat BUKAN lembur (keputusan user 13/10/2026): menit itu
         terpotong dari durasi sehingga tidak dihitung di mana pun. Aturan ini
         berlaku untuk pekerja shift juga.
     Format label durasi disamakan dengan accessor Report::duration_label
     ("X Jam Y Menit") supaya tampilan mode lihat dan mode edit tidak berbeda.

     Dipisah jadi partial supaya blade halaman detail yang besar tidak perlu
     ditulis ulang (lihat aturan edit blade di AGENTS.md).

     Parameter: $report = model Report yang sedang diedit. --}}

@php
    // Nilai 24:00 (akhir hari, Report::JAM_AKHIR_HARI) tidak bisa ditampung
    // <input type="time"> karena batasnya 23:59, sehingga isian jam selesai
    // memakai type="text" bila nilainya 24:00 supaya angka dari database tidak
    // hilang begitu halaman dibuka.
    $nilaiJamMulai    = old('start_time', $report->start_time);
    $nilaiJamSelesai  = old('end_time', $report->end_time);
    $selesaiAkhirHari = $nilaiJamSelesai === \App\Models\Report::JAM_AKHIR_HARI;

    // Jendela istirahat hari itu (daftar kosong untuk pekerja shift atau hari
    // libur) dan jendela kerja bruto hari itu (jadwal shift / jam kerja harian).
    // Dipakai untuk keterangan di layar dan oleh skrip penghitung durasi di
    // bawah supaya hasil di layar sama dengan hasil server.
    $layananJamKerja  = app(\App\Services\Shift\JamKerjaService::class);
    $tanggalLaporan   = $report->report_date ?? \Illuminate\Support\Carbon::now();
    $jendelaKerja     = $layananJamKerja->jendelaKerja($report->technician, $tanggalLaporan);
    $jendelaIstirahat = $layananJamKerja->istirahatHari($report->technician?->group, $tanggalLaporan);
    $labelIstirahat   = collect($jendelaIstirahat)
        ->map(function (array $jendela): string {
            $mulai   = (string) ($jendela[0] ?? '');
            $selesai = (string) ($jendela[1] ?? '');

            return $mulai === '' || $selesai === '' ? '' : $mulai . '-' . $selesai;
        })
        ->filter()
        ->implode(', ');

    // Angka tampilan dihitung dari jam laporan memakai aturan server
    // (JamKerjaService::ringkasJamKerja()) supaya laporan lama yang durasinya
    // masih memuat istirahat / jam di luar jam kerja tidak tampak dobel.
    $ringkasLaporan = $layananJamKerja->ringkasJamKerja(
        $layananJamKerja->jadwalHari($report->technician, $tanggalLaporan),
        $report->technician?->group,
        $tanggalLaporan,
        $nilaiJamMulai,
        $nilaiJamSelesai
    );

    // Label menit dengan format yang sama seperti Report::duration_label.
    $labelMenit = function (int $menit): string {
        $jam   = intdiv($menit, 60);
        $sisa  = $menit % 60;
        $label = [];

        if ($jam > 0) {
            $label[] = $jam . ' Jam';
        }

        if ($sisa > 0) {
            $label[] = $sisa . ' Menit';
        }

        return implode(' ', $label) ?: '0 Menit';
    };

    // Durasi man hour dari jam laporan bila rentangnya sah; cadangan nilai lama
    // laporan bila jam mulai/selesai tidak ada.
    $durasiTampil   = $ringkasLaporan['normal'] !== null
        ? $labelMenit((int) $ringkasLaporan['normal'])
        : ($report->duration_label ?? '-');
    $potonganTampil = $ringkasLaporan['normal'] !== null
        ? $labelMenit((int) $ringkasLaporan['istirahat'])
        : '-';
    $lemburTampil   = $ringkasLaporan['normal'] !== null
        ? $labelMenit((int) $ringkasLaporan['lembur'])
        : '-';
@endphp


<div class="border-t border-slate-100 pt-4">
    <h3 class="text-sm font-medium text-slate-900 mb-3">Waktu Pengerjaan</h3>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="start_time" class="block text-sm font-medium text-slate-700 mb-1">Jam Mulai</label>
            <input type="time" id="start_time" name="start_time" form="form-edit-laporan" step="60"
                   value="{{ $nilaiJamMulai }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            @error('start_time')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="end_time" class="block text-sm font-medium text-slate-700 mb-1">Jam Selesai</label>
            <input type="{{ $selesaiAkhirHari ? 'text' : 'time' }}" id="end_time" name="end_time"
                   form="form-edit-laporan" step="60" inputmode="numeric" maxlength="5" placeholder="HH:MM"
                   value="{{ $nilaiJamSelesai }}"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-slate-400 mt-1">Isi <span class="font-medium">24:00</span> bila pekerjaan selesai tengah malam.</p>
            @error('end_time')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="durasi_kerja" class="block text-sm font-medium text-slate-700 mb-1">Durasi Man Hour</label>
            <input type="text" id="durasi_kerja" readonly tabindex="-1"
                   value="{{ $durasiTampil }}"
                   class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600">
            <p class="text-xs text-slate-400 mt-1">
                Dihitung otomatis dari jam di samping.
                @if ($labelIstirahat !== '')
                    Istirahat dikurangi: <span class="font-medium">{{ $labelIstirahat }}</span>.
                @else
                    Tanpa potongan istirahat (pekerja shift atau hari libur).
                @endif
                @if ($jendelaKerja !== null)
                    Jam kerja: <span class="font-medium">{{ $jendelaKerja['mulai'] }}-{{ $jendelaKerja['selesai'] }}</span>;
                    jam di luar itu dihitung lembur (tidak menambah man hour).
                @else
                    Hari ini libur/OFF: seluruh jam kerja dihitung lembur.
                @endif
            </p>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" id="kosongkan_jam"
                class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-600 text-xs font-medium rounded-lg transition-colors">
            Kosongkan Jam
        </button>
        <p class="text-xs text-slate-400">
            Rentang terpilih: <span id="rentang_kerja" class="font-medium text-slate-600">{{ $report->time_range ?? '-' }}</span>
            &middot; Potongan istirahat: <span id="potongan_istirahat" class="font-medium text-slate-600">{{ $potonganTampil }}</span>
            &middot; Lembur di luar jam kerja: <span id="lembur_kerja" class="font-medium text-amber-600">{{ $lemburTampil }}</span>
        </p>
    </div>

    <p class="text-xs text-slate-400 mt-1">
        Mengosongkan kedua jam membuat durasi laporan yang sudah tersimpan dipakai kembali.
    </p>

    <p id="peringatan_lintas_malam" class="hidden text-xs text-amber-600 mt-2">
        Jam selesai lebih kecil dari jam mulai: pekerjaan dihitung melewati tengah malam (ditambah 24 jam).
    </p>

    <p id="peringatan_jam_sama" class="hidden text-xs text-red-600 mt-2">
        Jam mulai dan jam selesai sama: bukan rentang waktu. Perbaiki salah satu jamnya sebelum menyimpan.
    </p>
</div>


@push('scripts')
<script>
    // Keterangan jam kerja pada form edit.
    //
    // Halaman detail TIDAK punya isian durasi: durasi man hour dihitung server
    // dari rentang jam dikurangi istirahat hari itu
    // (JamKerjaService::durasiKerja()). Skrip di sini menyiapkan tampilan
    // durasi read-only, peringatan lintas tengah malam, besarnya potongan
    // istirahat, dan LEMBUR memakai aturan yang sama dengan server:
    //   - durasi normal = menit di dalam jendela kerja dikurangi istirahat,
    //   - lembur = menit di LUAR jendela kerja (sebelum jam masuk / sesudah jam
    //     pulang) dan seluruh jam pada hari libur/OFF,
    //   - jam ISTIRAHAT bukan lembur: menitnya terpotong dari durasi
    //     (keputusan user 13/10/2026), jadi tidak ada menit yang dihitung dua
    //     kali antara durasi dan lembur.
    (function () {
        var mulai          = document.getElementById('start_time');
        var selesai        = document.getElementById('end_time');
        var durasi         = document.getElementById('durasi_kerja');
        var rentang        = document.getElementById('rentang_kerja');
        var potongan       = document.getElementById('potongan_istirahat');
        var lembur         = document.getElementById('lembur_kerja');
        var peringatan     = document.getElementById('peringatan_lintas_malam');
        var peringatanSama = document.getElementById('peringatan_jam_sama');
        var kosongkan      = document.getElementById('kosongkan_jam');

        // Jendela istirahat hari itu dari server. Daftar kosong berarti tidak
        // ada potongan (pekerja shift grub A-D atau hari libur).
        var jendelaIstirahat = @json($jendelaIstirahat);

        // Jendela kerja bruto hari itu (jadwal shift / jam kerja harian).
        // null = hari libur/OFF, seluruh jam kerja pada hari itu dihitung lembur.
        var jendelaKerja = @json($jendelaKerja);

        if (!mulai || !selesai || !durasi || !rentang || !potongan || !lembur || !peringatan || !peringatanSama) {
            return;
        }

        // Ubah "HH:MM" menjadi menit sejak tengah malam; null bila tidak sah.
        // "24:00" dikenali sebagai 1440 menit (akhir hari) mengikuti
        // Report::menitDariJam() di sisi server.
        function menitDariJam(nilai) {
            var cocok = /^(\d{1,2}):(\d{2})$/.exec(nilai || '');

            if (!cocok) {
                return null;
            }

            var jam   = parseInt(cocok[1], 10);
            var menit = parseInt(cocok[2], 10);

            if (menit > 59 || jam > 24 || (jam === 24 && menit !== 0)) {
                return null;
            }

            return (jam * 60) + menit;
        }

        // Menit istirahat yang masuk ke dalam rentang kerja, mengikuti
        // JamKerjaService::menitIstirahat(): irisan tiap jendela istirahat
        // dengan rentang, dihitung pada satu garis waktu (rentang yang melewati
        // tengah malam memakai akhir +24 jam).
        function menitIstirahat(mulaiMenit, selesaiMenit) {
            if (mulaiMenit === null || selesaiMenit === null || mulaiMenit === selesaiMenit) {
                return 0;
            }

            var akhir = selesaiMenit < mulaiMenit ? selesaiMenit + (24 * 60) : selesaiMenit;
            var total = 0;

            for (var i = 0; i < jendelaIstirahat.length; i++) {
                var awalJendela  = menitDariJam(jendelaIstirahat[i][0]);
                var akhirJendela = menitDariJam(jendelaIstirahat[i][1]);

                if (awalJendela === null || akhirJendela === null) {
                    continue;
                }

                total += Math.max(0, Math.min(akhir, akhirJendela) - Math.max(mulaiMenit, awalJendela));
            }

            return total;
        }

        // Menit rentang yang jatuh di dalam jendela kerja hari itu, mengikuti
        // JamKerjaService::menitDalamJendela(). Jendela null (hari libur/OFF)
        // berarti tidak ada menit yang masuk jam kerja.
        function menitDalamJendela(mulaiMenit, selesaiMenit) {
            if (jendelaKerja === null || mulaiMenit === null || selesaiMenit === null || mulaiMenit === selesaiMenit) {
                return 0;
            }

            var awalJendela  = menitDariJam(jendelaKerja.mulai);
            var akhirJendela = menitDariJam(jendelaKerja.selesai);

            if (awalJendela === null || akhirJendela === null) {
                return 0;
            }

            var akhir = selesaiMenit < mulaiMenit ? selesaiMenit + (24 * 60) : selesaiMenit;

            return Math.max(0, Math.min(akhir, akhirJendela) - Math.max(mulaiMenit, awalJendela));
        }

        // Label durasi dengan format yang sama seperti accessor duration_label.
        function labelDurasi(menit) {
            if (menit === null) {
                return '-';
            }

            var jam    = Math.floor(menit / 60);
            var sisa   = menit % 60;
            var bagian = [];

            if (jam > 0) {
                bagian.push(jam + ' Jam');
            }

            if (sisa > 0) {
                bagian.push(sisa + ' Menit');
            }

            return bagian.length ? bagian.join(' ') : '0 Menit';
        }


        // Segarkan tampilan durasi, rentang, potongan istirahat, lembur, dan
        // peringatan. Angka yang ditampilkan harus sama dengan hasil server
        // (JamKerjaService::ringkasJamKerja()).
        function segarkan() {
            var menitMulai   = menitDariJam(mulai.value);
            var menitSelesai = menitDariJam(selesai.value);

            peringatanSama.classList.add('hidden');

            if (menitMulai === null || menitSelesai === null) {
                rentang.textContent  = '-';
                potongan.textContent = '-';
                lembur.textContent   = '-';
                peringatan.classList.add('hidden');

                return;
            }

            // Jam mulai = jam selesai bukan rentang: server menolaknya
            // (Report::durasiDariJam() mengembalikan null), jadi di layar pun
            // durasi tidak dihitung dan admin diberi peringatan.
            if (menitMulai === menitSelesai) {
                rentang.textContent  = '-';
                durasi.value         = '-';
                potongan.textContent = '-';
                lembur.textContent   = '-';
                peringatan.classList.add('hidden');
                peringatanSama.classList.remove('hidden');

                return;
            }

            var lewatTengahMalam = menitSelesai < menitMulai;
            var total            = menitSelesai - menitMulai;

            if (lewatTengahMalam) {
                total += 24 * 60;
                peringatan.classList.remove('hidden');
            } else {
                peringatan.classList.add('hidden');
            }

            // Jam kerja NORMAL = menit di dalam jendela kerja hari itu dikurangi
            // istirahat (istirahat yang benar-benar dipotong = irisan istirahat
            // dengan jendela kerja, mengikuti Math.min() di server).
            var dalamJendela = menitDalamJendela(menitMulai, menitSelesai);
            var istirahat    = Math.min(menitIstirahat(menitMulai, menitSelesai), dalamJendela);
            var normal       = Math.max(0, dalamJendela - istirahat);
            // LEMBUR hanya menit di LUAR jendela kerja: jam istirahat TIDAK
            // dihitung lembur (keputusan user 13/10/2026) sehingga durasi tidak
            // pernah terhitung dua kali bersama lembur.
            var lemburMenit  = Math.max(0, total - dalamJendela);

            rentang.textContent  = mulai.value + ' - ' + selesai.value;
            potongan.textContent = labelDurasi(istirahat);
            lembur.textContent   = labelDurasi(lemburMenit);
            durasi.value         = labelDurasi(normal);
        }

        mulai.addEventListener('change', segarkan);
        selesai.addEventListener('change', segarkan);
        mulai.addEventListener('input', segarkan);
        selesai.addEventListener('input', segarkan);

        if (kosongkan) {
            kosongkan.addEventListener('click', function () {
                mulai.value   = '';
                selesai.value = '';
                segarkan();
            });
        }

        segarkan();
    })();
</script>
@endpush

