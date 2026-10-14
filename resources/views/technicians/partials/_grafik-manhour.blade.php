{{-- Grafik man hour harian pada halaman detail teknisi.

     Dipisah menjadi partial supaya halaman `show.blade.php` (813 baris) tidak
     perlu ditulis ulang setiap kali grafik disesuaikan.

     Aturan tampilan (13/10/2026, disempurnakan 14/10/2026):
       - batang teal   = jam kerja NORMAL (menit di dalam jendela kerja dikurangi
         istirahat),
       - batang oranye = LEMBUR (di luar jendela kerja DITAMBAH seluruh jam pada
         hari libur/OFF, termasuk laporan yang jamnya kosong),
       - garis putus-putus = target harian menurut jadwal; digambar sebagai GARIS
         karena batang target membuat Chart.js menaruh kelompok batang kedua di
         sebelah kanan sehingga grafik menyesatkan,
       - tanggal hari libur/OFF yang ada laporannya diberi warna merah supaya
         kerja pada hari libur langsung terlihat.

     Parameter: $manHourHarian = daftar rincian harian dari ManHourService::harian().
--}}
@push('scripts')
@php
    // Data grafik disiapkan di server agar tidak bergantung pada parsing directive.
    $dataGrafik = array_map(function (array $hari): array {
        $target = (int) $hari['target_menit'];

        return [
            'tanggal'   => \Illuminate\Support\Carbon::parse($hari['tanggal'])->format('d/m'),
            'hari'      => mb_substr((string) $hari['hari'], 0, 3),
            'menit'     => (int) $hari['menit'],
            'lembur'    => (int) ($hari['lembur'] ?? 0),
            'target'    => $target,
            'shift'     => (string) $hari['label_shift'],
            // Hari tanpa target = hari libur/OFF (Minggu reguler, Sabtu-Minggu
            // reguler staff, atau kode shift 'x').
            'libur'     => $target <= 0,
            'pekerjaan' => (int) $hari['jumlah_pekerjaan'],
            'catatan'   => (string) ($hari['catatan'][0] ?? ''),
        ];
    }, $manHourHarian);

    $dataGrafikJson = json_encode($dataGrafik, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var kanvas = document.getElementById('grafikManHour');

    if (!kanvas || typeof Chart === 'undefined') {
        return;
    }

    // Data harian berasal dari perhitungan man hour di server.
    var dataHarian = {!! $dataGrafikJson !!};

    /**
     * Ubah menit menjadi jam dengan satu angka di belakang koma.
     *
     * @param {number} menit Menit yang ditampilkan
     * @returns {number} Jam (mis. 480 menjadi 8)
     */
    function jam(menit) {
        return Math.round(((menit || 0) / 60) * 10) / 10;
    }

    new Chart(kanvas, {
        type: 'bar',
        data: {
            labels: dataHarian.map(function (baris) { return baris.tanggal; }),
            datasets: [
                {
                    label: 'Jam kerja (jam)',
                    data: dataHarian.map(function (baris) { return jam(baris.menit); }),
                    backgroundColor: '#0E9E8E',
                    borderRadius: 4,
                    maxBarThickness: 26,
                    stack: 'realisasi'
                },
                {
                    // Segmen oranye: lembur (jam di luar jam kerja + seluruh jam
                    // pada hari libur/OFF), ditumpuk di atas jam kerja sehingga
                    // satu batang = total jam kerja hari itu.
                    label: 'Lembur (jam)',
                    data: dataHarian.map(function (baris) { return jam(baris.lembur); }),
                    backgroundColor: '#F59E0B',
                    borderRadius: 4,
                    maxBarThickness: 26,
                    stack: 'realisasi'
                },
                {
                    // Target digambar sebagai garis: kelompok batang kedua tidak
                    // diperlukan sehingga setiap hari hanya punya satu batang
                    // (jam kerja + lembur) yang bisa dibandingkan dengan target.
                    type: 'line',
                    label: 'Target (jam)',
                    data: dataHarian.map(function (baris) { return jam(baris.target); }),
                    borderColor: '#94A3B8',
                    backgroundColor: '#94A3B8',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    stepped: 'middle',
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 11 }, usePointStyle: true }
                },
                tooltip: {
                    callbacks: {
                        title: function (item) {
                            var baris = dataHarian[item[0].dataIndex];

                            return baris ? baris.hari + ' ' + baris.tanggal : '';
                        },
                        label: function (konteks) {
                            return konteks.dataset.label + ': ' + konteks.formattedValue + ' j';
                        },
                        afterBody: function (item) {
                            var baris = dataHarian[item[0].dataIndex];

                            if (!baris) {
                                return '';
                            }

                            var keterangan = [
                                'Total: ' + jam(baris.menit + baris.lembur) + ' j',
                                'Target: ' + jam(baris.target) + ' j',
                                'Jadwal: ' + baris.shift,
                                baris.pekerjaan + ' laporan'
                            ];

                            if (baris.catatan) {
                                keterangan.push(baris.catatan);
                            }

                            return keterangan;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (nilai) { return nilai + ' j'; }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        // Hari libur/OFF yang ada pekerjaannya ditandai merah:
                        // seluruh jamnya lembur, jadi tidak boleh tampak sebagai
                        // hari kerja biasa.
                        color: function (konteks) {
                            var baris = dataHarian[konteks.index];

                            if (baris && baris.libur && (baris.menit + baris.lembur) > 0) {
                                return '#E11D48';
                            }

                            return '#64748B';
                        }
                    }
                }
            }
        }
    });
})();
</script>
@endpush
