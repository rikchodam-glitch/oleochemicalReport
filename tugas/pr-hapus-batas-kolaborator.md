# PR: Hapus batas jumlah kolaborator teknisi

Cabang: `tugas/erik/hapus-batas-kolaborator`

## Ringkasan

Permintaan user 15/10/2026: hilangkan batas jumlah kolaborator teknisi.

## Temuan (verifikasi dulu)

Batas lama = **5 kolaborator per laporan**, ditegakkan di lima tempat:
- `CollaborationService::MAX_KOLABORATOR` (pemotongan otomatis + guard penggabungan),
- `WizardStepHandlerTrait::MAX_COLLABORATORS` (guard input, slot per input, peringatan slot penuh),
- `WizardCallbackHandlerTrait` (guard saat memilih kandidat nama & saat memilih dari hierarki),
- `ReportCollaborationController` (penolakan tambah kolaborator ke-6 dari halaman web),
- tampilan `reports/partials/_kolaborasi.blade.php` ("x / 5 kolaborator").

## Perubahan

- Semua pemotongan/penolakan berbasis batas 5 dihapus; konstanta
  `MAX_KOLABORATOR`/`MAX_COLLABORATORS` dihapus dan rujukannya dibersihkan.
- Yang TETAP dijaga (bukan batas): dedup NIK/nama (tidak dobel), pelaksana
  laporan sendiri tidak bisa jadi kolaborator, dan aturan "kelola hanya dari
  laporan induk".
- Tampilan kini menampilkan "N kolaborator" tanpa angka maksimum.
- Bagian JSON balasan tidak lagi mengirim `maksimal`.

## Bukti uji

- `tests/Feature/CollaborationTest.php`: uji "jumlah kolaborator tidak dibatasi
  lagi" (10 kolaborator otomatis diterima).
- `tests/Feature/ReportEditKolaborasiTest.php`: uji "penambahan kolaborator
  tidak dibatasi jumlahnya" (8 kolaborator lewat endpoint web diterima).
- `php artisan test` **665 test hijau (3239 assertions)**.
- Uji mutasi: menambahkan kembali penolakan `count >= 5` pada controller membuat
  uji "penambahan kolaborator tidak dibatasi jumlahnya" GAGAL (422), lalu
  dikembalikan.

## Berkas yang disentuh

- `app/Services/CollaborationService.php`
- `app/Services/Telegram/Traits/WizardStepHandlerTrait.php`
- `app/Services/Telegram/Traits/WizardCallbackHandlerTrait.php`
- `app/Http/Controllers/ReportCollaborationController.php`
- `resources/views/reports/partials/_kolaborasi.blade.php`
- `tests/Feature/CollaborationTest.php`
- `tests/Feature/ReportEditKolaborasiTest.php`
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] `php artisan view:cache` sukses
- [x] Tanpa migrasi / tanpa perubahan data
- [x] Dedup & pengecualian pelaksana sendiri tetap dijaga
