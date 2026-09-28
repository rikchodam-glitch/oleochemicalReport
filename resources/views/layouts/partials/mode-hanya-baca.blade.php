{{-- Pemberitahuan mode hanya baca + penyembunyi tombol aksi tulis. --}}
@php
    $penggunaBaca = auth()->user();
@endphp

@if ($penggunaBaca !== null && ! $penggunaBaca->bolehMenulis())
    <div class="mx-6 mt-4 rounded-lg bg-teal-50 border border-teal-100 px-4 py-3">
        <p class="text-xs font-semibold text-teal-800">Mode hanya baca</p>
        <p class="text-xs text-teal-700 mt-0.5">
            Akun Bagian {{ $penggunaBaca->labelBagian() }} hanya bisa melihat dan mengekspor data.
            Tombol tambah/ubah/hapus disembunyikan, dan permintaan perubahan data tetap ditolak di server.
        </p>
    </div>

    <script>
        /**
         * Sembunyikan tombol aksi tulis untuk akun hanya baca.
         *
         * Penolakan sebenarnya dilakukan HanyaBacaMiddleware di sisi server,
         * penyembunyian ini hanya supaya tampilan tidak menyesatkan.
         */
        (function () {
            var area = document.getElementById('appMain');
            if (! area) {
                return;
            }

            // 1. Form yang mengubah data (metode selain GET) disembunyikan.
            Array.prototype.forEach.call(area.querySelectorAll('form'), function (form) {
                var metode = (form.getAttribute('method') || 'GET').toUpperCase();
                if (metode !== 'GET') {
                    form.remove();
                }
            });

            // 2. Tautan menuju halaman tambah/ubah/impor data.
            var polaTautan = ['/create', '/edit', '/import', '/unlinked-funcloc'];
            Array.prototype.forEach.call(area.querySelectorAll('a[href]'), function (tautan) {
                var url = tautan.getAttribute('href') || '';
                var dilarang = polaTautan.some(function (pola) {
                    return url.indexOf(pola) !== -1;
                });

                if (dilarang) {
                    tautan.remove();
                }
            });

            // 3. Tombol pembuka modal tambah/impor data.
            Array.prototype.forEach.call(area.querySelectorAll('button'), function (tombol) {
                var teks = (tombol.textContent || '').trim().toLowerCase();
                if (teks.indexOf('tambah') === 0 || teks.indexOf('impor') === 0) {
                    tombol.remove();
                }
            });
        })();
    </script>
@endif
