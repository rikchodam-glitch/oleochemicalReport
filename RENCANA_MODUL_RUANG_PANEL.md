# Modul Monitoring Ruang Panel

Dokumen ini mencatat rancangan dan hasil verifikasi modul **Monitoring Ruang
Panel**: halaman yang menampilkan suhu dan kelembapan tiap ruangan panel dari
perangkat ESP32 + sensor DHT11, dengan kemampuan menambah beberapa ruangan.

Modul pendampingnya (firmware perangkat dan alat bantu commissioning) berada di
folder `sensor-suhu-esp32/`.

---

## 1. Tujuan dan ruang lingkup

- Memantau suhu dan kelembapan ruangan panel tempat peralatan listrik berada.
- Menampilkan nilai terkini secara berkala dan grafik historis per ruangan.
- Menyediakan pendataan ruangan (tambah, ubah, hapus) supaya penambahan
  perangkat berikutnya tidak perlu mengubah kode.
- Tahap awal cukup satu ruangan, tetapi struktur data dan tampilan sudah siap
  untuk banyak ruangan.

## 2. Alur data (server yang menarik data)

```
[ESP32 + W5500 + DHT11]
        |  HTTP server kecil di perangkat (port 80)
        |  /api/status, /api/latest, /api/buffer?since=ID
        v
[Laravel: PanelSensorService]  (menarik data)
        |  php artisan panel:tarik  -> scheduler tiap menit
        |  sekaligus saat halaman panel dibuka (jeda cache 3 detik)
        v
[MySQL: panel_ruangan + panel_pembacaan]
        v
[Halaman /panel]  polling /panel/data tiap 5 detik
```

Dipilih pola tarik (pull) supaya **tidak ada port masuk yang perlu dibuka** di
Windows Firewall komputer server. Kelebihan lain: perangkat menyimpan buffer
60 pembacaan terakhir di RAM, sehingga data yang tertinggal saat server mati
tetap terambil setelah server hidup lagi.

## 3. Struktur data

### Tabel `panel_ruangan`

Daftar ruangan panel beserta perangkat sensornya.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Kunci utama |
| nama_ruangan | varchar(100) | Unik, contoh: Ruang Panel EPE 1 |
| pt_location, plant | varchar | Mengikuti struktur PT Location lalu Plant |
| keterangan | varchar(255) | Catatan bebas |
| ip_address, port | varchar(45), smallint | Alamat perangkat ESP32 (port bawaan 80) |
| mac_address | varchar(17) | Terisi otomatis dari perangkat saat penarikan pertama |
| aktif | boolean | Ikut ditarik data atau tidak |
| ambang_suhu_bawah, ambang_suhu_atas | decimal(5,2) | Ambang peringatan suhu per ruangan |
| status_terakhir | varchar(20) | online, offline, atau belum-diketahui |
| terakhir_terlihat | timestamp | Kapan pembacaan baru terakhir masuk |
| terakhir_ditarik | timestamp | Kapan perangkat terakhir dicoba dihubungi |
| sesi_boot | varchar(12) | Penanda sesi menyala perangkat |
| id_pembacaan_terakhir | int | Nomor pembacaan terakhir yang sudah dimiliki server |
| catatan | varchar(255) | Pesan kondisi terakhir (misalnya gagal dihubungi) |

### Tabel `panel_pembacaan`

Satu baris untuk satu pembacaan sensor.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint | Kunci utama |
| panel_ruangan_id | foreignId | Terhubung ke panel_ruangan, terhapus berantai |
| waktu | datetime | Waktu pengukuran (dihitung dari selisih uptime perangkat) |
| suhu, kelembapan | decimal(5,2) | Nilai pembacaan sensor |
| valid | boolean | Hasil pembacaan wajar atau tidak |
| sesi_boot | varchar(12) | Penanda sesi menyala perangkat |
| id_perangkat | int | Nomor pembacaan di sisi perangkat |
| sumber | varchar(10) | pull (ditarik server) atau push (kirim dari perangkat) |

Kunci unik `(panel_ruangan_id, sesi_boot, id_perangkat)` membuat data tidak
tersimpan dua kali walaupun penarikan dilakukan berulang kali.

## 4. Berkas modul

Dibuat baru:

- `app/Models/PanelRuangan.php`, `app/Models/PanelPembacaan.php`
- `app/Services/PanelSensorService.php`
- `app/Http/Controllers/PanelRuanganController.php`
- `app/Console/Commands/PanelTarikCommand.php`
- `config/panel.php`
- `database/migrations/2026_09_14_000001_create_panel_ruangan_table.php`
- `database/migrations/2026_09_14_000002_create_panel_pembacaan_table.php`
- `resources/views/panel/index.blade.php`
- `public/vendor/chart.umd.min.js` (Chart.js versi lokal, agar halaman tetap
  bisa menggambar grafik walau komputer tidak ada koneksi internet)

Diubah (perubahan kecil):

- `routes/web.php` (tujuh rute modul di dalam grup middleware admin)
- `routes/console.php` (penjadwalan `panel:tarik` tiap menit)
- `resources/views/layouts/app.blade.php` (satu menu di grup
  Reliability & Maintenance)

## 5. Konfigurasi

Seluruh pengaturan ada di `config/panel.php` dan bisa ditimpa lewat `.env`:

| Kunci | Bawaan | Keterangan |
|---|---|---|
| `panel.pull.enabled` | true | Mengaktifkan penarikan terjadwal |
| `panel.pull.timeout_detik` | 3 | Batas waktu permintaan HTTP ke perangkat |
| `panel.pull.cache_detik` | 3 | Jeda antar penarikan saat halaman dibuka |
| `panel.pull.jeda_gagal_detik` | 30 | Jeda antar percobaan untuk perangkat yang sedang offline |
| `panel.ambang.suhu_atas` | 30 | Ambang peringatan bawaan ruangan baru |
| `panel.ambang.suhu_bawah` | 18 | Ambang peringatan bawaan ruangan baru |
| `panel.tampilan.interval_polling_detik` | 5 | Interval polling halaman |
| `panel.tampilan.jumlah_titik_grafik` | 60 | Jumlah titik pada grafik |
| `panel.tampilan.jumlah_baris_riwayat` | 20 | Jumlah baris tabel riwayat |
| `panel.maks_pembacaan_per_tarik` | 400 | Batas pembacaan yang diambil per penarikan (dibuat lebih besar dari buffer perangkat) |

Catatan: jeda `jeda_gagal_detik` penting saat perangkat belum terpasang. Tanpa
jeda itu, setiap polling halaman akan menunggu batas waktu HTTP (3 detik) untuk
tiap ruangan yang offline.

## 6. Rute dan endpoint

| Metode | URL | Nama rute | Fungsi |
|---|---|---|---|
| GET | `/panel` | `panel.index` | Halaman monitoring |
| GET | `/panel/data` | `panel.data` | Data JSON seluruh ruangan (dipakai polling) |
| POST | `/panel/ruangan` | `panel.ruangan.store` | Tambah ruangan |
| PUT | `/panel/ruangan/{id}` | `panel.ruangan.update` | Ubah ruangan |
| DELETE | `/panel/ruangan/{id}` | `panel.ruangan.destroy` | Hapus ruangan dan riwayatnya |
| POST | `/panel/ruangan/{id}/uji-koneksi` | `panel.ruangan.uji-koneksi` | Ping + status + pembacaan perangkat |
| POST | `/panel/ruangan/{id}/tarik` | `panel.ruangan.tarik` | Tarik data satu ruangan sekarang |

Semua rute berada di grup middleware `admin` (harus login sebagai admin atau
supervisor), sama seperti modul lain.

## 7. Cara pakai

1. Buka menu **Monitoring Ruang Panel** di grup Reliability & Maintenance,
   atau alamat langsung `http://oleochemicalreport.test/panel`.
2. Klik **Tambah Ruangan**, isi nama ruangan, alamat IP perangkat (contoh
   `192.168.41.240`), port `80`, dan ambang suhu. Simpan.
3. Data akan ditarik otomatis tiap menit oleh scheduler. Untuk memastikan saat
   itu juga, klik **Tarik** pada kartu ruangan atau **Tarik Sekarang** di atas.
4. Bila perangkat belum terpasang atau ada masalah, klik **Uji Koneksi**. Hasilnya
   memisahkan tiga hal: ping jaringan, status modul W5500, dan pembacaan sensor,
   sehingga bisa diketahui apakah masalahnya di jaringan, wiring modul, atau
   sensor.
5. Grafik dan tabel riwayat mengikuti ruangan yang dipilih pada daftar di atas
   grafik.

Perintah terminal yang tersedia:

```
php artisan panel:tarik              # tarik semua ruangan aktif
php artisan panel:tarik --ruangan=3  # tarik satu ruangan saja
php artisan schedule:list            # memastikan panel:tarik terjadwal
```

## 8. Hasil verifikasi (23 September 2026)

Verifikasi dilakukan tanpa perangkat keras, memakai simulator perangkat
(`sensor-suhu-esp32/tools/simulator_perangkat.py` pada port 8099) yang meniru
jawaban firmware ESP32.

| Pemeriksaan | Hasil |
|---|---|
| Migrasi dua tabel | Berhasil, `panel_ruangan` dan `panel_pembacaan` terbuat |
| Rute terdaftar | 7 rute (`php artisan route:list --name=panel`) |
| Kompilasi seluruh Blade | Berhasil (`php artisan view:cache`, tanpa galat) |
| Tambah ruangan lewat controller | HTTP 302 ke `/panel`, data tersimpan (validasi aktif) |
| Penarikan data dari perangkat | 5 pembacaan tersimpan, `{"status":"ok","baru":5}` |
| Uji koneksi | ping online, modul W5500 terdeteksi, link aktif, pembacaan 26,1 C / 57,4 % |
| Data JSON halaman | suhu 26,1 C, kelembapan 57,4 %, kondisi normal, online true |
| Render halaman penuh | 73.315 byte, menu sidebar, Chart.js lokal, modal, endpoint data ada |
| Anti-duplikat | Tarik berulang: 1 baru, lalu 0 baru, lalu 2 baru (sesuai laju 10 detik perangkat) |
| Jadwal otomatis | `panel:tarik` muncul di `schedule:list` sebagai `* * * * *` |
| Perilaku saat perangkat mati | Aman: suhu kosong, kondisi `tanpa-data`, status offline, catatan waktu kegagalan |
| Pengaman perangkat offline | Panggilan pertama 3,15 detik, panggilan berikutnya 0,01 detik (dilewati) |
| Tumpukan data (server sempat mati) | Batas 2 per penarikan: 6 pembacaan tersimpan berurutan id 1-6, tidak ada nomor terlewat |
| Kompilasi firmware 1.0.1 | 292.153 byte (22%) flash, 29.856 byte (9%) RAM, tanpa error |

Data awal yang sudah disiapkan: satu ruangan **Ruang Panel EPE 1**
(`192.168.41.240`, port 80, ambang 18 sampai 30 C, aktif). Ruangan ini akan
langsung terisi data begitu perangkat ESP32 terpasang dan firmware di-upload.

## 9. Catatan dan pengembangan lanjutan

- **Mencoba halaman tanpa perangkat keras**: jalankan simulator
  (`sensor-suhu-esp32\tools\simulator_perangkat.py --port 8099`), lalu ubah alamat
  IP ruangan menjadi `127.0.0.1` dan port menjadi `8099` lewat tombol Edit.
  Kembalikan ke `192.168.41.240` port `80` setelah perangkat asli terpasang.
- **Menambah ruangan baru**: cukup tambah baris di halaman panel dengan IP
  perangkat yang berbeda. Firmware saat ini memakai IP statis, jadi tiap
  perangkat perlu disetel `IPAddress ip(...)` yang berbeda sebelum di-upload.
- **Jalur kirim data dari perangkat (push)**: tidak dipakai. Firmware versi
  1.0.1 mematikan pengiriman mandiri lewat `#define AKTIFKAN_PUSH 0` sekaligus
  memperbesar buffer perangkat dari 60 menjadi 360 pembacaan (1 jam) supaya data
  tidak hilang saat aplikasi atau scheduler sempat mati. Bila kelak ingin
  menambah jalur push, tambahkan rute penerima data yang dikecualikan dari CSRF
  dan dilindungi token; kolom `sumber` pada `panel_pembacaan` sudah menyiapkan.
- **Retensi data**: pembacaan tersimpan terus bertambah. Bila nanti diperlukan,
  bisa ditambahkan perintah pembersih berkala (misalnya menyimpan 12 bulan
  terakhir) dengan pola yang sama seperti `storage:clean`.
- **Peringatan otomatis**: ambang suhu per ruangan sudah tersedia dan
  ditampilkan di kartu, tetapi belum mengirim notifikasi. Bila diperlukan,
  modul notifikasi IT yang ada (`it_notifications`) bisa dipakai sebagai contoh
  pola.
