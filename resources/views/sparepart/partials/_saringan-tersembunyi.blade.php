{{--
    Menyimpan saringan yang sedang aktif sebagai input tersembunyi.
    Dipakai form filter lanjutan supaya kolom lain tidak ikut ter-reset.
--}}
@php
    $kecuali = (array) ($kecuali ?? []);
    $kunci = [
        'q', 'kode', 'deskripsi', 'aksi', 'status', 'punya', 'pr', 'po', 'mrp',
        'min_stok_min', 'max_stok_min', 'min_stok', 'max_stok',
        'purch', 'vendor', 'dari', 'sampai', 'urut',
    ];
@endphp

@foreach($kunci as $nama)
    @continue(in_array($nama, $kecuali, true))
    @if(($saringan[$nama] ?? '') !== '')
        <input type="hidden" name="{{ $nama }}" value="{{ $saringan[$nama] }}">
    @endif
@endforeach
