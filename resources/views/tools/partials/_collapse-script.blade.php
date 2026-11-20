<script>
(function () {
    if (window.__collapseAlatTerpasang) {
        return;
    }

    window.__collapseAlatTerpasang = true;

    function atur(tombol, buka) {
        const id = tombol.getAttribute('aria-controls');
        const target = id ? document.getElementById(id) : null;

        if (!target) {
            return;
        }

        target.classList.toggle('hidden', !buka);
        tombol.setAttribute('aria-expanded', buka ? 'true' : 'false');

        const chevron = tombol.querySelector('[data-collapse-chevron]');

        if (chevron) {
            chevron.style.transform = buka ? 'rotate(90deg)' : '';
        }
    }

    window.bukaSemuaCollapse = function () {
        document.querySelectorAll('[data-collapse-toggle]').forEach(function (tombol) {
            atur(tombol, true);
        });
    };

    document.addEventListener('click', function (event) {
        const semua = event.target.closest('[data-collapse-all]');

        if (semua) {
            const buka = semua.getAttribute('data-collapse-all') === 'buka';

            document.querySelectorAll('[data-collapse-toggle]').forEach(function (tombol) {
                atur(tombol, buka);
            });

            return;
        }

        const tombol = event.target.closest('[data-collapse-toggle]');

        if (!tombol) {
            return;
        }

        atur(tombol, tombol.getAttribute('aria-expanded') !== 'true');
    });

    document.querySelectorAll('[data-collapse-toggle]').forEach(function (tombol) {
        const target = document.getElementById(tombol.getAttribute('aria-controls'));
        atur(tombol, target ? !target.classList.contains('hidden') : true);
    });
})();
</script>
