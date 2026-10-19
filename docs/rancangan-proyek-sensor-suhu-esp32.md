# Rancangan Proyek: Sensor Suhu & Kelembapan ESP32 (Ethernet) → Dashboard Web Lokal

## 1. Ringkasan Proyek

Membangun sistem monitoring suhu & kelembapan menggunakan **ESP32** yang terhubung ke jaringan lokal via **modul Ethernet W5500** (bukan WiFi), membaca sensor **DHT11**, lalu mengirim data secara berkala ke sebuah **server Python (Flask)** yang berjalan di komputer dalam jaringan lokal yang sama. Server menyimpan data ke database dan menyajikan **dashboard web** dengan nilai real-time serta grafik historis.

Perangkat ESP32 mendapat daya dari **POE Splitter** (5V output) sehingga cukup satu kabel LAN untuk daya + data.

---

## 2. Komponen Hardware

| Komponen | Detail |
|---|---|
| Mikrokontroler | ESP32-WROOM-32 dev board |
| Modul Ethernet | W5500 (RJ45 + magnetics HanRun HR961160C), komunikasi via SPI |
| Sensor | DHT11 (suhu & kelembapan), modul 3 pin (+, out, -) |
| Power | POE Splitter (IEEE 802.3af/at → output 5V DC) |

### Wiring

| Dari | Pin | Ke ESP32 |
|---|---|---|
| W5500 | VCC | 3V3 |
| W5500 | GND | GND |
| W5500 | SCK | GPIO18 |
| W5500 | MISO | GPIO19 |
| W5500 | MOSI | GPIO23 |
| W5500 | CS | GPIO5 |
| DHT11 | VCC | 3V3 |
| DHT11 | GND | GND |
| DHT11 | OUT | GPIO4 |
| POE Splitter | 5V out | VIN & GND ESP32 |

---

## 3. Arsitektur Sistem

```
[Sensor DHT11] --(data digital)--> [ESP32 + W5500] --(HTTP POST JSON via kabel LAN)--> [Server Flask di PC]
                                                                                              |
                                                                                              v
                                                                                     [SQLite database]
                                                                                              |
                                                                                              v
                                                                                [Dashboard web (browser)]
```

- **ESP32** berperan sebagai *client*: membaca sensor tiap interval tertentu, lalu mengirim data via `HTTP POST` ke server.
- **Server Python (Flask)** berperan sebagai *receiver + storage + web server*: menerima data, menyimpan ke SQLite, dan menyajikan dashboard HTML yang mengambil data via endpoint JSON (`fetch` + polling berkala).
- Tidak menggunakan WiFi sama sekali — seluruh komunikasi lewat Ethernet kabel (LAN lokal).

---

## 4. Spesifikasi Firmware ESP32 (Arduino/C++)

**Bahasa/Platform:** Arduino framework untuk ESP32.

**Library yang dibutuhkan:**
- `Ethernet` (by Arduino) — mendukung chip W5500
- `DHT sensor library` (by Adafruit)
- `Adafruit Unified Sensor` (dependency dari DHT library)

**Konfigurasi:**
- MAC address custom (hardcoded, unik di jaringan)
- IP statis untuk ESP32 (`IPAddress ip`)
- IP tujuan server Flask (`IPAddress server`) + port (default `5000`)
- Interval pengiriman data: 10 detik (dapat dikonfigurasi via konstanta)

**Alur program:**
1. Inisialisasi `Ethernet.begin()` dengan MAC & IP statis, pastikan hardware W5500 terdeteksi (`Ethernet.hardwareStatus()`).
2. Inisialisasi sensor DHT11.
3. Pada setiap interval:
   - Baca suhu (`readTemperature()`) & kelembapan (`readHumidity()`).
   - Validasi hasil (skip jika `NaN`, print error ke Serial).
   - Bentuk payload JSON: `{"suhu": <float>, "kelembapan": <float>}`.
   - Kirim `HTTP POST` ke `http://<server_ip>:<port>/api/data` dengan header `Content-Type: application/json`.
   - Tutup koneksi setelah request selesai.
4. Panggil `Ethernet.maintain()` secara berkala di `loop()` untuk menjaga lease DHCP (jika pakai DHCP, opsional karena default pakai IP statis).
5. Cetak status (sukses/gagal kirim, IP device, dsb.) ke Serial Monitor untuk debugging.

**Referensi kode:** file `esp32_dht11_ethernet.ino` (sudah dibuat sebelumnya) berisi implementasi lengkap sesuai spesifikasi di atas — dapat dipakai langsung sebagai starting point atau referensi oleh AI agent.

---

## 5. Spesifikasi Server (Python/Flask)

**Bahasa/Platform:** Python 3, framework Flask.

**Dependency:** `Flask` (lihat `requirements.txt`)

**Struktur data (SQLite, file `data_sensor.db`):**

Tabel `pembacaan`:
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | INTEGER PRIMARY KEY AUTOINCREMENT | |
| waktu | TEXT | timestamp `YYYY-MM-DD HH:MM:SS` |
| suhu | REAL | derajat Celsius |
| kelembapan | REAL | persen (%) |

**Endpoint API:**

| Method | Path | Fungsi |
|---|---|---|
| POST | `/api/data` | Menerima JSON `{"suhu": float, "kelembapan": float}` dari ESP32, simpan ke DB dengan timestamp saat diterima. Return `{"status":"ok"}`. |
| GET | `/api/data?limit=N` | Mengembalikan N data terakhir (default 50) dalam format JSON array, urut dari lama ke baru. |
| GET | `/` | Menyajikan halaman dashboard HTML. |

**Dashboard (embedded HTML + JS di server, tanpa build step):**
- Tampilkan kartu nilai suhu & kelembapan terkini (angka besar).
- Grafik garis (line chart) histori suhu & kelembapan menggunakan **Chart.js** (via CDN `cdnjs.cloudflare.com`).
- Polling otomatis tiap 5 detik ke endpoint `GET /api/data` menggunakan `fetch()`, lalu update kartu & grafik.
- Tampilkan status "update terakhir" dan pesan error jika gagal fetch.

**Referensi kode:** file `server.py` (sudah dibuat sebelumnya) adalah implementasi lengkap sesuai spesifikasi di atas.

---

## 6. Struktur File Proyek

```
project-root/
├── esp32_dht11_ethernet.ino   # firmware ESP32
├── server.py                   # server Flask + dashboard
├── requirements.txt             # dependency Python
└── data_sensor.db               # database SQLite (dibuat otomatis saat server pertama dijalankan)
```

---

## 7. Kebutuhan Konfigurasi Sebelum Menjalankan

1. **IP statis ESP32** dan **IP komputer server** harus disesuaikan langsung di kode `esp32_dht11_ethernet.ino` (variabel `ip` dan `server`), mengikuti subnet jaringan lokal yang dipakai.
2. Port server default `5000` — pastikan tidak bentrok dengan aplikasi lain, dan firewall komputer mengizinkan koneksi masuk di port tersebut dari perangkat lain di jaringan.
3. ESP32 harus terhubung ke jaringan yang sama (kabel LAN, bisa lewat switch/router) dengan komputer yang menjalankan server.

---

## 8. Kemungkinan Pengembangan Lanjutan (Opsional)

Poin-poin berikut **belum diimplementasikan** dan bisa jadi task tambahan untuk AI agent:

- Ekspor data historis ke CSV/Excel dari dashboard.
- Notifikasi (email/telegram) jika suhu/kelembapan melewati ambang batas tertentu.
- Autentikasi login sebelum mengakses dashboard.
- Mode DHCP otomatis untuk IP ESP32 (saat ini pakai IP statis).
- Retry/backoff otomatis di ESP32 jika pengiriman data gagal (saat ini hanya log error).
- Grafik dengan filter rentang waktu (harian/mingguan).

---

## 9. Kriteria Sukses (Acceptance Criteria)

- [ ] ESP32 berhasil konek ke jaringan via W5500 dan tampil IP di Serial Monitor.
- [ ] Data sensor DHT11 terbaca valid (tidak NaN) dan terkirim tiap interval yang ditentukan.
- [ ] Server Flask menerima data, tersimpan dengan benar di SQLite.
- [ ] Dashboard `/` dapat diakses dari browser di komputer manapun dalam jaringan lokal yang sama.
- [ ] Nilai suhu & kelembapan terkini serta grafik historis ter-update otomatis tanpa perlu refresh manual.

---

## 10. Status Implementasi (diperbarui setelah pengerjaan)

Implementasi lengkap ada di folder `sensor-suhu-esp32/` pada repo ini, dengan
panduan pemakaian dan troubleshooting di `sensor-suhu-esp32-README.md`.

Yang sudah diverifikasi jalan:

- `Server Flask menerima data, tersimpan dengan benar di SQLite` - sudah diuji
  dengan data kiriman sungguhan lewat HTTP POST, termasuk uji anti duplikat.
- `Dashboard /` - sudah diuji membalas HTTP 200, memuat Chart.js versi lokal
  (tanpa internet), dan otomatis memperbarui tampilan tiap 5 detik.
- Firmware ESP32 berhasil dikompilasi untuk board DOIT ESP32 DEVKIT V1 dengan
  core ESP32 2.0.9 (295.721 byte flash, 23.872 byte RAM).
- Jalur pengambilan data oleh server (PULL) sudah diuji memakai simulator
  perangkat: data masuk tanpa duplikat dan waktu pengukurannya akurat.

Yang belum bisa diverifikasi karena perangkat keras belum terpasang penuh:

- ESP32 tampil IP di Serial Monitor lewat modul W5500 di jaringan kantor.
- Pembacaan sensor DHT11 asli dan pengiriman tiap interval dari perangkat nyata.

Penyimpangan kecil dari rancangan awal (beserta alasannya):

1. Modul Ethernet W5500 tidak bisa memakai library Ethernet bawaan core ESP32
   2.0.9 karena dukungan `ETH_PHY_W5500` belum ada di versi itu; firmware
   memakai library Ethernet 2.0.0 dari Arduino IDE.
2. Firmware ditambah HTTP server kecil di perangkat (port 80) supaya server bisa
   menarik data sendiri. Ini penting karena port 5000 belum diizinkan masuk oleh
   Windows Firewall di komputer server, sehingga jalur PUSH saja berisiko tidak
   jalan.
3. Tabel SQLite ditambah kolom `perangkat`, `boot`, `id_perangkat`, `valid`,
   `uptime_s`, dan `sumber` agar bisa menampung lebih dari satu perangkat serta
   mencegah data ganda saat PUSH dan PULL aktif bersamaan.
4. Ditambah dua alat bantu di luar rancangan: `tools/check_connectivity.py`
   (pemeriksaan bertahap saat commissioning) dan `tools/simulator_perangkat.py`
   (pengujian tanpa perangkat keras).
