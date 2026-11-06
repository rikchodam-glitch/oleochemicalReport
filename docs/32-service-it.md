# Service IT

Halaman `/it-service` mencatat kasus service perangkat IT, baik yang berasal
dari laporan teknisi maupun entri manual. Aturan bisnis di bagian ini sudah
dikonfirmasi user 06/11/2026.

## Sumber data laporan

- Laporan yang dihitung adalah laporan **induk** yang asetnya punya
  `assets.it_asset_id` (baris cermin perangkat IT), apa pun nilai
  `jenis_pekerjaan`-nya.
- Baris salinan kolaborasi (`collaborator_of`) dan salinan multi alat
  (`multi_of`) diabaikan supaya satu pekerjaan tidak menjadi beberapa kasus.
- Hanya status laporan `continue` dan `completed` yang dipakai. Status
  `needs_review`/lainnya tidak membuka kasus.

## Aturan kasus dari laporan

- **Satu kasus per aset.** Laporan `continue` pertama membuka kasus baru.
  Selama kasus masih terbuka, laporan `continue` berikutnya tidak membuat kasus
  baru.
- **Tanggal masuk** = `reports.report_date` laporan continue yang membuka kasus.
- **Tanggal selesai** = `reports.report_date` laporan completed yang menutup
  kasus. Tanggal ini bukan `completed_at` (waktu status diubah).
- Laporan `completed` tanpa kasus terbuka **tidak** membuat kasus baru; dengan
  demikian aset yang hanya punya laporan done tidak masuk halaman Service IT.
- Jika laporan penutup diubah kembali menjadi `continue`, kasus dibuka lagi:
  tanggal selesai dikosongkan, status kembali `proses`, dan tanggal masuk tetap
  tanggal laporan continue pembuka.
- Jika tanggal laporan penutup diubah, tanggal selesai kasus ikut diperbarui.
- Sinkronisasi dijalankan otomatis setiap halaman Service IT dibuka
  (`ItServiceSyncService`), jadi data laporan lama langsung terbentuk tanpa
  perintah tambahan setelah deploy.

## Status dan tanggal

| Status   | Arti                         | Tanggal selesai |
|----------|------------------------------|-----------------|
| `proses` | Kasus masih berjalan         | Kosong          |
| `selesai`| Tuntas (otomatis dari done)  | Wajib           |
| `rusak`  | Perangkat dinyatakan rusak   | Wajib           |

- Kasus tanpa tanggal selesai berstatus `proses`. Dari halaman edit, kasus
  proses dapat ditandai `rusak` dan wajib mengisi tanggal selesai.
- Menandai rusak **tidak** mengubah `it_assets.status`; status master perangkat
  IT tetap seperti semula.
- Tanggal selesai tidak boleh sebelum tanggal masuk.

## Entri manual

- Tombol **Tambah Service IT** membuka modal pencarian perangkat.
- Perangkat dikelompokkan menurut lokasi (Functional Location, atau
  plant/department/location bila tidak ada), lalu jenis perangkat. Grup dapat
  dibuka/tutup; kotak pencarian memfilter tag, nama, IP, serial, merek, model,
  dan lokasi.
- Perangkat yang masih punya kasus terbuka (belum ada tanggal selesai) tidak
  ditawarkan dan ditolak server bila dipaksa, supaya tidak ada kasus ganda.
- Tanggal masuk default hari ini dan bisa diubah. Status default `proses`;
  status `selesai`/`rusak` mewajibkan tanggal selesai.
- Kasus manual boleh diubah penuh (tanggal masuk, tanggal selesai, status,
  catatan).
- Kasus dari laporan hanya boleh diubah catatannya; status `proses` boleh
  diubah menjadi `rusak` beserta tanggal selesai. Tanggal masuk mengikuti
  laporan dan tidak dapat diedit.

## Catatan

Setiap kasus punya satu catatan bebas yang boleh kosong dan dapat diisi/diedit
kapan saja. Tidak ada riwayat catatan terpisah.

## Filter dan tampilan

- Filter status: **Aktif (Proses + Rusak)** sebagai bawaan, Proses, Rusak,
  Selesai, dan Semua.
- Kasus yang sudah selesai tetap tampil bila filter Selesai/Semua dipilih.
- Ringkasan di atas halaman menampilkan jumlah proses, rusak, dan selesai.
- Urutan daftar: proses lebih dulu, lalu rusak, lalu selesai; di dalam status
  diurutkan tanggal masuk terbaru.

## Hak akses

Modul baru `it_service` di `config/akses.php` (grup **IT & Jaringan**) dengan
pola route `it-service.*` dan menu `it-service.index`. Hak akses diatur per
Bagian seperti modul lain; akun non-penulis hanya bisa melihat (middleware
`hanya_baca` menolak POST/PUT).

## Berkas utama

- `database/migrations/2026_11_06_000001_create_it_service_records_table.php`
- `app/Models/ItServiceRecord.php`
- `app/Services/ItServiceSyncService.php`
- `app/Http/Controllers/ItServiceController.php`
- `resources/views/it-service/index.blade.php`
- `tests/Feature/ItServiceTest.php`

## Deploy

- Ada migrasi baru (`it_service_records`); deploy menjalankannya otomatis.
- Tidak ada perintah sinkronisasi manual; kasus lama terbentuk saat halaman
  pertama kali dibuka setelah deploy.
