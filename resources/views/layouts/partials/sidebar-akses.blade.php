{{-- Mengatur tampilan menu sidebar: membuang tautan posisi lama untuk menu yang
     sudah dipindah (Kolaborasi ke Menu Utama, Storage ke Pengaturan) dan
     menyembunyikan menu yang tidak diizinkan untuk Bagian pengguna. --}}
@php
    $penggunaSekarang = auth()->user();
    $menuTerlarang    = ($penggunaSekarang !== null && ! $penggunaSekarang->aksesPenuh())
        ? $penggunaSekarang->menuTerlarangUrls()
        : [];

    // Tautan posisi lama yang harus dibuang; posisi barunya ditandai kelas
    // "menu-pindahan" oleh partial sidebar-menu-utama / sidebar-pengaturan.
    $menuDipindah = [route('collaboration.index'), route('storage.index')];
@endphp

<script>
    /**
     * Rapikan menu sidebar:
     * 1. Buang tautan posisi lama menu yang sudah dipindah dan judul grup
     *    "Sistem" yang menjadi kosong.
     * 2. Sembunyikan menu yang tidak diizinkan untuk Bagian pengguna.
     *
     * Penegakan izin sebenarnya dilakukan AksesMiddleware di sisi server,
     * jadi pengaturan tampilan di sini hanya supaya menu tidak menyesatkan.
     */
    (function () {
        var menuTerlarang = @json($menuTerlarang);
        var menuDipindah  = @json($menuDipindah);

        // 1. Buang tautan posisi lama (tautan baru ditandai menu-pindahan).
        Array.prototype.forEach.call(document.querySelectorAll('#appSidebar .sidebar-nav a'), function (tautan) {
            if (tautan.classList.contains('menu-pindahan')) {
                return;
            }

            if (menuDipindah.indexOf(tautan.getAttribute('href')) !== -1) {
                tautan.remove();
            }
        });

        // Judul grup "Sistem" menjadi kosong setelah Storage dipindah.
        Array.prototype.forEach.call(document.querySelectorAll('#appSidebar .sidebar-nav p'), function (judul) {
            if (judul.textContent.trim() === 'Sistem') {
                judul.remove();
            }
        });

        // 2. Sembunyikan menu tanpa hak akses.
        Array.prototype.forEach.call(document.querySelectorAll('#appSidebar .sidebar-nav a'), function (tautan) {
            if (menuTerlarang.indexOf(tautan.getAttribute('href')) !== -1) {
                tautan.remove();
            }
        });
    })();
</script>
