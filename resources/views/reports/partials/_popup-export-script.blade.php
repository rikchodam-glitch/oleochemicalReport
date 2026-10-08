{{--
    Skrip popup Export Laporan: buka/tutup, pilihan cepat rentang tanggal,
    dan pengalihan tujuan form ke rute Excel atau CSV sesuai format terpilih.
    Ditulis memakai JavaScript biasa, mengikuti popup kolaborasi di halaman yang
    sama, supaya tidak menambah ketergantungan baru.
--}}
@push('scripts')
    <script>
        /** Rute unduhan untuk tiap format berkas. */
        var ruteExportLaporan = {
            excel: '{{ route('reports.export-excel') }}',
            csv: '{{ route('reports.export-csv') }}'
        };

        /**
         * Ubah objek Date menjadi teks YYYY-MM-DD memakai waktu lokal.
         * @param {Date} tanggal
         * @returns {string}
         */
        function tanggalExportLaporan(tanggal) {
            var bulan = String(tanggal.getMonth() + 1).padStart(2, '0');
            var hari = String(tanggal.getDate()).padStart(2, '0');

            return tanggal.getFullYear() + '-' + bulan + '-' + hari;
        }

        /**
         * Format berkas yang sedang dipilih di popup.
         * @returns {string} 'excel' atau 'csv'
         */
        function formatTerpilihExport() {
            var radio = document.querySelector('#form-export-laporan input[name="format"]:checked');

            return radio ? radio.value : 'excel';
        }

        /**
         * Arahkan form popup ke rute unduhan sesuai format terpilih.
         * @param {string} format
         */
        function terapkanFormatExport(format) {
            var form = document.getElementById('form-export-laporan');

            if (!form) {
                return;
            }

            form.action = ruteExportLaporan[format] || ruteExportLaporan.excel;
        }

        /**
         * Isi tanggal dari/sampai lewat tombol pilih cepat.
         * @param {string} kunci
         */
        function pilihCepatExport(kunci) {
            var dari = document.getElementById('export-date-from');
            var sampai = document.getElementById('export-date-to');
            var tanpaBatas = document.getElementById('export-tanpa-batas');

            if (!dari || !sampai || !tanpaBatas) {
                return;
            }

            var hariIni = new Date();
            var awalBulan = new Date(hariIni.getFullYear(), hariIni.getMonth(), 1);
            var bulanLalu = new Date(hariIni.getFullYear(), hariIni.getMonth() - 1, 1);
            var akhirBulanLalu = new Date(hariIni.getFullYear(), hariIni.getMonth(), 0);
            var tujuhHari = new Date(hariIni.getTime() - (6 * 24 * 60 * 60 * 1000));

            tanpaBatas.checked = (kunci === 'semua');

            if (kunci === 'semua') {
                dari.value = '';
                sampai.value = '';
            } else if (kunci === 'hari-ini') {
                dari.value = tanggalExportLaporan(hariIni);
                sampai.value = tanggalExportLaporan(hariIni);
            } else if (kunci === '7-hari') {
                dari.value = tanggalExportLaporan(tujuhHari);
                sampai.value = tanggalExportLaporan(hariIni);
            } else if (kunci === 'bulan-ini') {
                dari.value = tanggalExportLaporan(awalBulan);
                sampai.value = tanggalExportLaporan(hariIni);
            } else if (kunci === 'bulan-lalu') {
                dari.value = tanggalExportLaporan(bulanLalu);
                sampai.value = tanggalExportLaporan(akhirBulanLalu);
            }

            perbaruiCatatanPeriodeExport();
        }

        /**
         * Tampilkan keterangan periode yang akan diekspor (bantu pengguna).
         */
        function perbaruiCatatanPeriodeExport() {
            var catatan = document.getElementById('export-catatan-periode');
            var tanpaBatas = document.getElementById('export-tanpa-batas');
            var dari = document.getElementById('export-date-from');
            var sampai = document.getElementById('export-date-to');

            if (!catatan || !tanpaBatas || !dari || !sampai) {
                return;
            }

            if (tanpaBatas.checked) {
                catatan.textContent = 'Seluruh data akan diekspor (batas tanggal diabaikan).';
            } else if (dari.value && sampai.value) {
                catatan.textContent = 'Data ' + dari.value + ' sampai ' + sampai.value + ' akan diekspor.';
            } else if (dari.value || sampai.value) {
                catatan.textContent = dari.value
                    ? 'Data mulai ' + dari.value + ' sampai tanggal terakhir akan diekspor.'
                    : 'Data dari awal sampai ' + sampai.value + ' akan diekspor.';
            } else {
                catatan.textContent = 'Tanpa rentang: hanya tanggal aktif halaman ini yang diekspor.';
            }
        }

        /**
         * Sesuaikan pilihan "sertakan foto" dengan format berkas: gambar hanya
         * bisa disematkan pada Excel, jadi pada CSV kotaknya dimatikan supaya
         * pengguna tidak mengharapkan gambar pada berkas CSV.
         */
        function perbaruiCatatanFotoExport() {
            var kotak = document.getElementById('export-sertakan-foto');
            var baris = document.getElementById('export-baris-sertakan-foto');
            var catatan = document.getElementById('export-catatan-foto');

            if (!kotak || !baris || !catatan) {
                return;
            }

            var excel = formatTerpilihExport() === 'excel';

            kotak.disabled = !excel;

            if (!excel) {
                catatan.textContent = 'Format CSV hanya memuat tautan foto, gambar tidak disematkan.';
            } else if (kotak.checked) {
                catatan.textContent = 'Gambar foto akan disematkan pada berkas Excel.';
            } else {
                catatan.textContent = 'Tanpa centang ini berkas hanya memuat tautan foto.';
            }
        }

        /**
         * Buka popup export, sekaligus memilih format berkas awal.
         * @param {string} format 'excel' atau 'csv'
         */
        function bukaExport(format) {
            var modal = document.getElementById('modal-export-laporan');
            var radio = document.querySelector('#form-export-laporan input[name="format"][value="' + (format || 'excel') + '"]');

            if (!modal) {
                return;
            }

            if (radio) {
                radio.checked = true;
            }

            terapkanFormatExport(formatTerpilihExport());
            perbaruiCatatanPeriodeExport();
            perbaruiCatatanFotoExport();

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        /**
         * Tutup popup export.
         */
        function tutupExport() {
            var modal = document.getElementById('modal-export-laporan');

            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('form-export-laporan');
            var tanpaBatas = document.getElementById('export-tanpa-batas');
            var dari = document.getElementById('export-date-from');
            var sampai = document.getElementById('export-date-to');
            var tampilan = document.getElementById('export-tampilan');
            var barisGrub = document.getElementById('export-baris-grub');
            var sertakanFoto = document.getElementById('export-sertakan-foto');

            document.querySelectorAll('[data-export-format]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    terapkanFormatExport(formatTerpilihExport());
                    perbaruiCatatanFotoExport();
                });
            });

            document.querySelectorAll('[data-export-cepat]').forEach(function (tombol) {
                tombol.addEventListener('click', function () {
                    pilihCepatExport(tombol.getAttribute('data-export-cepat'));
                });
            });

            if (tanpaBatas) {
                tanpaBatas.addEventListener('change', function () {
                    if (tanpaBatas.checked && dari && sampai) {
                        dari.value = '';
                        sampai.value = '';
                    }

                    perbaruiCatatanPeriodeExport();
                });
            }

            [dari, sampai].forEach(function (input) {
                if (input) {
                    input.addEventListener('change', perbaruiCatatanPeriodeExport);
                }
            });

            if (tampilan && barisGrub) {
                tampilan.addEventListener('change', function () {
                    barisGrub.classList.toggle('hidden', tampilan.value !== 'shift');
                });
            }

            if (sertakanFoto) {
                sertakanFoto.addEventListener('change', perbaruiCatatanFotoExport);
            }

            perbaruiCatatanFotoExport();

            if (form) {
                form.addEventListener('submit', function () {
                    if (tanpaBatas && tanpaBatas.checked && dari && sampai) {
                        // "Seluruh data": rentang dikosongkan supaya server tidak
                        // tetap menyaring memakai tanggal yang tertinggal.
                        dari.value = '';
                        sampai.value = '';
                    } else if (dari && sampai && dari.value && sampai.value && dari.value > sampai.value) {
                        // Rentang terbalik dibetulkan sendiri, bukan ditolak.
                        var tukar = dari.value;
                        dari.value = sampai.value;
                        sampai.value = tukar;
                    }

                    terapkanFormatExport(formatTerpilihExport());
                    tutupExport();
                });
            }
        });

        document.addEventListener('keydown', function (peristiwa) {
            if (peristiwa.key === 'Escape') {
                tutupExport();
            }
        });
    </script>
@endpush
