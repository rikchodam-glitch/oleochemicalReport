{{-- Peringatan cakupan berkas sumber pada import terakhir --}}
@if($impor !== null && $impor->peringatanCakupan() !== [])
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
        <p class="font-semibold">Cakupan berkas import terakhir belum lengkap</p>
        <ul class="list-disc list-inside space-y-1 mt-1">
            @foreach($impor->peringatanCakupan() as $peringatan)
                <li>{{ $peringatan }}</li>
            @endforeach
        </ul>
        <p class="mt-2 text-xs">
            Kolom Aksi pada tabel di bawah mengikuti berkas itu, jadi belum tentu menggambarkan
            keadaan sebenarnya.
            Bila berkas PR/PO yang diunggah hanya cuplikan bulan berjalan, material yang sudah
            punya PR terbuka tidak terbaca sebagai Buat PO melainkan jatuh ke Buat PR, sehingga
            angka Buat PO bisa tampak nol walaupun sebenarnya ada PR yang menunggu dibuat PO.
            <a href="{{ route('sparepart.riwayat', ['impor' => $impor->id]) }}" class="font-semibold underline">
                Lihat analisa import #{{ $impor->id }}
            </a>
        </p>
    </div>
@endif
