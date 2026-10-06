{{--
    Kartu penanggung jawab ruang panel.

    Ditulis sebagai partial tersendiri supaya form pengaturan ruangan (340 baris)
    tidak perlu ditulis ulang. Isian dikirim sebagai penanggung_jawab[] dan
    disimpan oleh PanelRuanganController::simpanPenanggungJawab().
--}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-semibold text-slate-900">Penanggung Jawab Panel</h3>
        <p class="text-xs text-slate-500 mt-1">
            Boleh lebih dari satu teknisi. Setiap hari, pada awal jam masuk kerja masing-masing
            penanggung jawab (mengikuti jam kerja atau jadwal shift-nya), sistem mengirim pesan
            Telegram berisi kondisi terkini ruangan ini. Teknisi tanpa Telegram ID tetap boleh
            dipilih, tetapi tidak akan menerima pesan tersebut.
        </p>
    </div>
    <div class="p-6">
        <label class="block text-xs font-medium text-slate-500 mb-1.5">
            Nama teknisi (tekan Ctrl untuk memilih lebih dari satu)
        </label>
        <select name="penanggung_jawab[]" multiple size="10"
                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
            @forelse (($pilihanTeknisi ?? []) as $bagian => $daftarTeknisi)
                <optgroup label="{{ $bagian }}">
                    @foreach ($daftarTeknisi as $teknisiPilihan)
                        <option value="{{ $teknisiPilihan['id'] }}"
                            @selected(in_array($teknisiPilihan['id'], $penanggungJawabTerpilih ?? [], true))>
                            {{ $teknisiPilihan['nama'] }}{{ $teknisiPilihan['nik'] ? ' (' . $teknisiPilihan['nik'] . ')' : '' }}{{ $teknisiPilihan['ada_telegram'] ? '' : ' - tanpa Telegram' }}
                        </option>
                    @endforeach
                </optgroup>
            @empty
                <option value="" disabled>Belum ada data teknisi</option>
            @endforelse
        </select>
        <p class="text-[11px] text-slate-400 mt-2">
            Kosongkan pilihan bila ruangan ini tidak punya penanggung jawab khusus. Daftar
            penanggung jawab juga tampil di kartu dashboard dan halaman detail ruangan.
        </p>
    </div>
</div>
