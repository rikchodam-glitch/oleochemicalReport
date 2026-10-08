{{-- Kartu "Teknisi Pelaksana" pada kolom kanan halaman detail laporan.

     Memuat profil ringkas teknisi, angka kinerja, dan grafik jumlah laporan per
     bulan (12 bulan terakhir) beserta komposisi jenis pekerjaan. Bila laporan
     berkolaborasi, seluruh teknisi yang terlibat tampil sebagai chip: mengklik
     chip lain langsung mengganti profil dan grafiknya tanpa memuat ulang
     halaman, dan tiap chip punya tautan ke halaman detail teknisi.

     Data disiapkan ReportController::siapkanKartuTeknisi() dan dihitung
     TechnicianKinerjaService (agregasi SQL; baris salinan multi alat tidak
     dihitung supaya angka tidak menggelembung).

     Parameter: $kartuTeknisi = ['pelaksana' => int|null, 'daftar' => array]. --}}
@if(!empty($kartuTeknisi['daftar']))
    <div class="bg-white rounded-xl border border-slate-200 p-6"
         x-data="kartuKinerja(@js($kartuTeknisi['daftar']), @js($kartuTeknisi['pelaksana']))">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 class="font-medium text-slate-900">Teknisi Pelaksana</h3>
            <span class="text-xs text-slate-400"
                  x-text="daftar.length > 1 ? (daftar.length + ' teknisi') : '1 teknisi'"></span>
        </div>

        {{-- Chip teknisi: diklik untuk berganti profil (saat laporan berkolaborasi). --}}
        <template x-if="daftar.length > 1">
            <div class="flex flex-wrap gap-2 mb-4">
                <template x-for="orang in daftar" :key="'chip-' + orang.id">
                    <button type="button"
                            @click="pilih(orang.id)"
                            :class="aktif === orang.id
                                ? 'bg-teal-600 text-white border-teal-600'
                                : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'"
                            class="px-2.5 py-1 text-xs font-medium rounded-full border transition-colors">
                        <span x-text="orang.nama"></span>
                    </button>
                </template>
            </div>
        </template>

        {{-- Profil teknisi yang sedang aktif --}}
        <div class="flex items-start gap-3">
            <div class="w-12 h-12 shrink-0 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center font-semibold">
                <span x-text="profil.inisial"></span>
            </div>

            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900 truncate" x-text="profil.nama"></p>
                <p class="text-xs text-slate-500">
                    <span x-text="profil.nik ? ('NIK ' + profil.nik) : 'NIK belum ada'"></span>
                    <span class="text-slate-300 mx-1">|</span>
                    <span x-text="profil.peran"></span>
                </p>
                <p class="text-xs text-slate-400 truncate">
                    <span x-text="profil.bagian"></span>
                    <span class="text-slate-300 mx-1">|</span>
                    <span x-text="profil.grup"></span>
                </p>
            </div>
        </div>

        {{-- Angka kinerja --}}
        <div class="grid grid-cols-2 gap-2 mt-4">
            <div class="rounded-lg border border-slate-200 p-3 text-center">
                <p class="text-lg font-bold text-slate-800" x-text="profil.total"></p>
                <p class="text-[11px] text-slate-500 mt-0.5">Total laporan</p>
            </div>
            <div class="rounded-lg border border-slate-200 p-3 text-center">
                <p class="text-lg font-bold text-teal-700" x-text="profil.selesai"></p>
                <p class="text-[11px] text-slate-500 mt-0.5">Selesai</p>
            </div>
            <div class="rounded-lg border border-slate-200 p-3 text-center">
                <p class="text-lg font-bold text-amber-600" x-text="profil.belum_selesai"></p>
                <p class="text-[11px] text-slate-500 mt-0.5">Belum selesai</p>
            </div>
            <div class="rounded-lg border border-slate-200 p-3 text-center">
                <p class="text-lg font-bold text-slate-800" x-text="profil.bulan_ini"></p>
                <p class="text-[11px] text-slate-500 mt-0.5">Bulan ini</p>
            </div>
        </div>

        {{-- Grafik jumlah laporan per bulan --}}
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-700">Jumlah laporan per bulan</p>
            <p class="text-[11px] text-slate-400 mb-2">12 bulan terakhir, tanpa baris salinan multi alat.</p>
            <div style="height: 150px;">
                <canvas x-ref="kanvas" height="150"></canvas>
            </div>
        </div>

        {{-- Komposisi jenis pekerjaan --}}
        <div class="mt-4">
            <p class="text-xs font-medium text-slate-700 mb-2">Komposisi jenis pekerjaan</p>

            <template x-if="profil.komposisi.length === 0">
                <p class="text-xs text-slate-400">Belum ada laporan tercatat.</p>
            </template>

            <div class="space-y-1.5">
                <template x-for="jenis in profil.komposisi" :key="'jenis-' + jenis.label">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-xs text-slate-600 min-w-0">
                            <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="'background-color: ' + jenis.color"></span>
                            <span class="truncate" x-text="jenis.label"></span>
                        </span>
                        <span class="text-xs font-medium text-slate-700" x-text="jenis.jumlah"></span>
                    </div>
                </template>
            </div>
        </div>

        <a :href="profil.tautan"
           class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium text-teal-700 hover:text-teal-800">
            Lihat detail teknisi
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>
        </a>
    </div>
@endif

@push('scripts')
{{-- Chart.js dipakai juga oleh halaman Teknisi; dimuat sekali di sini. --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Komponen Alpine kartu "Teknisi Pelaksana": memilih teknisi aktif dan
    // menggambar grafik jumlah laporan per bulan. Seluruh data sudah dihitung
    // server (TechnicianKinerjaService), jadi berpindah teknisi tidak memicu
    // permintaan jaringan baru dan tidak ada angka yang dihitung ulang di
    // peramban.
    function kartuKinerja(daftarAwal, pelaksanaAwal) {
        return {
            daftar: daftarAwal || [],
            aktif: (pelaksanaAwal === null || pelaksanaAwal === undefined)
                ? ((daftarAwal && daftarAwal.length) ? daftarAwal[0].id : null)
                : pelaksanaAwal,
            grafik: null,

            /**
             * Profil teknisi yang sedang aktif. Dikembalikan sebagai objek kosong
             * bila datanya belum siap supaya binding x-text tidak membaca
             * properti undefined.
             */
            get profil() {
                for (var i = 0; i < this.daftar.length; i++) {
                    if (this.daftar[i].id === this.aktif) {
                        return this.daftar[i];
                    }
                }

                return this.daftar.length ? this.daftar[0] : {};
            },

            init() {
                this.gambarGrafik();

                // Chart.js dimuat dari CDN; bila belum siap saat init, gambar
                // ulang setelah seluruh berkas halaman selesai dimuat.
                window.addEventListener('load', () => {
                    if (!this.grafik) {
                        this.gambarGrafik();
                    }
                });
            },

            /**
             * Jadikan satu teknisi sebagai profil aktif, lalu gambar ulang grafiknya.
             *
             * @param {number} id Id teknisi yang dipilih lewat chip
             */
            pilih(id) {
                this.aktif = id;
                this.gambarGrafik();
            },

            /**
             * Gambar grafik batang jumlah laporan 12 bulan terakhir untuk teknisi
             * aktif. Objek Chart lama dibuang lebih dulu supaya tidak menumpuk di
             * memori bila chip teknisi diklik berkali-kali.
             */
            gambarGrafik() {
                var kanvas = this.$refs.kanvas;

                if (!kanvas || typeof Chart === 'undefined') {
                    return;
                }

                var profil = this.profil;

                if (this.grafik) {
                    this.grafik.destroy();
                    this.grafik = null;
                }

                this.grafik = new Chart(kanvas, {
                    type: 'bar',
                    data: {
                        labels: profil.label_bulan || [],
                        datasets: [{
                            label: 'Jumlah laporan',
                            data: profil.jumlah_bulanan || [],
                            backgroundColor: '#0E9E8E',
                            borderRadius: 4,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { font: { size: 9 } } },
                            y: { beginAtZero: true, ticks: { precision: 0, font: { size: 9 } } },
                        },
                    },
                });
            },
        };
    }
</script>
@endpush
