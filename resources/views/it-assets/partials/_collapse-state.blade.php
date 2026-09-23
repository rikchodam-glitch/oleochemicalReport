{{--
    Partial: menyimpan dan memulihkan status buka/tutup (collapse) halaman
    daftar IT Assets di localStorage, supaya setelah user membuka halaman
    detail/edit sebuah perangkat lalu kembali ke daftar, grup CCTV per PT,
    grup per switch, dan panel filter tetap seperti terakhir dilihat.

    Dipakai index.blade.php lewat satu baris @include di dalam @push('scripts').

    Catatan: fungsi toggle bawaan halaman (toggleCctvGroup, toggleFilter)
    dibungkus (wrap) di sini, bukan diubah, sehingga perilaku aslinya tidak
    berubah dan tidak ada duplikasi kode.
--}}
<script>
(function () {
    var STORAGE_GROUPS = 'itAssets.collapsedGroups';
    var STORAGE_FILTER = 'itAssets.filterOpen';

    /**
     * Baca daftar id grup yang sedang tertutup dari localStorage.
     *
     * @returns {string[]}
     */
    function readCollapsedGroups() {
        try {
            var raw = window.localStorage.getItem(STORAGE_GROUPS);
            var parsed = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Simpan daftar grup tertutup. Kegagalan localStorage (mode private)
     * diabaikan supaya halaman tetap berjalan normal.
     *
     * @param {string[]} ids
     */
    function writeCollapsedGroups(ids) {
        try {
            window.localStorage.setItem(STORAGE_GROUPS, JSON.stringify(ids));
        } catch (e) {
            // diabaikan
        }
    }

    /**
     * Kumpulkan seluruh grup yang saat ini tertutup dari DOM lalu simpan.
     */
    function persistGroupsFromDom() {
        var ids = [];

        document.querySelectorAll('[id^="cctv-group-"], [id^="switch-group-"]').forEach(function (el) {
            if (el.classList.contains('hidden')) {
                ids.push(el.id);
            }
        });

        writeCollapsedGroups(ids);
    }

    /**
     * Terapkan status tertutup yang tersimpan: sembunyikan grup dan putar
     * chevron tombolnya, sama seperti hasil klik manual.
     */
    function applySavedGroups() {
        readCollapsedGroups().forEach(function (id) {
            var group = document.getElementById(id);
            if (!group) {
                return;
            }

            group.classList.add('hidden');

            var button = document.querySelector('[onclick*="' + id + '"]');
            var chevron = button ? button.querySelector('svg') : null;
            if (chevron) {
                chevron.classList.add('rotate-180');
            }
        });
    }

    /**
     * Apakah panel filter sedang terbuka (dibaca dari inline style yang
     * dipasang toggleFilter()).
     *
     * @param {HTMLElement} panel
     * @returns {boolean}
     */
    function isFilterOpen(panel) {
        return panel.style.maxHeight !== '' && panel.style.maxHeight !== '0px';
    }

    /**
     * Pulihkan status panel filter yang tersimpan. Dijalankan setelah jeda
     * singkat karena halaman ini otomatis membuka panel saat ada filter aktif.
     */
    function applySavedFilterState() {
        var saved = null;
        try {
            saved = window.localStorage.getItem(STORAGE_FILTER);
        } catch (e) {
            return;
        }

        if (saved === null) {
            return;
        }

        var panel = document.getElementById('filterPanel');
        if (!panel) {
            return;
        }

        var shouldOpen = saved === '1';
        if (shouldOpen !== isFilterOpen(panel) && typeof window.toggleFilter === 'function') {
            window.toggleFilter();
        }
    }

    window.addEventListener('DOMContentLoaded', function () {
        applySavedGroups();

        // Bungkus toggle grup: perilaku asli dijalankan dulu, baru status disimpan.
        var originalGroupToggle = window.toggleCctvGroup;
        if (typeof originalGroupToggle === 'function') {
            window.toggleCctvGroup = function () {
                var result = originalGroupToggle.apply(this, arguments);
                persistGroupsFromDom();

                return result;
            };
        }

        // Bungkus toggle panel filter dengan cara yang sama.
        var originalFilterToggle = window.toggleFilter;
        if (typeof originalFilterToggle === 'function') {
            window.toggleFilter = function () {
                var result = originalFilterToggle.apply(this, arguments);

                try {
                    var panel = document.getElementById('filterPanel');
                    if (panel) {
                        window.localStorage.setItem(STORAGE_FILTER, isFilterOpen(panel) ? '1' : '0');
                    }
                } catch (e) {
                    // diabaikan
                }

                return result;
            };
        }

        // Jeda 250 ms: halaman ini membuka panel filter otomatis pada 100 ms
        // ketika ada filter aktif, jadi pemulihan harus berjalan setelahnya.
        window.setTimeout(applySavedFilterState, 250);
    });
})();
</script>
