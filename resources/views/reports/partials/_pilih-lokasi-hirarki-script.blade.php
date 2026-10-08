{{-- Skrip pemilih lokasi bertingkat (PT -> Departemen -> Area -> Section -> Alat).

     Berisi SATU definisi perilaku (window.pilihLokasiHirarki) yang dipakai dua
     tempat pada halaman detail laporan: pemilih lokasi UTAMA laporan dan panel
     tambah alat/lokasi tambahan. Dipusatkan di sini supaya aturan penelusuran
     hierarki, pemuatan alat, dan pengisian form tidak ditulis dua kali.

     Cara pakai: elemen pembungkus cukup diberi atribut data-pilih-hirarki plus
       data-url         : endpoint daftar anak (reports.locations.hirarki)
       data-jalur       : JSON id node terpilih (rantai root -> daun), boleh []
       data-mode        : "laporan" (isi lokasi laporan) atau "item" (tambah item)
       data-url-tambah  : endpoint penambah item (hanya mode item)
       data-label-alat / data-label-lokasi : teks tombol aksi

     Markupnya dipisah di _pilih-lokasi-hirarki-badan.blade.php dan elemen di
     dalamnya dicari lewat atribut data-peran. --}}
@once
@push('scripts')
<script>
    // Bikin satu pemilih lokasi bertingkat di dalam `wadah`.
    //
    // Satu permintaan ke endpoint mengembalikan node anak, alat yang menempel
    // pada node itu, dan ringkasan node induknya (termasuk area_id), sehingga
    // satu langkah pilih hanya perlu satu permintaan. Seluruh teks dibuat
    // dengan textContent (bukan innerHTML) agar nama lokasi dan deskripsi alat
    // dari database tidak bisa menyisipkan HTML.
    window.pilihLokasiHirarki = function (wadah) {
        var status     = wadah.querySelector('[data-peran="status"]');
        var pesan      = wadah.querySelector('[data-peran="pesan"]');
        var daftarAlat = wadah.querySelector('[data-peran="alat"]');
        var pemilih    = [
            wadah.querySelector('[data-peran="pt"]'),
            wadah.querySelector('[data-peran="departemen"]'),
            wadah.querySelector('[data-peran="area"]'),
            wadah.querySelector('[data-peran="section"]'),
        ];

        if (!pesan || !daftarAlat || pemilih.some(function (s) { return !s; })) {
            return null;
        }

        var url         = wadah.dataset.url;
        var urlTambah   = wadah.dataset.urlTambah || '';
        var mode        = wadah.dataset.mode === 'item' ? 'item' : 'laporan';
        var labelAlat   = wadah.dataset.labelAlat || 'Pakai alat ini';
        var labelLokasi = wadah.dataset.labelLokasi || 'Pakai lokasi ini';
        var label       = ['PT', 'Departemen', 'Area', 'Section'];
        var kotakCari   = wadah.querySelector('[data-peran="cari"]');
        var jalur       = [];
        var alat        = [];
        // Node yang sedang dibuka (dipakai penyaring alat) dan penunda pengetikan
        // supaya satu huruf tidak langsung menjadi permintaan ke server.
        var simpulAktif = null;
        var jedaSaring  = null;

        try {
            jalur = JSON.parse(wadah.dataset.jalur || '[]') || [];
        } catch (galat) {
            jalur = [];
        }

        // Komponen Alpine pemilik state lokasi laporan (reportDetail).
        function komponen() {
            if (!window.Alpine || typeof window.Alpine.$data !== 'function') {
                return null;
            }

            var host = document.querySelector('[x-data="reportDetail()"], [x-data="reportLocationManager()"]');

            return host ? window.Alpine.$data(host) : null;
        }

        // Tulis pesan bantuan di bawah pemilih.
        function tulisPesan(teks, jenis) {
            pesan.textContent = teks;

            if (jenis === 'sukses') {
                pesan.className = 'text-xs text-teal-700 mt-2';
            } else if (jenis === 'galat') {
                pesan.className = 'text-xs text-red-600 mt-2';
            } else {
                pesan.className = 'text-xs text-slate-400 mt-2';
            }
        }

        // Kosongkan isi dropdown mulai tingkat tertentu.
        function kosongkanPemilihDari(tingkat) {
            for (var i = tingkat; i < pemilih.length; i++) {
                pemilih[i].innerHTML = '';
                pemilih[i].disabled = true;

                var kosong = document.createElement('option');
                kosong.value = '';
                kosong.textContent = '- Pilih ' + label[i] + ' -';
                pemilih[i].appendChild(kosong);
            }
        }

        // Kosongkan dropdown DAN daftar alat.
        function kosongkanDari(tingkat) {
            kosongkanPemilihDari(tingkat);

            daftarAlat.innerHTML = '';
            daftarAlat.classList.add('hidden');
            alat = [];
        }

        // Isi satu dropdown dengan daftar node dari server.
        function isiPemilih(select, nodes, terpilih) {
            select.innerHTML = '';

            var kosong = document.createElement('option');
            kosong.value = '';
            kosong.textContent = '- Pilih ' + label[pemilih.indexOf(select)] + ' -';
            select.appendChild(kosong);

            nodes.forEach(function (node) {
                var opsi = document.createElement('option');
                opsi.value = node.id;
                opsi.textContent = node.label;

                if (terpilih !== null && terpilih !== undefined && String(terpilih) === String(node.id)) {
                    opsi.selected = true;
                }

                select.appendChild(opsi);
            });

            select.disabled = nodes.length === 0;
        }

        // Tampilkan alat milik node induk + tombol aksi tingkat lokasi.
        //
        // @param {Array}       assets Daftar alat (sudah dipotong server)
        // @param {object|null} induk  Ringkasan node induk (null = belum dipilih)
        // @param {number}      jumlah Jumlah SELURUH alat node itu, dipakai untuk
        //                             memberi tahu bila daftarnya dipotong
        function gambarAlat(assets, induk, jumlah) {
            daftarAlat.innerHTML = '';
            alat = assets || [];

            if (kotakCari) {
                kotakCari.disabled = !induk;
            }

            if (!induk) {
                daftarAlat.classList.add('hidden');

                return;
            }

            var kepala = document.createElement('div');
            kepala.className = 'flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-slate-50 border-b border-slate-200';

            var judul = document.createElement('p');
            judul.className = 'text-xs text-slate-600';
            judul.textContent = 'Lokasi terpilih: ' + induk.label;
            kepala.appendChild(judul);

            var pakai = document.createElement('button');
            pakai.type = 'button';
            pakai.className = 'px-3 py-1.5 border border-slate-300 hover:bg-white text-slate-700 text-xs font-medium rounded-lg transition-colors';
            pakai.textContent = labelLokasi;
            pakai.addEventListener('click', function () {
                pakaiLokasi(induk);
            });
            kepala.appendChild(pakai);

            daftarAlat.appendChild(kepala);

            // Keterangan jumlah: penting karena daftar alat per section bisa
            // panjang dan server hanya mengirim sebagian.
            var total = parseInt(jumlah, 10);
            var jumlahCatatan = document.createElement('p');
            jumlahCatatan.className = 'px-3 py-1.5 text-xs text-slate-400 border-b border-slate-100';

            if (!isNaN(total) && total > alat.length) {
                jumlahCatatan.textContent = 'Menampilkan ' + alat.length + ' dari ' + total
                    + ' alat. Pakai kotak penyaring alat untuk mencari yang lain.';
            } else {
                jumlahCatatan.textContent = alat.length + ' alat pada lokasi ini.';
            }

            daftarAlat.appendChild(jumlahCatatan);

            if (!alat.length) {
                var tidakAda = document.createElement('p');
                tidakAda.className = 'px-3 py-2 text-xs text-slate-400';
                tidakAda.textContent = 'Tidak ada alat yang cocok pada lokasi ini.';
                daftarAlat.appendChild(tidakAda);
            }

            // Daftar alat bisa memuat ratusan baris (satu section di data nyata
            // ada yang 426 alat), jadi isinya DIGULIR sendiri supaya halaman
            // tidak memanjang, dan barisnya DIKELOMPOKKAN per Equipment Type
            // (alat yang tipenya belum diisi masuk kelompok TANPA TIPE).
            // Setiap kelompok dibiarkan TERTUTUP lebih dulu.
            var gulir = document.createElement('div');
            gulir.className = 'max-h-64 overflow-y-auto divide-y divide-slate-100';
            daftarAlat.appendChild(gulir);

            var kotakTipe = {};

            kelompokAlat(alat).forEach(function (kelompok) {
                var kotak = kotakKelompokAlat(kelompok);

                kotakTipe[kelompok.tipe] = kotak.isi;
                gulir.appendChild(kotak.bungkus);
            });

            alat.forEach(function (item, posisi) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-2 px-3 py-2 border-b border-slate-100 last:border-b-0';

                var info = document.createElement('div');
                info.className = 'min-w-0';

                var kode = document.createElement('p');
                kode.className = 'text-sm font-medium text-slate-700 font-mono';
                kode.textContent = item.tech_ident_no || item.equipment_no || ('#' + item.id);
                info.appendChild(kode);

                var ket = document.createElement('p');
                ket.className = 'text-xs text-slate-400 truncate';
                ket.textContent = item.description || 'Tanpa deskripsi';
                info.appendChild(ket);

                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.className = 'shrink-0 px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-medium rounded-lg transition-colors';
                tombol.textContent = labelAlat;
                tombol.addEventListener('click', function () {
                    pakaiAlat(posisi);
                });

                baris.appendChild(info);
                baris.appendChild(tombol);
                var wadahBaris = kotakTipe[tipeAlat(item)] || gulir;

                wadahBaris.appendChild(baris);
            });

            daftarAlat.classList.remove('hidden');
        }

        // Label kelompok alat yang Equipment Type-nya belum diisi (penamaan ini
        // mengikuti pemilih alat pada wizard Telegram).
        var TANPA_TIPE = 'TANPA TIPE';

        // Tipe alat satu baris. Kolom Equipment Type yang kosong dianggap
        // kelompok TANPA TIPE supaya alatnya tetap bisa dipilih.
        //
        // @param {object} item Data alat dari server (punya equipment_type)
        // @return {string} Label tipe alat
        function tipeAlat(item) {
            var tipe = String(item.equipment_type || '').trim();

            return tipe !== '' ? tipe : TANPA_TIPE;
        }

        // Kelompokkan daftar alat per Equipment Type. Kelompok TANPA TIPE
        // diletakkan paling akhir supaya alat yang tipenya sudah diisi terbaca
        // lebih dulu.
        //
        // @param {Array} daftar Daftar alat dari server
        // @return {Array} Daftar { tipe, alat } yang sudah terurut
        function kelompokAlat(daftar) {
            var urutan = [];
            var peta   = {};

            daftar.forEach(function (item) {
                var tipe = tipeAlat(item);

                if (!peta[tipe]) {
                    peta[tipe] = { tipe: tipe, alat: [] };
                    urutan.push(tipe);
                }

                peta[tipe].alat.push(item);
            });

            urutan.sort(function (a, b) {
                if (a === TANPA_TIPE || b === TANPA_TIPE) {
                    return a === b ? 0 : (a === TANPA_TIPE ? 1 : -1);
                }

                return a.localeCompare(b);
            });

            return urutan.map(function (tipe) {
                return peta[tipe];
            });
        }

        // Kotak satu kelompok tipe alat: judulnya bisa diklik untuk membuka atau
        // menutup isinya, dan kelompok dibiarkan TERTUTUP lebih dulu supaya
        // daftar alat yang panjang tidak memanjangkan halaman.
        //
        // @param {object} kelompok Data { tipe, alat }
        // @return {object} { bungkus, isi } — `isi` diisi baris alat oleh pemanggil
        function kotakKelompokAlat(kelompok) {
            var bungkus = document.createElement('div');

            var tombol = document.createElement('button');
            tombol.type = 'button';
            tombol.setAttribute('aria-expanded', 'false');
            tombol.className = 'w-full flex items-center justify-between gap-2 px-3 py-2 text-left transition-colors hover:bg-slate-50';

            var kiri = document.createElement('span');
            kiri.className = 'flex items-center gap-2 min-w-0';

            var panah = document.createElement('span');
            panah.className = 'shrink-0 inline-block text-slate-400 text-xs transition-transform';
            panah.textContent = '>';
            kiri.appendChild(panah);

            var judul = document.createElement('span');
            judul.className = 'text-xs font-medium text-slate-700 truncate';
            judul.textContent = kelompok.tipe;
            kiri.appendChild(judul);
            tombol.appendChild(kiri);

            var jumlah = document.createElement('span');
            jumlah.className = 'shrink-0 text-xs text-slate-400';
            jumlah.textContent = kelompok.alat.length + ' alat';
            tombol.appendChild(jumlah);

            var isi = document.createElement('div');
            isi.className = 'hidden divide-y divide-slate-100';

            tombol.addEventListener('click', function () {
                // classList.toggle mengembalikan true saat kelas 'hidden' kini
                // TERPASANG, jadi kelompok terbuka ketika nilainya false.
                var terbuka = !isi.classList.toggle('hidden');

                tombol.setAttribute('aria-expanded', terbuka ? 'true' : 'false');
                panah.style.transform = terbuka ? 'rotate(90deg)' : '';
            });

            bungkus.appendChild(tombol);
            bungkus.appendChild(isi);

            return { bungkus: bungkus, isi: isi };
        }


        // Aksi tombol pada tingkat lokasi.
        function pakaiLokasi(induk) {
            if (mode === 'item') {
                tambahItem({ funcloc_id: induk.id });

                return;
            }

            var alpine = komponen();

            if (alpine && typeof alpine.terapkanLokasi === 'function') {
                alpine.terapkanLokasi({
                    id: induk.id,
                    area_id: induk.area_id,
                    code: induk.code,
                    label: induk.label,
                });
            } else {
                isiManual({ funcloc_id: induk.id, area_id: induk.area_id, asset_id: '' });
            }

            if (status) {
                status.textContent = 'Lokasi terpilih: ' + induk.label;
            }

            tulisPesan('Lokasi dipakai untuk laporan ini. Tekan "Simpan Perubahan" untuk menyimpan.', 'sukses');
        }

        // Aksi tombol pada baris alat.
        function pakaiAlat(posisi) {
            var item = alat[posisi];

            if (!item) {
                return;
            }

            if (mode === 'item') {
                tambahItem({ asset_id: item.id });

                return;
            }

            var muatan = {
                id: item.id,
                area_id: item.area_id,
                funcloc_id: item.funcloc_id,
                tech_ident_no: item.tech_ident_no,
                equipment_no: item.equipment_no,
                description: item.description,
                functional_loc: item.area_label,
                area_label: item.area_label,
            };

            var alpine = komponen();

            if (alpine && typeof alpine.terapkanAset === 'function') {
                alpine.terapkanAset(muatan);
            } else {
                isiManual({ funcloc_id: item.funcloc_id, area_id: item.area_id, asset_id: item.id });
            }

            if (status) {
                status.textContent = 'Alat terpilih: ' + (item.tech_ident_no || item.equipment_no || ('#' + item.id));
            }

            tulisPesan('Alat dipakai untuk laporan ini. Tekan "Simpan Perubahan" untuk menyimpan.', 'sukses');
        }

        // Mode item: kirim alat/lokasi terpilih sebagai item tambahan pekerjaan.
        //
        // @param {object} body  { asset_id } atau { funcloc_id }
        async function tambahItem(body) {
            if (!urlTambah) {
                return;
            }

            var meta = document.querySelector('meta[name="csrf-token"]');

            tulisPesan('Menambahkan...', 'biasa');

            try {
                var balasan = await fetch(urlTambah, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': meta ? meta.content : '',
                    },
                    body: JSON.stringify(body),
                });

                var data = await balasan.json();

                if (balasan.ok && data.success) {
                    // Daftar item digambar ulang oleh skrip pemilik panel item
                    // supaya pengelompokan per lokasi tetap sama dengan Blade.
                    if (typeof window.gambarDaftarAlatTambahan === 'function') {
                        window.gambarDaftarAlatTambahan(data.item);
                    }

                    tulisPesan(data.message || 'Item tambahan ditambahkan.', 'sukses');
                } else {
                    tulisPesan(data.message || 'Gagal menambah item tambahan.', 'galat');
                }
            } catch (galat) {
                tulisPesan('Gagal menghubungi server. Coba lagi.', 'galat');
            }
        }

        // Jaring pengaman bila komponen Alpine belum siap: isi langsung ke elemen form.
        function isiManual(nilai) {
            ['funcloc_id', 'area_id', 'asset_id'].forEach(function (id) {
                var isian = document.getElementById(id);

                if (!isian || nilai[id] === undefined) {
                    return;
                }

                isian.value = nilai[id] === null ? '' : String(nilai[id]);
                isian.dispatchEvent(new Event('change'));
            });
        }

        // Ambil anak satu node dari server (parentId 0 atau kosong = daftar PT).
        // Kata kunci `kunci` (opsional) menyaring daftar alat pada node itu.
        //
        // @param {number} parentId Node induk; 0 = daftar PT
        // @param {string} kunci    Kata kunci penyaring alat (boleh kosong)
        async function ambil(parentId, kunci) {
            var alamat  = url;
            var pemisah = '?';

            if (parentId) {
                alamat += pemisah + 'parent_id=' + encodeURIComponent(parentId);
                pemisah = '&';
            }

            if (kunci) {
                alamat += pemisah + 'q=' + encodeURIComponent(kunci);
            }

            var balasan = await fetch(alamat, { headers: { 'Accept': 'application/json' } });

            return balasan.json();
        }

        // Isi dropdown tingkat tertentu dengan anak dari parentId, lalu tampilkan
        // alat node induk (beserta tombol aksinya).
        //
        // @param {number}      tingkat   Indeks dropdown yang diisi (0 = PT)
        // @param {number}      parentId  Node induk; 0 = daftar PT
        // @param {number|null} terpilih  Id node yang harus terpilih lebih dulu
        async function bukaTingkat(tingkat, parentId, terpilih) {
            try {
                // Node yang dibuka berubah: kotak penyaring alat dikosongkan
                // supaya hasil penyaringan lama tidak tercampur dengan daftar
                // node yang baru.
                simpulAktif = parentId ? parseInt(parentId, 10) : null;

                if (kotakCari) {
                    kotakCari.value = '';
                }

                if (!parentId) {
                    kosongkanDari(0);

                    var akar = await ambil(0);
                    isiPemilih(pemilih[0], akar.nodes, terpilih);

                    return;
                }

                var data = await ambil(parentId);

                if (tingkat < pemilih.length) {
                    isiPemilih(pemilih[tingkat], data.nodes, terpilih);
                    kosongkanPemilihDari(tingkat + 1);
                }

                gambarAlat(data.assets, data.induk, data.jumlah_alat);
            } catch (galat) {
                tulisPesan('Gagal memuat hierarki lokasi. Periksa koneksi lalu coba lagi.', 'galat');
            }
        }

        // Perubahan pilihan: muat tingkat berikutnya + alat node yang dipilih.
        pemilih.forEach(function (select, tingkat) {
            select.addEventListener('change', function () {
                var nilai = parseInt(select.value, 10);

                if (!nilai) {
                    kosongkanDari(tingkat + 1);

                    if (status) {
                        status.textContent = 'PT → Departemen → Area → Section → Alat';
                    }

                    return;
                }

                if (status) {
                    status.textContent = label[tingkat] + ' dipilih: ' + select.options[select.selectedIndex].textContent;
                }

                bukaTingkat(tingkat + 1, nilai, null);
            });
        });

        // Penyaring alat pada node yang sedang dibuka (minimal 2 huruf baru
        // dikirim ke server; satu huruf hanya memuat daftar penuh lagi).
        if (kotakCari) {
            kotakCari.addEventListener('input', function () {
                clearTimeout(jedaSaring);

                jedaSaring = setTimeout(async function () {
                    if (!simpulAktif) {
                        return;
                    }

                    try {
                        var kunci = kotakCari.value.trim();
                        var data  = await ambil(simpulAktif, kunci.length >= 2 ? kunci : '');

                        gambarAlat(data.assets, data.induk, data.jumlah_alat);
                    } catch (galat) {
                        tulisPesan('Gagal menyaring alat. Coba lagi.', 'galat');
                    }
                }, 250);
            });
        }

        // Buka kembali pilihan yang sudah tersimpan (mode laporan) atau mulai
        // dari daftar PT (mode item).
        async function bukaAwal() {
            if (!jalur.length) {
                await bukaTingkat(0, 0, null);

                return;
            }

            var parentId = 0;

            for (var i = 0; i < jalur.length; i++) {
                await bukaTingkat(i, parentId, jalur[i]);
                parentId = jalur[i];
            }

            await bukaTingkat(pemilih.length, parentId, null);
        }

        bukaAwal();

        return { bukaAwal: bukaAwal };
    };

    // Siapkan seluruh pemilih yang ditandai data-pilih-hirarki pada halaman ini.
    (function () {
        function jalankan() {
            var daftar = document.querySelectorAll('[data-pilih-hirarki]');

            for (var i = 0; i < daftar.length; i++) {
                window.pilihLokasiHirarki(daftar[i]);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', jalankan);
        } else {
            jalankan();
        }
    })();
</script>
@endpush
@endonce
