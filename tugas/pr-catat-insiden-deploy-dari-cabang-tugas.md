# Catat insiden deploy dari cabang tugas

## Ringkasan

Mendokumentasikan kejadian 20/10/2026 agar tidak terulang:

- Fitur sudah di-merge ke `main` lewat PR #25 (`82830c6`).
- Di cabang tugas masih dibuat commit tambahan `350a24c` yang hanya mengganti
  nama catatan PR.
- `Deploy-Laporan.bat` dijalankan dari cabang tugas, sehingga penjaga
  membandingkan `350a24c` dengan `main` GitHub `05be5ce` dan membatalkan deploy.
- Penjaga benar; tidak ada kode fitur yang belum masuk main.
- Perbaikan: `tools\kembalikan-ke-main.ps1` memindahkan DEV ke `main`
  `05be5ce`; penjaga `SEJAJAR=1`, `CARA=sejajar`. PROD tidak disentuh.

## Aturan baru

1. Semua catatan PR (termasuk `tugas/pr-*.md`) di-commit **sebelum** PR dibuat
   atau di-merge.
2. Jangan menambah commit ke cabang tugas setelah PR-nya di-merge.
3. Deploy hanya dari `main`; jangan dari cabang tugas.
4. Bila `KEMBALI_MAIN=BELUM` tetapi penjaga `SEJAJAR=1`, periksa apakah commit
   yang tertinggal hanya administratif. Jangan reset/hapus branch.

## Berkas

- `docs/02-alur-kerja-banyak-teknisi.md` — bagian 6c
- `docs/12-riwayat-insiden-33-46.md` — insiden #47
- `AGENTS.md` — deskripsi peta insiden 33-47
- `tugas/erik.md`

## Uji

Perubahan hanya dokumentasi. `php artisan test` dijalankan lewat gerbang
pre-push; tidak ada kode aplikasi yang berubah.
