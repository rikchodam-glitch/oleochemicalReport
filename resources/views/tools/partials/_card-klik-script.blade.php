<script>
(function () {
    document.querySelectorAll('[data-href-card]').forEach(function (kartu) {
        function buka() {
            const href = kartu.getAttribute('data-href-card');

            if (href) {
                window.location.href = href;
            }
        }

        kartu.addEventListener('click', function (event) {
            if (event.target.closest('a, button, form, input, select, textarea, label, [data-no-card-click]')) {
                return;
            }

            buka();
        });

        kartu.addEventListener('keydown', function (event) {
            if (event.target.closest('a, button, form, input, select, textarea, label, [data-no-card-click]')) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                buka();
            }
        });
    });
})();
</script>
