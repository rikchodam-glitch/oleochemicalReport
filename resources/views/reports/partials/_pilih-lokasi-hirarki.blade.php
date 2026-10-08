{{-- Pemilih lokasi bertingkat (PT -> Departemen -> Area -> Section -> Alat) untuk
     lokasi UTAMA laporan pada mode edit halaman detail.

     Cara lama memaksa admin tahu Area lebih dulu sehingga lokasi harus
     ditelusuri ulang dari awal. Pemilih ini mengikuti alur wizard Telegram:
     pilih PT, lalu Departemen, Area, Section, dan akhirnya alat. Memilih node
     lokasi atau alat langsung mengisi Area + Functional Location + Kode Alat
     laporan (area diturunkan server dari node lokasi, lihat AssetLocationService).

     Kotak "Cari Alat (semua area)" pada partial _cari-alat tetap tersedia
     sebagai jalan pintas bila kode alatnya sudah diketahui.

     Markup dan perilakunya dipakai bersama panel tambah alat/lokasi tambahan
     (_alat-tambahan), jadi aturan hierarkinya hanya ditulis sekali di
     _pilih-lokasi-hirarki-script.blade.php.

     Parameter: $report = model Report yang sedang diedit. --}}
@php
    // Jalur lokasi laporan ini (root -> node terpilih) dipakai untuk membuka
    // kembali pilihan yang sudah tersimpan begitu halaman dimuat.
    $jalurLokasi = [];

    if ($report->functionalLocation !== null) {
        $jalurLokasi = $report->functionalLocation->getAncestors()->pluck('id')->all();
        $jalurLokasi[] = $report->functionalLocation->id;
    }
@endphp

<div class="mt-3 rounded-lg border border-slate-200 bg-white p-3"
     data-pilih-hirarki
     data-mode="laporan"
     data-url="{{ route('reports.locations.hirarki') }}"
     data-jalur="{{ json_encode(array_values($jalurLokasi)) }}"
     data-label-alat="Pakai alat ini"
     data-label-lokasi="Pakai lokasi ini">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs font-medium text-slate-700">Telusuri Hierarki Lokasi</p>
        <span data-peran="status" class="text-xs text-slate-400">
            PT &rarr; Departemen &rarr; Area &rarr; Section &rarr; Alat
        </span>
    </div>

    @include('reports.partials._pilih-lokasi-hirarki-badan')

    @include('reports.partials._pilih-lokasi-hirarki-script')
</div>
