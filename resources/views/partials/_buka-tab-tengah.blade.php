{{-- Klik tengah (tekan roda gulir) pada baris tabel membuka halaman tujuan
     di tab baru.

     Baris yang ingin memakai perilaku ini cukup menambahkan atribut
     data-buka-tab="<url>". Klik kiri tetap memakai onclick milik baris, dan
     tautan/tombol di dalam baris TIDAK diambil alih: klik tengah pada tautan
     tetap membuka tautan itu secara native.

     Cegah pemasangan ganda bila partial ini tidak sengaja dimuat dua kali. --}}
@push('scripts')
<script>
    // Buka tab baru lewat klik tengah pada baris tabel.
    if (!window.__bukaTabTengahTerpasang) {
        window.__bukaTabTengahTerpasang = true;

        (function () {
            var SELECTOR_BARIS = '[data-buka-tab]';
            var SELECTOR_ABAIKAN = 'a, button, input, select, textarea, label, [data-abaikan-baris]';

            /**
             * Cari baris yang boleh membuka tab dari elemen yang diklik.
             * Mengembalikan null bila klik berada di tautan/tombol (biarkan
             * perilaku native browser) atau bukan di dalam baris.
             *
             * @param  {EventTarget} target  Elemen sasaran klik
             * @return {Element|null}        Baris dengan atribut data-buka-tab
             */
            function barisDari(target) {
                if (!(target instanceof Element)) {
                    return null;
                }

                var baris = target.closest(SELECTOR_BARIS);

                if (!baris || target.closest(SELECTOR_ABAIKAN)) {
                    return null;
                }

                return baris;
            }

            // Cegah mode autoscroll browser saat roda gulir ditekan di baris.
            document.addEventListener('mousedown', function (kejadian) {
                if (kejadian.button !== 1) {
                    return;
                }

                if (barisDari(kejadian.target)) {
                    kejadian.preventDefault();
                }
            });

            // auxclick = klik selain tombol kiri; button 1 = roda gulir.
            document.addEventListener('auxclick', function (kejadian) {
                if (kejadian.button !== 1) {
                    return;
                }

                var baris = barisDari(kejadian.target);

                if (!baris) {
                    return;
                }

                var url = baris.getAttribute('data-buka-tab');

                if (!url) {
                    return;
                }

                kejadian.preventDefault();
                window.open(url, '_blank', 'noopener');
            });
        })();
    }
</script>
@endpush
