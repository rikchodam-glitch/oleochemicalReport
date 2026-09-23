# Monitoring Suhu & Kelembapan - ESP32 (W5500 Ethernet) ke Server Lokal

Proyek ini adalah implementasi nyata dari rancangan di
`../rancangan-proyek-sensor-suhu-esp32.md`: ESP32 membaca sensor DHT11, mengirim
data lewat kabel LAN (modul Ethernet W5500) ke server Flask di komputer lokal,
lalu ditampilkan pada dashboard web dengan nilai terkini dan grafik historis.

Semua komponen (firmware, server, dashboard, alat bantu commissioning) sudah
ditulis lengkap dan sudah diuji. Bagian yang belum bisa diuji adalah komunikasi
ke perangkat keras asli, karena pada saat pengerjaan ESP32 belum terhubung
lewat LAN dan sensor belum terpasang di board.

## 1. Status verifikasi

| Bagian | Cara diuji | Hasil |
|---|---|---|
| Firmware ESP32 | Kompilasi Arduino CLI (`arduino_debug.exe --verify`, board `esp32doit-devkit-v1`) | Berhasil, 295.721 byte (22% flash), RAM 23.872 byte (7%) |
| Server Flask + SQLite | Server dijalankan, data uji dikirim lewat HTTP POST | Health OK, data tersimpan, dashboard 200, CSV 200 |
| Anti duplikat data | POST dengan id sama dua kali | Kiriman pertama tersimpan, kiriman kedua diabaikan |
| Jalur PULL | Server menarik data dari simulator perangkat selama 30 detik | 4 data masuk dengan id unik 1-4, tidak ada duplikat |
| Waktu pengukuran | Baris hasil pull dibandingkan jam server | Selisih 9,4 detik untuk data dengan `uptime_s = 10`, sesuai perhitungan |
| Dashboard | Halaman dimuat dan berkas grafik diperiksa | HTTP 200 (12.641 byte), Chart.js lokal 205.889 byte termuat, warna aksen #0E9E8E ada |
| Konektivitas perangkat asli | Belum diuji | Menunggu ESP32 terpasang di jaringan |

## 2. Struktur berkas

```
sensor-suhu-esp32/
├── config.json                        Pengaturan IP, port, dan interval
├── 01_jalankan_server.bat             Menjalankan server (sekalian pasang dependensi)
├── 02_cek_konektivitas.bat            Pemeriksaan konektivitas bertahap
├── 03_upload_firmware.bat             Upload firmware ke ESP32 lewat USB
├── 04_simulator.bat                   Simulator perangkat (uji tanpa perangkat keras)
├── firmware/ethernet_dht11/
│   └── ethernet_dht11.ino             Firmware ESP32 lengkap
├── server/
│   ├── server.py                      Server Flask + penyimpanan SQLite + penarik data
│   ├── requirements.txt               Dependensi Python (Flask)
│   ├── templates/dashboard.html       Dashboard web (Chart.js)
│   └── static/vendor/chart.umd.min.js Chart.js versi lokal (tidak butuh internet)
├── tools/
│   ├── check_connectivity.py          Pemeriksaan konektivitas (ping, HTTP, data uji)
│   ├── simulator_perangkat.py         Simulator ESP32 untuk pengujian
│   ├── test_receiver.py               Penerima uji untuk memisahkan masalah firewall
│   └── monitor_serial.ps1             Serial Monitor sederhana lewat PowerShell
└── setup/
    ├── izinkan_port_5000.ps1          Membuka port 5000 (jalankan sebagai Admin)
    └── 06_izinkan_port_firewall.bat   Pembungkus dengan hak Administrator
```

## 3. Perangkat keras dan wiring

| Komponen | Keterangan |
|---|---|
| Mikrokontroler | ESP32-WROOM-32 dev board (board Arduino: DOIT ESP32 DEVKIT V1) |
| Modul Ethernet | W5500 (RJ45 + magnetics HanRun HR961160C), bus SPI |
| Sensor | DHT11 modul 3 pin (+, out, -) |
| Daya | POE Splitter IEEE 802.3af/at keluaran 5V DC |

| Dari | Pin perangkat | Ke ESP32 |
|---|---|---|
| W5500 | VCC | 3V3 (JANGAN 5V) |
| W5500 | GND | GND |
| W5500 | SCK | GPIO18 |
| W5500 | MISO | GPIO19 |
| W5500 | MOSI | GPIO23 |
| W5500 | CS | GPIO5 |
| DHT11 | VCC | 3V3 |
| DHT11 | GND | GND |
| DHT11 | OUT | GPIO4 |
| POE Splitter | keluaran 5V+ | VIN ESP32 |
| POE Splitter | keluaran 5V- | GND ESP32 |

Catatan penting:

- VCC modul W5500 harus 3,3V supaya sejajar dengan level logika ESP32. Memberi
  5V ke pin VCC W5500 bisa merusak modul.
- Daya masuk ke board ESP32 lewat pin VIN (5V), bukan 3V3, karena regulator di
  board yang menurunkan 5V menjadi 3,3V untuk ESP32 dan modul W5500.
- Modul W5500 pada proyek ini tidak memakai pin RST dan INT, jadi kedua pin itu
  boleh dibiarkan tidak tersambung.
- Pin SPI yang dipakai adalah pin bawaan VSPI pada ESP32, yaitu SCK=18,
  MISO=19, MOSI=23, CS=5. Angka ini juga tertulis di berkas firmware.

## 4. Rencana alamat IP (hasil pemeriksaan jaringan nyata)

Pemeriksaan dilakukan langsung di komputer server (hostname `WS-EPE-MTC-IT`):

| Item | Nilai | Cara diketahui |
|---|---|---|
| Subnet kantor | 192.168.41.0/24 (VLAN 41) | `ipconfig` pada adapter Ethernet 2 |
| Alamat komputer server | 192.168.41.74 | IP statis manual di adapter |
| Gateway | 192.168.41.254 | terdaftar di adapter dan ping berhasil |
| IP untuk ESP32 | 192.168.41.240 | alamat .230-.253 dipastikan kosong |
| Port server monitoring | 5000 | dipastikan tidak dipakai proses lain |
| Port HTTP di ESP32 | 80 | dipakai jalur PULL |
| Port simulator | 8099 | 8080 sudah dipakai Laragon nginx |

Alamat .230 sampai .253 dipastikan kosong dengan ping satu per satu, sementara
daftar perangkat aktif di ARP berhenti di sekitar .220, jadi DHCP kemungkinan
memakai sampai .220. Memilih IP di luar rentang itu mengurangi risiko bentrok.

Bila ingin rapi secara administrasi, IP ESP32 ini bisa dicatat juga pada fitur
Daftar IP di aplikasi Laravel pada repo ini (tabel `ip_networks` dan
`ip_addresses`, keduanya sudah diverifikasi ada) supaya perangkat IoT masuk ke
inventaris yang sama dengan perangkat IT lain.

## 5. Konfigurasi

Semua pengaturan server ada di `config.json`:

| Kunci | Arti |
|---|---|
| `perangkat[].nama` | Nama perangkat, menjadi identitas di database dan dashboard |
| `perangkat[].ip` | IP perangkat ESP32 (harus sama dengan yang di firmware) |
| `perangkat[].port` | Port HTTP perangkat (80 sesuai firmware) |
| `perangkat[].aktif` | `false` untuk menonaktifkan penarikan data perangkat itu |
| `server.host` | Alamat bind server (`0.0.0.0` berarti menerima dari semua antarmuka) |
| `server.port` | Port server monitoring (5000) |
| `server.ip_lokal` | IP komputer ini, ditampilkan di dashboard |
| `mode_pull` | `true` untuk mengaktifkan penarikan data dari ESP32 |
| `interval_pull_detik` | Jarak waktu antar penarikan data |
| `interval_tampilan_detik` | Jarak refresh dashboard |
| `batas_data_grafik` | Jumlah titik data pada grafik |
| `database` | Nama berkas SQLite (dibuat otomatis) |

Pengaturan yang sama pada firmware ada di bagian atas `ethernet_dht11.ino`
(blok KONFIGURASI JARINGAN): MAC, IP perangkat, gateway, netmask, IP server
tujuan, dan port server.

## 6. Urutan commissioning (dari nol sampai jalan)

1. Pasang rangkaian mengikuti tabel wiring bagian 3. Pastikan VCC modul W5500 ke
   3V3 dan daya masuk ke pin VIN ESP32.
2. Hubungkan board ke komputer memakai kabel USB, lalu cek nomor port COM di
   Device Manager (perangkat uji di komputer ini muncul sebagai COM3, chip
   CP210x).
3. Upload firmware: `03_upload_firmware.bat COM3`
4. Buka Serial Monitor 115200 bps lewat `tools\monitor_serial.ps1` (atau lewat
   Arduino IDE). Yang harus terlihat: "Modul W5500 terdeteksi", "Kabel LAN
   terhubung (link ON)", "IP perangkat: 192.168.41.240", lalu baris pembacaan
   suhu dan kelembapan.
5. Cabut kabel USB dan pindahkan board ke posisi pemasangan. Bila memakai POE
   Splitter, cukup satu kabel LAN: POE masuk ke splitter, keluaran 5V ke ESP32,
   dan port LAN splitter ke modul W5500.
6. Jalankan server: `01_jalankan_server.bat`
7. Uji konektivitas: `02_cek_konektivitas.bat`
8. Buka dashboard di browser: `http://192.168.41.74:5000` (dari komputer lain di
   jaringan yang sama juga bisa).

Urutan ini penting: server dijalankan lebih dulu supaya langkah pemeriksaan
"server.py berjalan" ikut berhasil.

Catatan sejak firmware 1.0.1: pengiriman mandiri (PUSH) ke server.py dimatikan
secara bawaan lewat `#define AKTIFKAN_PUSH 0`, dan buffer perangkat diperbesar
dari 60 menjadi 360 pembacaan (1 jam pada pengambilan 10 detik). Alasannya:
yang dipakai sehari-hari adalah aplikasi Laravel pada repo induk yang menarik
data dari perangkat lewat port 80 (lihat bagian 13). Dengan PUSH mati, loop
perangkat tidak lagi tertahan menunggu koneksi ke port 5000 yang diblokir
firewall, sehingga permintaan penarikan selalu cepat dilayani. Langkah 6 sampai
8 di atas tetap berlaku bila ingin memakai dashboard Flask: ubah
`AKTIFKAN_PUSH` menjadi 1 lalu upload ulang firmware.

## 7. Endpoint yang tersedia

Pada server (port 5000):

| Method | Jalur | Fungsi |
|---|---|---|
| GET | `/` | Dashboard web |
| POST | `/api/data` | Menerima data dari ESP32 (jalur PUSH) |
| GET | `/api/data?limit=N` | Daftar pembacaan terakhir (lama ke baru) |
| GET | `/api/latest` | Pembacaan terakhir dan status perangkat |
| GET | `/api/perangkat` | Status semua perangkat (online/offline) |
| GET | `/api/health` | Kondisi server dan jumlah data |
| GET | `/api/export.csv?limit=N` | Unduh data dalam format CSV |

Pada perangkat ESP32 (port 80):

| Method | Jalur | Fungsi |
|---|---|---|
| GET | `/` | Halaman ringkas nilai terkini di perangkat |
| GET | `/api/status` | Status jaringan, W5500, uptime, statistik pengiriman |
| GET | `/api/latest` | Pembacaan terakhir di buffer perangkat |
| GET | `/api/buffer?since=ID` | Semua pembacaan dengan id lebih besar dari ID |

Endpoint `/api/buffer` membuat jalur PULL tetap aman: data yang sempat tertahan
di buffer RAM perangkat (60 pembacaan terakhir) tetap tersimpan ke database
begitu server hidup kembali, dan waktu pengukurannya dihitung dari selisih
uptime perangkat sehingga grafik tetap akurat.

## 8. Dua jalur data (penting untuk kasus firewall)

Firmware mengirim data sendiri (PUSH) sekaligus menyediakan data untuk ditarik
(PULL). Alasannya: di komputer ini Windows Firewall profil Domain aktif dan
port 5000 belum dibuka, sehingga jalur PUSH bisa gagal.

- Bila PUSH bisa masuk, data langsung tersimpan tanpa menunggu siklus pull.
- Bila PUSH diblokir, jalur PULL tetap membawa data yang sama ke database.
- Keduanya aman dijalankan bersamaan karena tabel `pembacaan` punya UNIQUE
  (perangkat, boot, id_perangkat), jadi tidak akan menghasilkan data ganda.

Untuk mengaktifkan PUSH: jalankan `setup\06_izinkan_port_firewall.bat` (harus
disetujui lewat UAC), atau minta bagian TI menambahkan aturan masuk TCP 5000.

## 9. Daftar masalah dan cara memperbaikinya

| Gejala | Kemungkinan penyebab | Tindakan |
|---|---|---|
| Serial Monitor: "W5500 TIDAK TERDETEKSI" | Kabel SPI salah/lepas, VCC modul belum dapat 3,3V | Cek ulang SCK=18, MISO=19, MOSI=23, CS=5, VCC=3V3, GND=GND |
| Serial Monitor: "link LAN mati" | Kabel LAN belum tersambung ke switch, atau keluaran POE belum masuk | Cek lampu link di port switch dan di modul W5500 |
| Tidak bisa ping 192.168.41.240 | IP bentrok, beda subnet, atau perangkat belum boot | Cek Serial Monitor, pastikan IP perangkat .240 dan netmask 255.255.255.0 |
| Serial Monitor: "Gagal baca DHT11" terus | Wiring DHT11 salah, modul rusak, atau jarak kabel terlalu panjang | Cek OUT=GPIO4, coba sensor lain, pendekkan kabel |
| Dashboard kosong padahal ESP32 hidup | Port 5000 diblokir firewall sehingga PUSH gagal, dan IP di `config.json` belum sesuai | Jalankan `tools\test_receiver.py` untuk memastikan jalur PUSH, atau andalkan PULL dengan IP yang benar |
| Baris "PUSH" tidak muncul di log server | Sejak firmware 1.0.1 pengiriman mandiri memang dimatikan (`AKTIFKAN_PUSH 0`) | Tidak perlu tindakan. Aplikasi Laravel menarik data lewat port 80. Ubah `AKTIFKAN_PUSH` ke 1 bila ingin memakai dashboard Flask |
| Server tidak bisa dijalankan, port terpakai | Ada proses lain di port 5000 | Cek `netstat -ano | findstr :5000`, ubah `server.port` bila perlu |
| Simulator gagal start | Port 8099/8080 sudah dipakai | Pakai `--port` lain, misalnya `--port 8181` |
| Data tidak bertambah setelah ESP32 mati dan hidup lagi | Belum menunggu siklus pull berikutnya | Tunggu maksimal `interval_pull_detik`, atau refresh dashboard |

Untuk memisahkan masalah firewall dari masalah aplikasi, jalankan
`tools\test_receiver.py` (matikan `server.py` dulu karena portnya sama). Bila di
penerima uji pun tidak ada data masuk, sedangkan `02_cek_konektivitas.bat`
menunjukkan perangkat bisa diakses, berarti masalahnya murni di jaringan/firewall
jalur masuk, bukan di `server.py`.

## 10. Catatan teknis yang ditemukan saat pengerjaan

Bagian ini penting supaya tidak salah langkah saat mengubah kode nanti.

1. Modul W5500 tidak bisa dipakai dengan library Ethernet bawaan core ESP32.
   Core ESP32 versi 2.0.9 yang terpasang di komputer ini tidak punya dukungan
   `ETH_PHY_W5500` (hanya Ethernet internal LAN8720/TLK110 dan sejenisnya).
   Karena itu firmware memakai library `Ethernet` 2.0.0 dari Arduino
   (`C:\Program Files (x86)\Arduino\libraries\Ethernet`).
2. Ada tiga library yang menyediakan `Ethernet.h`, jadi Arduino IDE
   menampilkan pesan "Multiple libraries were found". Yang benar-benar dipakai
   adalah versi 2.0.0 dari folder Arduino di Program Files, sedangkan library
   di folder sketchbook (`Ethernet-master` 2.0.2) berstatus "Not used". Kalau
   suatu saat muncul error aneh seputar `Ethernet`, jalankan kompilasi dengan
   opsi `--verbose` dan periksa baris "Using library Ethernet at version ...".
3. `EthernetServer` dari library Arduino tidak bisa langsung dipakai di core
   ESP32 karena `Server` bawaan core mendeklarasikan `begin(uint16_t port = 0)`
   sebagai fungsi virtual murni, sementara library Arduino hanya punya
   `begin()`. Firmware mengatasinya dengan kelas kecil `ServerHttpLokal` yang
   menambahkan override tersebut, jadi library tidak perlu diubah sama sekali.
4. Port 8080 di komputer ini dipakai Laragon nginx. Simulator perangkat karena
   itu memakai 8099.
5. `config.json` dibaca dengan `utf-8-sig`, jadi berkas yang tersimpan dengan
   BOM (misalnya hasil edit PowerShell atau Notepad tertentu) tetap bisa dibaca.
6. Flask dijalankan dengan `threaded=True` supaya layar dashboard tetap
   terlayani sementara thread lain sedang menarik data dari perangkat.

## 11. Menambah perangkat kedua dan seterusnya

1. Salin folder `firmware/ethernet_dht11` menjadi folder baru dengan nama
   berbeda (nama folder harus sama dengan nama berkas `.ino` di dalamnya).
2. Ubah `NAMA_PERANGKAT`, `MAC_PERANGKAT`, dan `IP_PERANGKAT` di firmware baru
   (MAC dan IP harus unik).
3. Upload ke board kedua, lalu tambahkan satu entri baru pada `perangkat` di
   `config.json`.
4. Server otomatis menarik data dari semua perangkat yang aktif, dan dashboard
   menampilkan seluruh perangkat pada tabel status. Grafik saat ini menampilkan
   gabungan data semua perangkat; kalau nanti perlu grafik per perangkat,
   tambahkan parameter `perangkat` pada endpoint `/api/data`.

## 12. Pengembangan lanjutan yang belum dikerjakan

- Notifikasi ke Telegram bila suhu atau kelembapan melewati ambang batas.
  Komputer ini sudah punya token Telegram di `.env` aplikasi Laravel, jadi
  pengirimannya bisa memakai bot yang sama.
- Login sederhana sebelum dashboard bisa diakses.
- Mode DHCP untuk ESP32 (sekarang memakai IP statis supaya mudah dilacak).
- Retry bertingkat dengan jeda lebih panjang saat server lama tidak bisa
  dihubungi (sekarang dicoba ulang tiap siklus kirim, maksimum 3 data per
  siklus).
- Filter rentang waktu pada grafik (harian atau mingguan).
- Grafik terpisah per perangkat bila jumlah perangkat sudah lebih dari satu.

Ekspor CSV sudah tersedia lewat tautan "Unduh CSV" di dashboard.

## 13. Integrasi dengan aplikasi Laravel (repo induk)

Perangkat ini dipakai oleh modul **Monitoring Ruang Panel** pada aplikasi
Laravel di repo induk. Halaman tersebut menampilkan suhu dan kelembapan tiap
ruangan panel, lengkap dengan grafik, riwayat, ambang peringatan, dan tombol uji
koneksi.

Bagaimana datanya mengalir:

- Aplikasi Laravel menjalankan `php artisan panel:tarik` tiap menit, dan juga
  menarik data saat halaman panel sedang dibuka.
- Penarikan memakai port 80 perangkat: `/api/status` lalu
  `/api/buffer?since=ID`. Karena server yang menghubungi perangkat, tidak ada
  port masuk yang perlu dibuka di firewall komputer ini.
- Waktu pengukuran dihitung dari selisih uptime perangkat, jadi jam pada
  perangkat tidak perlu disetel.
- Data tidak akan tersimpan dobel karena ada kunci unik (ruangan, sesi boot,
  nomor pembacaan).

Yang perlu disamakan antara perangkat dan aplikasi:

| Item | Nilai | Di mana mengubahnya |
|---|---|---|
| Alamat IP perangkat | 192.168.41.240 | `IP_PERANGKAT` di firmware, dan alamat IP ruangan di halaman panel |
| Port HTTP perangkat | 80 | `PORT_HTTP_LOKAL` di firmware, dan port ruangan di halaman panel |
| Nama perangkat | sensor-suhu-01 | `NAMA_PERANGKAT` di firmware (dipakai bila kelak ada jalur push) |

Tahap pemeriksaan yang disarankan:

1. Upload firmware, buka Serial Monitor, pastikan muncul "Modul W5500
   terdeteksi", "Kabel LAN terhubung (link ON)", dan "IP perangkat:
   192.168.41.240".
2. Buka `http://192.168.41.240/` dari browser di jaringan yang sama: harus tampil
   suhu dan kelembapan. Ini bukti perangkat sehat tanpa perlu aplikasi.
3. Buka halaman Monitoring Ruang Panel, klik Uji Koneksi pada kartu ruangan:
   hasilnya menampilkan ping, status modul, dan pembacaan terakhir.
4. Tunggu maksimal 1 menit (siklus penarikan otomatis) atau klik Tarik Sekarang,
   lalu pastikan angka suhu muncul di kartu dan pada grafik.

Nomor versi firmware pada Serial Monitor bisa dicocokkan dengan yang tampil di
kotak Uji Koneksi halaman panel, sehingga diketahui pasti versi firmware yang
sedang berjalan di perangkat.

