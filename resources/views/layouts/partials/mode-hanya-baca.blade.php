{{-- Pemberitahuan mode hanya baca + penyembunyi tombol aksi tulis.

     Sejak 15/10/2026 akun non-administrator bisa punya IZIN TULIS SEBAGIAN
     (Reguler Staff / Staff Grub A-D): mengelola laporan grub yang dinaungi,
     import PO Spare Part, request Spare Part, dan sync data CM. Untuk akun itu form yang diizinkan
     tetap tampil (mis. form edit laporan), sedangkan form lain disembunyikan.
     Penegakan sebenarnya ada di HanyaBacaMiddleware + penjaga per-baris di
     ReportController/ReportCollaborationController/ReportMultiItemController.
--}}
@php
    $penggunaBaca = auth()->user();

    $polaTulis = ($penggunaBaca !== null && ! $penggunaBaca->bolehMenulis())
        ? $penggunaBaca->polaTulisTampilan()
        : [];

    // Boleh tidaknya pengguna mengelola LAPORAN pada halaman laporan yang sedang
    // dibuka. Dipakai supaya tombol Edit/Hapus laporan grub lain tidak muncul
    // (server tetap menolaknya walau tombol dipaksa tampil).
    $bolehKelolaHalaman = true;
    $ruteSekarang       = request()->route()?->getName();
    $laporanHalaman     = request()->route('report');

    if ($polaTulis !== [] && $ruteSekarang === 'reports.show' && $laporanHalaman instanceof \App\Models\Report) {
        $indukHalaman = $laporanHalaman->multi_of !== null
            ? $laporanHalaman->multiOf
            : ($laporanHalaman->collaborator_of !== null ? $laporanHalaman->parentReport : $laporanHalaman);

        $bolehKelolaHalaman = (bool) $penggunaBaca?->bolehKelolaLaporan($indukHalaman ?? $laporanHalaman);
    }
@endphp

@if ($penggunaBaca !== null && ! $penggunaBaca->bolehMenulis())
    <div class="mx-6 mt-4 rounded-lg bg-teal-50 border border-teal-100 px-4 py-3">
        @if ($polaTulis === [])
            <p class="text-xs font-semibold text-teal-800">Mode hanya baca</p>
            <p class="text-xs text-teal-700 mt-0.5">
                Akun Bagian {{ $penggunaBaca->labelBagian() }} hanya bisa melihat dan mengekspor data.
                Tombol tambah/ubah/hapus disembunyikan, dan permintaan perubahan data tetap ditolak di server.
            </p>
        @else
            <p class="text-xs font-semibold text-teal-800">Akses terbatas</p>
            <p class="text-xs text-teal-700 mt-0.5">
                Akun Bagian {{ $penggunaBaca->labelBagian() }} bisa mengelola laporan grub yang dinaungi
                (termasuk kolaborasi bila laporan itu induk), import PO Spare Part, request Spare Part,
                dan sync data CM. Perubahan data lain (aset, import lain, dan sebagainya) tetap ditolak di server.
            </p>
        @endif
    </div>

    <script>
        /**
         * Sembunyikan form/tautan aksi tulis yang tidak diizinkan.
         *
         * Penolakan sebenarnya dilakukan HanyaBacaMiddleware di sisi server,
         * penyembunyian ini hanya supaya tampilan tidak menyesatkan.
         */
        (function () {
            var area = document.getElementById('appMain');
            if (! area) {
                return;
            }

            var POLA_TULIS = @json($polaTulis);
            var BOLEH_KELOLA_HALAMAN = @json($bolehKelolaHalaman);

            /**
             * Apakah sebuah URL (relatif maupun absolut) diizinkan dikirim.
             *
             * @param  {string} url Alamat action form
             * @return {boolean}
             */
            function urlDiizinkan(url) {
                if (! url) {
                    return false;
                }

                var path = url;

                try {
                    path = new URL(url, window.location.origin).pathname;
                } catch (e) {
                    return false;
                }

                return POLA_TULIS.some(function (pola) {
                    try {
                        return new RegExp(pola).test(path);
                    } catch (e) {
                        return false;
                    }
                });
            }

            // 1. Form yang mengubah data (metode selain GET) disembunyikan bila
            //    tidak diizinkan. Halaman laporan grub lain diperlakukan hanya
            //    baca sehingga seluruh form tulisnya dibuang.
            Array.prototype.forEach.call(area.querySelectorAll('form'), function (form) {
                var metode = (form.getAttribute('method') || 'GET').toUpperCase();

                if (metode === 'GET') {
                    return;
                }

                if (BOLEH_KELOLA_HALAMAN && urlDiizinkan(form.getAttribute('action'))) {
                    return;
                }

                form.remove();
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

            // 3. Tombol "Edit Laporan" pada laporan yang tidak boleh dikelola.
            if (! BOLEH_KELOLA_HALAMAN) {
                Array.prototype.forEach.call(area.querySelectorAll('button'), function (tombol) {
                    var aksi = tombol.getAttribute('@click') || tombol.getAttribute('x-on:click') || '';

                    if (aksi.indexOf('enableEditMode') !== -1) {
                        tombol.remove();
                    }
                });
            }

            // 4. Tombol pembuka modal tambah/impor data. Hanya untuk akun yang
            //    benar-benar hanya baca (tanpa izin tulis sebagian), supaya
            //    tombol "Tambah Kolaborator"/"Tambah" pada laporan staf tetap ada.
            if (POLA_TULIS.length === 0) {
                Array.prototype.forEach.call(area.querySelectorAll('button'), function (tombol) {
                    var teks = (tombol.textContent || '').trim().toLowerCase();

                    if (teks.indexOf('tambah') === 0 || teks.indexOf('impor') === 0) {
                        tombol.remove();
                    }
                });
            }
        })();
    </script>
@endif
