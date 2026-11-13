# Perbaiki tampilan riwayat pembacaan dan temuan equipment CM

## Ringkasan

Halaman detail equipment CM (`/cm/equipment/{tag}`) sekarang lebih mudah dibaca:

- Riwayat pembacaan menampilkan **semua sumbu vibrasi** (NDE dan DE, V/H/A),
  bukan hanya tiga nilai NDE. Nilai tertinggi pada baris Danger/Alarm diberi
  warna dan sumbernya ditulis (contoh: `Motor DE-A 21.9 mm/s`).
- Remark pembacaan tidak lagi dipotong; teks panjang tampil beberapa baris.
  Penanda `[Temuan: ...]` dipisahkan menjadi chip.
- Temuan (Findings) punya kolom **Remark**, uraian/analisa/tindakan tampil
  terpisah dan tidak terpotong, serta ada tombol **Edit**.
- Modal edit finding bisa mengubah severity, status, tanggal, kategori, PIC,
  analisa, tindakan, dan remark. `hari_open` dihitung ulang otomatis.
- Tabel Laporan Teknisi punya tombol **Edit** yang langsung membuka mode edit
  laporan (`reports.show?edit=1`).
- Detail finding dan daftar Finding CM juga punya tautan edit yang membuka
  modal yang sama.
- Halaman **Finding CM** punya filter **PIC** (termasuk **Tanpa PIC**), kolom
  PIC, dan setiap baris bisa diklik ke halaman detail finding. Klik pada tag
  equipment tetap membuka halaman status/detail equipment.
- Halaman detail finding menampilkan ringkasan PIC, tanggal temuan, hari open,
  tanggal tindakan, informasi equipment, konteks pembacaan, seluruh foto, dan
  Work Order terkait. Tombol **Edit Finding** membuka modal edit langsung di
  halaman itu dan kembali ke detail setelah disimpan.
- Filter PIC juga diterapkan pada Export Excel Finding CM.
- Header Finding CM menampilkan beban open per PIC (termasuk Tanpa PIC), jumlah
  open lebih dari 7 hari, equipment terdampak, dan distribusi umur open.
- Overview kini menghitung **visual bad sebagai kondisi baik** pada persentase
  utama karena vibrasinya masih baik, dengan catatan visual bad tetap perlu
  ditindaklanjuti. Visual bad tetap tampil sebagai kartu dan segmen grafik
  terpisah.
- Overview menambah panel tindak lanjut finding (open, >7 hari, tanpa PIC,
  equipment terdampak) bergaya dashboard PM engineer.

## Berkas utama

- `resources/views/cm/equipment-show.blade.php`
- `resources/views/cm/_riwayat-pembacaan-rows.blade.php` (baru)
- `resources/views/cm/_temuan-rows.blade.php` (baru)
- `resources/views/cm/_modal-edit-finding.blade.php` (baru)
- `resources/views/cm/finding-show.blade.php`
- `resources/views/cm/findings.blade.php`
- `resources/views/cm/_findings-list.blade.php`
- `resources/views/cm/overview.blade.php`
- `resources/views/cm/_overview-cards.blade.php`
- `resources/views/reports/show.blade.php`
- `app/Http/Controllers/CmController.php`
- `app/Models/CmFinding.php`
- `app/Jobs/ProcessCmFindingsImport.php`
- `app/Console/Commands/CmImportFindings.php`
- `app/Exports/CmFindingsExport.php`
- `database/migrations/2026_10_27_000001_add_remark_to_cm_findings_table.php`
- `routes/web.php`
- `tests/Feature/CmEquipmentDetailFindingTest.php` (baru)
- `tests/Feature/CmFindingPagesTest.php` (baru)
- `docs/32-tampilan-riwayat-pembacaan-dan-temuan.md` (baru)

## Remark dan sinkronisasi

- Kolom `cm_findings.remark` ditambahkan lewat migrasi.
- Import Finding CM menyimpan Remark ke kolom sendiri (tidak lagi ditempel ke
  `deskripsi`).
- Sinkronisasi berikutnya tidak mengosongkan remark manual bila kolom Remark di
  sheet kosong. Remark dari sheet tetap menang bila terisi.
- Field lain yang berasal dari sheet dapat diperbarui lagi oleh sinkronisasi.

## Uji

- `php artisan test` → **799 passed (4038 assertions)**
- `php artisan view:cache` → sukses
- Test baru: `tests/Feature/CmEquipmentDetailFindingTest.php`
  (5 test, 26 assertion), `tests/Feature/CmFindingPagesTest.php`
  (7 test, 38 assertion), dan `tests/Feature/CmOverviewVisualBadTest.php`
  (2 test, 9 assertion).
- Satu assertion lama di `CmEquipmentTrendPressureAmpereTest.php` disesuaikan
  karena padding sel Pressure berubah dari `py-3` menjadi `py-4`.

## Catatan deploy

Belum ada deploy dari sesi ini. Setelah PR di-merge, integrator menjalankan
`tools\kembalikan-ke-main.ps1` sampai `SEJAJAR=1`, lalu `Deploy-Laporan.bat`
(migrasi `remark` ikut berjalan).
