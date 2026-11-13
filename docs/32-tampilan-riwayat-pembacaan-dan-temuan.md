# Tampilan Riwayat Pembacaan & Temuan Equipment CM

Dokumen ini mencatat perbaikan tampilan pada halaman
`/cm/equipment/{tag}` (detail equipment CM), khususnya dua tabel yang
sebelumnya sulit dibaca:

1. **Riwayat Pembacaan** — nilai vibrasi yang benar-benar Danger/Alarm tidak
   terlihat karena tabel hanya menampilkan tiga nilai NDE.
2. **Temuan (Findings)** — tidak ada kolom remark, deskripsi dipotong, dan
   tidak ada tombol edit.

## Riwayat Pembacaan

Sumber data tetap `cm_readings`. Perubahan tampilan:

- Setiap unit (Motor, Mesin, dan Screw bila ada) menampilkan **NDE dan DE**
  sekaligus, masing-masing tiga sumbu **V/H/A**.
  Sebelumnya `take(3)` hanya mengambil NDE-V, NDE-H, NDE-A sehingga nilai
  bahaya di DE (contoh 21,90 mm/s pada Motor DE-A) tidak pernah tampil.
- Nilai tertinggi pada baris diambil dari kolom `max_vibration` (hasil sheet
  `Status CM`). Bila kolom itu kosong, nilai dihitung dari seluruh titik ukur.
- Kolom **Kondisi** menampilkan badge kondisi dari sheet, nilai maksimum, dan
  sumber nilai maksimum (mis. `Motor DE-A`). Nilai maksimum pada sel vibrasi
  diberi warna merah (Danger) atau kuning (Alarm).
- Warna kondisi mengikuti kolom `kondisi` dari sumber data, bukan threshold
  chart. Ini menghindari perbedaan batas ISO antar tipe equipment.
- Remark pembacaan tidak lagi dipotong `truncate`. Teks panjang tampil
  beberapa baris. Penanda `[Temuan: ...]` dipisahkan menjadi chip
  **Temuan** agar kalimat remark tetap terbaca.
- Temperatur ditampilkan terpisah untuk Motor (`Mtr`) dan Mesin (`Msn`),
  memakai nilai tertinggi dari sisi DE/NDE.
- Ampere dan Pressure memakai format angka tetap; nilai `0` tidak lagi
  berubah menjadi tanda hubung.
- Kolom Vibrasi Screw hanya muncul bila halaman itu memang punya data screw.

Partial yang dipakai: `resources/views/cm/_riwayat-pembacaan-rows.blade.php`.

## Temuan (Findings)

Perubahan tampilan:

- Kolom baru **Remark**. Isinya diambil dari `cm_findings.remark`; bila kosong,
  dipakai remark reading induk (`cm_findings.cm_reading_id`) dengan label
  sumbernya.
- Kolom **Uraian & Tindakan** menampilkan Deskripsi, Analisa, dan Tindakan
  secara terpisah dan tidak dipotong. Deskripsi yang hanya menduplikasi
  analisa + tindakan tidak ditampilkan dua kali.
- Kolom **Status / PIC** dan **Umur** digabung agar tabel lebih ringkas.
  Finding open di atas 7 hari tetap ditandai merah.
- Tombol **Edit** membuka modal edit finding. Field yang bisa diubah:
  severity, status, tanggal temuan, kategori, PIC, tanggal tindakan,
  deskripsi, analisa, tindakan, dan remark.
- Tombol **Detail**, **WO/+WO**, dan hapus tetap tersedia di kolom Aksi.
- Halaman detail finding punya tombol **Edit Finding** yang langsung membuka
  modal edit di halaman itu (tanpa berpindah ke halaman equipment). Setelah
  disimpan, pengguna kembali ke halaman detail finding.
- Export Finding CM ikut memuat kolom Analisa, Tindakan, dan Remark.

Partial/modal:

- `resources/views/cm/_temuan-rows.blade.php`
- `resources/views/cm/_modal-edit-finding.blade.php`
- `resources/views/cm/finding-show.blade.php` (detail lengkap + modal edit)

## Halaman Daftar Finding CM

- Filter baru **PIC** memakai nilai `cm_findings.pic` yang berasal dari kolom
  PIC di sheet Finding CM. Pilihan **Tanpa PIC** menyaring finding yang PIC-nya
  kosong.
- Filter PIC juga dipakai saat **Export Excel**.
- Setiap baris finding bisa diklik untuk membuka halaman detail finding.
  Klik pada **tag equipment** tetap membuka halaman status/detail equipment
  (`/cm/equipment/{tag}`), bukan halaman finding.
- Tombol **Edit** pada baris membuka halaman detail finding dengan `?edit=1`
  sehingga modal edit langsung terbuka.
- Halaman detail menampilkan PIC, kategori, severity, status, tanggal temuan,
  hari open, tanggal tindakan, informasi equipment, konteks pembacaan, seluruh
  foto, remark, dan Work Order terkait.
- Bila finding belum punya foto, halaman detail menampilkan keadaan kosong
  "Belum ada foto untuk finding ini." agar area foto tetap informatif.

## Remark dan Sinkronisasi

- Kolom `cm_findings.remark` ditambahkan lewat migrasi
  `2026_10_27_000001_add_remark_to_cm_findings_table.php`.
- Import `Finding CM` sekarang menyimpan kolom `Remark` ke kolom sendiri,
  bukan menempelkannya ke `deskripsi`.
- Saat sinkronisasi (`ProcessCmFindingsImport`), remark manual **tidak
  dikosongkan** bila kolom Remark di sheet kosong. Remark dari sheet tetap
  menang bila terisi.
- Field lain yang berasal dari sheet (severity, status, PIC, analisa,
  tindakan) dapat diperbarui lagi oleh sinkronisasi berikutnya. Remark manual
  tetap dipertahankan.

## Rute dan Controller

- `PUT /cm/findings/{finding}` → `CmController::findingUpdate()`
  dengan nama rute `cm.findings.update`. Bila form mengirim
  `redirect=finding`, pengguna diarahkan kembali ke halaman detail finding.
- `CmController::equipmentShow()` eager-load `findings.reading` dan
  `findings.workOrder` supaya tabel tidak N+1.
- `CmController::findingShow()` eager-load `equipment.asset`, `reading`, dan
  `workOrder` untuk kartu informasi detail finding.
- `CmController::findings()` menyaring PIC dan menyediakan daftar PIC untuk
  dropdown. `CmFindingsExport` menerima filter PIC yang sama.

## Tautan Edit Laporan Teknisi

Tabel **Laporan Teknisi** di halaman equipment kini punya tombol **Edit**
menuju `reports.show` dengan `?edit=1`. Halaman detail laporan membaca
parameter itu dan langsung membuka mode edit (hanya untuk laporan induk;
baris salinan kolaborasi tetap tidak bisa diedit langsung).

## Uji

- `tests/Feature/CmEquipmentDetailFindingTest.php`
  - seluruh sumbu vibrasi dan sumber nilai tertinggi tampil;
  - remark pembacaan tampil utuh, finding punya remark + tombol edit;
  - update finding menghitung ulang `hari_open`;
  - validasi status/tanggal tindakan;
  - tautan edit laporan membuka `editMode: true`.
- `tests/Feature/CmFindingPagesTest.php`
  - filter PIC dan filter Tanpa PIC;
  - baris finding menaut ke detail, tag equipment menaut ke halaman equipment;
  - detail finding menampilkan PIC, foto, konteks pembacaan, dan tombol edit;
  - edit dari detail kembali ke halaman detail.
- `tests/Feature/CmEquipmentTrendPressureAmpereTest.php` disesuaikan karena
  padding sel Pressure berubah dari `py-3` menjadi `py-4`.

## Catatan

- Tidak ada perubahan pada nilai sumber atau threshold bisnis. Nilai kondisi
  tetap mengikuti sheet `Status CM`.
- Tidak perlu deploy manual dari sesi ini; deploy tetap dilakukan integrator
  setelah PR di-merge dan DEV kembali sejajar `main`.
