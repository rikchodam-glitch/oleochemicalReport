{{-- Script grafik analitik perbaikan: tren per bulan (batang bertumpuk per
     jenis pekerjaan) + komposisi jenis pekerjaan (donut).
     Data disiapkan oleh App\Http\Controllers\Concerns\ResolvesReportAnalytics
     lewat AssetController::show() dan LocationController::show(). --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/**
 * Gambar grafik tren perbaikan dan komposisi jenis pekerjaan.
 */
(function () {
    if (typeof Chart === 'undefined') {
        return;
    }

    var tren = @json($trenPerbaikan);
    var komposisi = @json($komposisiTipe);
    var warnaCadangan = @json(array_values(\App\Support\WarnaJenisPekerjaan::PALET));

    var legend = document.getElementById('trenPerbaikanLegend');

    (tren.datasets || []).forEach(function (dataset) {
        if (!legend) {
            return;
        }

        var item = document.createElement('span');
        item.className = 'flex items-center gap-1.5';
        item.innerHTML = '<span class="w-3 h-3 rounded-full" style="background: ' + dataset.color + '"></span> ' + dataset.label;
        legend.appendChild(item);
    });

    /**
     * Susun dataset batang Chart.js dari data controller.
     *
     * @param {Array} datasets Data per jenis pekerjaan
     * @param {number} mulai   Indeks awal (untuk pemilih rentang bulan)
     * @returns {Array} Dataset siap dipakai Chart.js
     */
    function datasetBatang(datasets, mulai) {
        return datasets.map(function (dataset) {
            return {
                label: dataset.label,
                data: dataset.data.slice(mulai),
                backgroundColor: dataset.color,
                borderRadius: 4,
                maxBarThickness: 42,
            };
        });
    }

    var canvasTren = document.getElementById('trenPerbaikanChart');
    var chartTren = null;

    if (canvasTren && (tren.labels || []).length > 0) {
        chartTren = new Chart(canvasTren, {
            type: 'bar',
            data: { labels: tren.labels, datasets: datasetBatang(tren.datasets, 0) },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            footer: function (items) {
                                var total = items.reduce(function (jumlah, item) {
                                    return jumlah + item.parsed.y;
                                }, 0);

                                return 'Total: ' + total + ' perbaikan';
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 }, color: '#94A3B8', maxRotation: 45 } },
                    y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(148,163,184,0.15)' }, ticks: { font: { size: 10 }, color: '#94A3B8', precision: 0 } },
                },
            },
        });

        // Pemilih rentang: memotong data dari belakang tanpa memuat ulang halaman.
        var pemilih = document.getElementById('trenRentang');

        if (pemilih) {
            pemilih.addEventListener('change', function () {
                var batas = parseInt(this.value, 10) || 0;
                var mulai = batas > 0 ? Math.max(0, tren.labels.length - batas) : 0;

                chartTren.data.labels = tren.labels.slice(mulai);
                chartTren.data.datasets = datasetBatang(tren.datasets, mulai);
                chartTren.update();
            });
        }
    }

    var canvasKomposisi = document.getElementById('komposisiTipeChart');

    if (canvasKomposisi && (komposisi || []).length > 0) {
        new Chart(canvasKomposisi, {
            type: 'doughnut',
            data: {
                labels: komposisi.map(function (item) { return item.label; }),
                datasets: [{
                    data: komposisi.map(function (item) { return item.jumlah; }),
                    backgroundColor: komposisi.map(function (item, index) {
                        return item.color || warnaCadangan[index % warnaCadangan.length];
                    }),
                    borderWidth: 2,
                    borderColor: '#FFFFFF',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, color: '#64748B' } },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (konteks) {
                                var total = konteks.dataset.data.reduce(function (jumlah, nilai) {
                                    return jumlah + nilai;
                                }, 0);
                                var persen = total > 0 ? Math.round((konteks.parsed / total) * 100) : 0;

                                return konteks.label + ': ' + konteks.parsed + ' perbaikan (' + persen + '%)';
                            },
                        },
                    },
                },
            },
        });
    }
})();
</script>
