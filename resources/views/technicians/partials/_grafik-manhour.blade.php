{{-- Grafik man hour harian pada halaman detail teknisi.

     Dipisah menjadi partial supaya halaman `show.blade.php` tidak perlu ditulis
     ulang setiap kali grafik disesuaikan.

     Aturan tampilan:
       - batang teal   = jam kerja NORMAL (menit di dalam jendela kerja dikurangi
         istirahat),
       - batang oranye = LEMBUR (di luar jendela kerja DITAMBAH seluruh jam pada
         hari libur/OFF, termasuk laporan yang jamnya kosong),
       - garis putus-putus = target harian menurut jadwal; digambar sebagai GARIS
         karena batang target membuat Chart.js menaruh kelompok batang kedua di
         sebelah kanan sehingga grafik menyesatkan,
       - tanggal hari libur/OFF yang ada laporannya diberi warna merah supaya
         kerja pada hari libur langsung terlihat,
       - grafik memuat 12 periode 26-25 dengan garis pemisah tiap periode,
       - klik batang membuka daftar laporan pada tanggal itu; popup Cek Overtime
         mengikuti periode yang sedang terlihat.

     Parameter:
       - $grafikHarian     = rincian harian satu tahun (12 periode 26-25)
       - $parameterLaporan = parameter filter halaman Laporan untuk tautan klik
--}}
@push('scripts')
@php
    $parameterLaporan = $parameterLaporan ?? [];

    // Data grafik disiapkan di server agar tidak bergantung pada parsing directive.
    $dataGrafik = array_map(function (array $hari) use ($parameterLaporan): array {
        $target = (int) $hari['target_menit'];
        $jumlahPekerjaan = (int) $hari['jumlah_pekerjaan'];

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
            'pekerjaan' => $jumlahPekerjaan,
            'catatan'   => (string) ($hari['catatan'][0] ?? ''),
            'sesi'      => (string) ($hari['sesi'] ?? ''),
            'mulai_sesi'=> (bool) ($hari['mulai_sesi'] ?? false),
            'url'       => $jumlahPekerjaan > 0
                ? route('reports.index', array_merge($parameterLaporan, ['tanggal' => $hari['tanggal']]))
                : null,
        ];
    }, $grafikHarian);

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
    var baruGeser = false;
    var abaikanPerbaruan = true;

    /**
     * Ubah menit menjadi jam dengan satu angka di belakang koma.
     *
     * @param {number} menit Menit yang ditampilkan
     * @returns {number} Jam (mis. 480 menjadi 8)
     */
    function jam(menit) {
        return Math.round(((menit || 0) / 60) * 10) / 10;
    }

    // Garis pemisah vertikal di awal setiap sesi 26-25. Indeks 0 dilewati
    // karena berimpit dengan sumbu Y.
    var pluginPemisahSesi = {
        id: 'pemisahSesi',
        afterDraw: function (grafik) {
            var sumbuX = grafik.scales.x;
            var area   = grafik.chartArea;

            if (!sumbuX || !area) {
                return;
            }

            var konteks = grafik.ctx;
            konteks.save();
            konteks.strokeStyle = 'rgba(100, 116, 139, 0.35)';
            konteks.lineWidth = 1;
            konteks.setLineDash([4, 4]);

            dataHarian.forEach(function (baris, indeks) {
                if (indeks === 0 || !baris.mulai_sesi) {
                    return;
                }

                var posisi = sumbuX.getPixelForValue(indeks);
                konteks.beginPath();
                konteks.moveTo(posisi, area.top);
                konteks.lineTo(posisi, area.bottom);
                konteks.stroke();
            });

            konteks.restore();
        }
    };

    var grafikManHour = new Chart(kanvas, {
        type: 'bar',
        plugins: [pluginPemisahSesi],
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
            onHover: function (kejadian, elements) {
                if (!kejadian.native || !kejadian.native.target) {
                    return;
                }

                var baris = elements.length ? dataHarian[elements[0].index] : null;
                kejadian.native.target.style.cursor = (baris && baris.url) ? 'pointer' : 'default';
            },
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

                            if (baris.url) {
                                keterangan.push('Klik untuk membuka daftar laporan');
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

    var wadah = document.getElementById('grafikManHourScroll');

    /**
     * Periode 26-25 yang berada di tengah tampilan grafik.
     *
     * @returns {string|null} Kunci sesi (tanggal mulai sesi)
     */
    function sesiTerlihat() {
        if (!wadah || !dataHarian.length) {
            return null;
        }

        var indeks = Math.floor((wadah.scrollLeft + (wadah.clientWidth / 2)) / 42);
        indeks = Math.max(0, Math.min(dataHarian.length - 1, indeks));

        return dataHarian[indeks] ? dataHarian[indeks].sesi : null;
    }

    /**
     * Tampilkan bagian Cek Overtime untuk sesi yang sedang terlihat.
     *
     * @returns {void}
     */
    function perbaruiLemburTerlihat() {
        var sesi = sesiTerlihat();

        if (!sesi) {
            return;
        }

        var bagian = document.querySelector('[data-lembur-sesi="' + sesi + '"]');

        if (!bagian) {
            return;
        }

        document.querySelectorAll('[data-lembur-sesi]').forEach(function (el) {
            el.classList.toggle('hidden', el !== bagian);
        });

        var label        = bagian.getAttribute('data-label') || '';
        var totalJam     = bagian.getAttribute('data-total-jam') || '0,0';
        var totalTanggal = bagian.getAttribute('data-jumlah-tanggal') || '0';
        var badge        = document.getElementById('lembur-badge');

        if (badge) {
            badge.textContent = 'Lembur: ' + totalJam + ' j';
            badge.title = 'Periode ' + label;
        }

        var periode = document.getElementById('lembur-periode');
        if (periode) {
            periode.textContent = label;
        }

        var totalJamEl = document.getElementById('lembur-total-jam');
        if (totalJamEl) {
            totalJamEl.textContent = totalJam;
        }

        var totalTanggalEl = document.getElementById('lembur-total-tanggal');
        if (totalTanggalEl) {
            totalTanggalEl.textContent = totalTanggal;
        }
    }

    if (wadah) {
        var indeksFokus = parseInt(wadah.getAttribute('data-indeks-fokus') || '0', 10);

        // Tampilkan tanggal HARI INI di sisi KANAN: posisi slot hari ini
        // dikurangi lebar tampilan, ditambah satu slot agar seluruh slot
        // tanggal hari ini terlihat saat halaman pertama dibuka.
        requestAnimationFrame(function () {
            var posisiHariIni = indeksFokus * 42;

            wadah.scrollLeft = Math.max(0, posisiHariIni - wadah.clientWidth + 42);

            // Badge dan bagian modal sudah berisi periode berjalan dari server.
            // Abaikan scroll programatik ini; setelah pengguna menggeser grafik,
            // periode di tengah tampilan yang menentukan isi Cek Overtime.
            setTimeout(function () { abaikanPerbaruan = false; }, 250);
        });

        var aktif = false;
        var tertangkap = false;
        var mulaiX = 0;
        var mulaiScroll = 0;

        // Klik ditangani di wadah, bukan di canvas: drag memakai pointer capture
        // sehingga event click bisa berpindah target ke wadah. Dengan begitu klik
        // batang tetap membuka laporan tanpa mengganggu drag.
        wadah.addEventListener('click', function (kejadian) {
            if (baruGeser) {
                return;
            }

            var elemen = grafikManHour.getElementsAtEventForMode(
                kejadian,
                'nearest',
                { intersect: true },
                false
            );
            var baris = elemen.length ? dataHarian[elemen[0].index] : null;

            if (baris && baris.url) {
                window.location.href = baris.url;
            }
        });

        wadah.addEventListener('pointerdown', function (kejadian) {
            if (kejadian.pointerType !== 'mouse') {
                return;
            }

            aktif = true;
            tertangkap = false;
            baruGeser = false;
            mulaiX = kejadian.clientX;
            mulaiScroll = wadah.scrollLeft;
            wadah.classList.add('cursor-grabbing');
            wadah.classList.remove('cursor-grab');
        });

        wadah.addEventListener('pointermove', function (kejadian) {
            if (!aktif) {
                return;
            }

            if (Math.abs(kejadian.clientX - mulaiX) > 5) {
                baruGeser = true;

                // Pointer capture baru dipasang setelah gerakan melewati batas
                // drag; klik biasa tidak perlu capture.
                if (!tertangkap && wadah.setPointerCapture) {
                    wadah.setPointerCapture(kejadian.pointerId);
                    tertangkap = true;
                }
            }

            wadah.scrollLeft = mulaiScroll - (kejadian.clientX - mulaiX);
        });

        function selesaiGeser() {
            if (!aktif) {
                return;
            }

            aktif = false;
            tertangkap = false;
            wadah.classList.add('cursor-grab');
            wadah.classList.remove('cursor-grabbing');

            if (baruGeser) {
                setTimeout(function () { baruGeser = false; }, 250);
            }
        }

        wadah.addEventListener('pointerup', selesaiGeser);
        wadah.addEventListener('pointercancel', selesaiGeser);
        wadah.addEventListener('pointerleave', selesaiGeser);

        var pembaruanTertunda = false;

        wadah.addEventListener('scroll', function () {
            if (abaikanPerbaruan || pembaruanTertunda) {
                return;
            }

            pembaruanTertunda = true;

            requestAnimationFrame(function () {
                pembaruanTertunda = false;
                perbaruiLemburTerlihat();
            });
        });
    }

    // Tabel man hour dimulai dari baris HARI INI (paling atas), sehingga
    // pengguna bisa menggulir ke atas untuk tanggal sesudahnya dan ke bawah
    // untuk tanggal sebelumnya.
    var tabelWadah = document.querySelector('[data-tanggal-fokus]');

    if (tabelWadah) {
        var tanggalFokus = tabelWadah.getAttribute('data-tanggal-fokus');
        var barisFokus   = tabelWadah.querySelector('tr[data-tanggal="' + tanggalFokus + '"]');

        if (barisFokus) {
            requestAnimationFrame(function () {
                var posisiBaris = barisFokus.getBoundingClientRect().top
                    - tabelWadah.getBoundingClientRect().top
                    + tabelWadah.scrollTop;

                tabelWadah.scrollTop = Math.max(0, posisiBaris - 40);
            });
        }
    }
})();
</script>
@endpush
