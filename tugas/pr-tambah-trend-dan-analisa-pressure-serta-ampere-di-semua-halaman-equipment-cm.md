# Trend & Analisa Pressure + Ampere di Semua Halaman Equipment CM

## Permintaan user (14/10/2026)

Pada halaman detail equipment CM (contoh: `/cm/equipment/6811P05`) baru ada
trend vibrasi dan temperature. Diminta:

1. tambahkan trend untuk **pressure** dan **ampere** juga;
2. bagian "Trend & Analisa" harus ada di **setiap** halaman equipment;
3. pada bagian **Riwayat Pembacaan**, tambahkan kolom untuk **pressure**.

## Apa yang berubah

- **Chart baru di card "Trend & Analisa"** (Chart.js, gaya sama dengan chart
  vibrasi/temperature yang sudah ada):
  - `Trending Ampere` (canvas `ampereChart`);
  - `Trending Discharge Pressure` (canvas `pressureChart`).
  Masing-masing hanya dirender bila parameter itu benar-benar punya pembacaan
  pada rentang tahun yang dipilih (`range`), sehingga tidak muncul kotak kosong.
- **Panel "Analisa Pressure & Ampere"** — SELALU tampil di halaman equipment
  mana pun (termasuk equipment yang belum punya pembacaan sama sekali, dengan
  keterangan "Belum ada data"). Isi per parameter:
  - kalimat analisa otomatis (naik/turun signifikan, atau stabil/relatif);
  - nilai terakhir, rata-rata, minimum, maksimum;
  - rentang tanggal, jumlah titik data, dan persentase perubahan.
- **Tabel "Riwayat Pembacaan"** kini punya kolom **Pressure**
  (`discharge_pressure`, 2 desimal), tepat setelah kolom Ampere. `colspan` baris
  "belum ada data" ikut disesuaikan (9 -> 10).
- **Dua method baru** di `CmController`:
  - `analisaParameterTrend()` — hitung analisa per parameter dari data chart,
    dengan ambang batas dari config;
  - `pesanAnalisaParameter()` — susun kalimat analisa berbahasa Indonesia.
- **Config baru** `cm.process_parameter_insight` (`min_data_points` = 3,
  `significant_change_pct` = 20) supaya ambang batas mudah diubah tanpa
  menyentuh kode. Persen dibulatkan lebih dulu sebelum dibandingkan dengan
  ambang, supaya angka yang tampil di layar selalu sama dengan angka yang
  dipakai menilai signifikan.
- Karena semua equipment memakai satu view yang sama
  (`resources/views/cm/equipment-show.blade.php`), bagian ini otomatis muncul di
  SELURUH halaman equipment tanpa perlu mengubah halaman satu per satu.

## Catatan desain

Analisa pressure & ampere sengaja bersifat deskriptif (tidak memakai batas
Alarm/Danger) karena kedua parameter ini belum punya ambang resmi seperti
vibrasi (ISO 10816-3). Nilai 0/NULL dianggap "tidak diambil" dan tidak dihitung,
sama seperti perlakuan data vibrasi pada chart yang sudah ada.

## Checklist

- [x] Chart ampere & discharge pressure muncul dengan data (id canvas
      `ampereChart`/`pressureChart`, data ikut terkirim ke Chart.js).
- [x] Panel "Analisa Pressure & Ampere" tampil di equipment berisi data, dan
      tetap tampil di equipment tanpa pembacaan (keterangan "Belum ada data").
- [x] Kolom Pressure tampil di tabel Riwayat Pembacaan beserta nilainya.
- [x] Ambang "signifikan" memakai angka persen yang sudah dibulatkan.
- [x] Test otomatis hijau.

## Bukti uji

- `php artisan test --filter=CmEquipmentTrendPressureAmpereTest`
  -> 6 test lulus, 32 assertion.
- `php artisan test` (seluruh suite)
  -> 597 test lulus, 2853 assertion, 0 gagal.
- Render halaman nyata dari data DEV, equipment `6811P05` (8 pembacaan, 7
  ampere, 8 pressure):
  - "Analisa Pressure & Ampere": ADA, `ampereChart`: ADA, `pressureChart`: ADA;
  - pesan Ampere: "Tren Ampere relatif turun pada 197 hari terakhir
    (rata-rata 22.56, rentang 19.70 - 27.00).";
  - pesan Discharge Pressure: "Tren Discharge Pressure relatif naik pada 197
    hari terakhir (rata-rata 5.69, rentang 4.00 - 6.80).";
  - kolom `Pressure` pada tabel Riwayat Pembacaan: ADA (contoh nilai 6.00).

## Berkas yang disentuh

- `config/cm.php`
- `app/Http/Controllers/CmController.php`
- `resources/views/cm/equipment-show.blade.php`
- `tests/Feature/CmEquipmentTrendPressureAmpereTest.php` (baru)
- `tugas/erik.md`
