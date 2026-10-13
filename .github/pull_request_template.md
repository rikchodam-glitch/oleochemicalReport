# Ringkasan perubahan

<!-- Jelaskan apa yang berubah dan kenapa, dalam Bahasa Indonesia, tanpa emoji. -->

## Checklist wajib (agenda integrator)

- [ ] `php artisan test` HIJAU (tempel jumlah test + assertions di bawah).
- [ ] `php artisan view:cache` sukses (bila ada berkas blade yang disentuh).
- [ ] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [ ] Tidak ada berkas sampah (skrip `tmp-*`, `.bak`, hasil export sekali pakai).
- [ ] Bila ada migrasi baru: nama berkas memakai stempel waktu `php artisan make:migration`.
- [ ] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.
- [ ] Bagian `AGENTS.md` sudah diperbarui bila perilaku/aturan berubah, DAN
      ditambahkan entri "Riwayat Insiden" bila ini perbaikan bug.

## Bukti uji

```
(Jumlah test + assertions, atau potongan keluaran uji yang membuktikan.)
```

## Berkas yang disentuh

```
1. path/berkas-satu.php      - apa yang diubah
2. path/berkas-dua.blade.php - apa yang diubah
```

## Risiko / hal yang perlu diperhatikan integrator

<!-- Mis. menyentuh definisi filter man hour, menghapus partial, mengubah .bat,
     atau butuh langkah deploy khusus (migrasi, build aset, pengaturan .env PROD). -->
