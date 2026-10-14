# Laporan Teknisi (Daftar Perbaikan) di Halaman Tiap Equipment CM

## Permintaan user (14/10/2026)

> "tambahkan juga ke dalam halaman tiap equipment status
> http://oleochemicalreport.test/cm/equipment-status, yaitu hasil laporan tiap
> teknisi, jadi laporan itu kan sudah ada tagging equipment nya, sekarang
> masukkan laporan-laporan tersebut ke dalam halaman tiap equipment nya, jgn
> sampai salah equipment nya, hanya yg ada tagging equipment nya saja yg
> dimasukkan di halaman ... kamu bisa ambil laporannya dari sini
> http://oleochemicalreport.test/assets , contohnya
> http://oleochemicalreport.test/assets/2052 di daftar perbaikan"

## Apa yang berubah

- **Card baru "Laporan Teknisi (Daftar Perbaikan)"** pada halaman detail
  equipment CM (`/cm/equipment/{tag}`). Karena semua equipment memakai satu view
  yang sama (`resources/views/cm/equipment-show.blade.php`), card ini otomatis
  muncul di SETIAP halaman equipment.
- Isi tabel (gaya sama dengan tabel "Daftar Perbaikan" halaman detail asset):
  No, Tanggal (+ tanggal kejadian), Deskripsi Pekerjaan (+ Penyebab), Teknisi
  (+ jumlah kolaborator), Durasi, Status, dan tombol **Detail** ke halaman
  laporan. Paginasi 10 baris memakai parameter `pageLaporan` supaya tidak
  berebut parameter `page` dengan tabel "Riwayat Pembacaan".
- Judul card menyebut tag equipment yang sedang dibuka + jumlah laporan, dan ada
  tautan **"Buka di Asset Management"** ke halaman asset bertag sama (bila
  asset-nya terdaftar) supaya mudah membandingkan dengan halaman asset.
- Keadaan kosong: "Belum ada laporan teknisi yang bertag equipment ini."

## Aturan pencocokan (inti permintaan: "jangan sampai salah equipment")

Sebuah laporan dianggap **bertag** equipment X HANYA bila salah satu benar:

1. `reports.equipment_tag` = `cm_equipment.equipment_tag` (sama persis), ATAU
2. `reports.asset_id` menunjuk asset yang `tech_ident_no`-nya **sama persis**
   dengan `cm_equipment.equipment_tag` (relasi `CmEquipment::asset()` yang sudah
   ada di aplikasi).

Selain itu:

- Pencocokan lewat **deskripsi pekerjaan (`LIKE '%tag%'`) SENGAJA TIDAK
  dipakai**, walaupun halaman detail asset masih memakai cara itu untuk laporan
  lama. Alasannya nyata di data DEV: halaman asset 2052 (`tech_ident_no`
  `6163P8`) menampilkan laporan *"Balancing 2-6163P8 ..."* yang sebenarnya milik
  asset 2647 = equipment **`2-6163P8`** (equipment yang BERBEDA). Di halaman
  equipment `6163P8`, laporan itu TIDAK ikut tampil.
- Salinan laporan kolaborator (`collaborator_of` terisi) disembunyikan supaya
  satu pekerjaan tidak muncul berulang — sama seperti halaman detail asset.
- Card ini menampilkan SELURUH riwayat laporan bertag equipment tersebut (tidak
  ikut disaring oleh dropdown tahun `range`, yang tetap berlaku untuk tabel
  Riwayat Pembacaan dan MTBF).

## Checklist

- [x] Hanya laporan yang BERTAG equipment itu yang tampil di halamannya.
- [x] Laporan equipment lain tidak ikut tampil walau kodenya disebut di
      deskripsi pekerjaan (ada test penjaganya).
- [x] Laporan tanpa tagging (pekerjaan area) tidak tampil.
- [x] Equipment tanpa laporan bertag tetap tampil normal + pesan kosong.
- [x] Card muncul di semua halaman equipment (satu view bersama).
- [x] Copy DEV diperbarui dulu lewat `tools\mulai-tugas.ps1` sebelum menyunting.
- [x] Test otomatis hijau (tidak ada test merah yang diunggah).

## Bukti uji

- `php artisan test --filter=CmEquipmentLaporanTeknisiTest`
  -> **6 test lulus, 22 assertion.**
- `php artisan test` (seluruh suite)
  -> **621 test lulus, 0 gagal.**
- Render nyata dari data DEV, halaman `/cm/equipment/6163P8`
  (asset 2052 "Methylester Transfer Pump"):
  - judul bagian "Laporan Teknisi (Daftar Perbaikan)": **ADA**;
  - laporan milik equipment itu (`RPT-20260923-0021`, "Monitoring vibrasi
    6163P8", teknisi M. ABEL YUJI PRATAMA): **ADA**;
  - tombol Detail ke `/reports/588`: **ADA**;
  - tautan `/assets/2052`: **ADA**;
  - laporan "Balancing 2-6163P8" (milik equipment LAIN): **TIDAK ADA** (sesuai
    permintaan), padahal laporan itu memang muncul di halaman asset 2052.
- Catatan jumlah data: DEV hanya punya **39 laporan** yang sudah bertag ke
  equipment CM (data DEV tipis, 356 asset terhubung), sedangkan data PROD punya
  **196 laporan** untuk **73 equipment** — jadi di PROD card ini akan lebih
  terisi. Untuk equipment yang belum punya laporan bertag, card menampilkan
  pesan kosong.

## Berkas yang disentuh

- `app/Http/Controllers/CmController.php` — `equipmentShow()`: query
  `$laporanTeknisi` + meneruskannya ke view.
- `resources/views/cm/equipment-show.blade.php` — card "Laporan Teknisi
  (Daftar Perbaikan)".
- `tests/Feature/CmEquipmentLaporanTeknisiTest.php` (baru).
- `AGENTS.md` — bagian baru "LAPORAN TEKNISI DI HALAMAN TIAP EQUIPMENT CM
  (14/10/2026)" (aturan pencocokan supaya sesi berikutnya tidak memakai `LIKE`).
- `tugas/erik.md` (papan klaim).
- `tugas/pr-tambah-laporan-teknisi-di-halaman-tiap-equipment-cm.md` (berkas ini).
