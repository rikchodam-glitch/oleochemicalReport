{{-- Badan markup pemilih lokasi bertingkat PT -> Departemen -> Area -> Section
     -> Alat.

     Dipakai bersama oleh pemilih lokasi UTAMA laporan (_pilih-lokasi-hirarki)
     dan panel tambah alat/lokasi tambahan (_alat-tambahan) supaya tampilannya
     sama dan logikanya hanya satu (_pilih-lokasi-hirarki-script).

     Elemen ditandai atribut data-peran supaya skrip bisa mencarinya tanpa
     bergantung pada id: status, pt, departemen, area, section, alat, pesan. --}}
<div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
    @foreach (['pt' => 'PT', 'departemen' => 'Departemen', 'area' => 'Area', 'section' => 'Section'] as $peran => $label)
        <select data-peran="{{ $peran }}" disabled
                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
            <option value="">- Pilih {{ $label }} -</option>
        </select>
    @endforeach
</div>

    {{-- Penyaring alat pada node yang sedang dibuka. Daftar alat per section bisa
         memuat 200+ baris, jadi server memotongnya 50 baris dan sisanya dicari
         lewat kotak ini. --}}
    <div class="mt-2">
        <input type="text" data-peran="cari" disabled autocomplete="off"
               placeholder="Saring alat pada lokasi terpilih (minimal 2 huruf)"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
    </div>

<div data-peran="alat" class="hidden mt-2 rounded-lg border border-slate-200"></div>

<p data-peran="pesan" class="text-xs text-slate-400 mt-2">
    Pilih sampai tingkat Section, lalu pilih alatnya bila pekerjaan menyentuh satu alat tertentu.
</p>
