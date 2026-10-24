# Dokumentasi: aturan deploy hanya setelah PR merge dan DEV di main

## Ringkasan

- `AGENTS.md`: menambah aturan pokok bahwa deploy hanya boleh disarankan
  setelah Pull Request benar-benar di-merge dan copy DEV kembali ke `main`
  dengan penjaga mencetak `SEJAJAR=1`.
- `docs/02-alur-kerja-banyak-teknisi.md`: menambah bagian 6d yang mencatat
  insiden 24/10/2026. PR #58 sudah di-merge (`8154eb3`), tetapi DEV masih di
  cabang tugas dengan commit dokumentasi `9533c47` yang belum masuk `main`,
  sehingga `Deploy-Laporan.bat` dibatalkan penjaga (`SEJAJAR=0`).
- `docs/28-assets-management.md`: koreksi jumlah assertion pada catatan uji
  dari PR #58 (3.673 -> 3.677). Koreksi ini adalah commit yang tertinggal dan
  ikut diselesaikan lewat PR ini.

## Berkas yang disentuh

- `AGENTS.md`
- `docs/02-alur-kerja-banyak-teknisi.md`
- `docs/28-assets-management.md`

## Bukti uji

- Tidak ada perubahan kode atau blade.
- `php artisan test`: 738 test hijau, 3.677 assertions.

## Checklist

- [x] Aturan deploy setelah merge dicatat di `AGENTS.md`.
- [x] Rincian insiden dan langkah pencegahan dicatat di `docs/02`.
- [x] Koreksi jumlah assertion dokumen ikut diselesaikan.
- [x] Uji otomatis hijau.
