# Pisahkan dokumentasi AGENTS.md ke folder docs

## Ringkasan

- `AGENTS.md` dijadikan **peta saja**: 105 baris / 8 KB (sebelumnya 4.470 baris /
  302 KB). Isinya aturan pokok + tabel dokumen + alur sesi + aturan perawatan peta.
- Seluruh isi lama dipecah ke `docs/01-...` sampai `docs/20-...` (20 berkas).
- Dokumen pendukung ikut dipindah ke `docs/`: `ASSET_MAPPING.md`, `PROMPT.md`,
  `rancangan-proyek-sensor-suhu-esp32.md`, `README-LARAVEL.md`,
  `sensor-suhu-esp32-README.md`, dan `pull_request_template.md`.
- Referensi path diperbarui (`docs/pull_request_template.md`, path README sensor,
  rujukan `PROMPT.md`, rujukan aturan edit, dan peta dokumen).
- `tugas/**/*.md`, `.kilo/`, dan folder dependensi sengaja tidak dipindah karena
  dibaca/ditulis tooling.

## Bukti verifikasi

- Semua link relatif di `AGENTS.md` valid: 0 tautan rusak.
- Perbandingan baris isi lama vs 20 berkas `docs/`: hanya 3 perbedaan yang
  disengaja (H1 lama dan 2 rujukan `PROMPT.md`), jadi tidak ada isi yang hilang.
- Tidak ada lagi referensi `docs/agents`, `AGENTS.md baris ...`, atau path
  `.github\pull_request_template.md` yang tertinggal.
- Tidak ada perubahan kode aplikasi.

## Hasil uji

`php artisan test`: 665 test, 662 lulus, 3 gagal.

Ketiga kegagalan bukan akibat perubahan dokumentasi ini: test memakai tanggal
`now()->subDay()` yang pada 19/10/2026 jatuh hari **Minggu, 18/10/2026**
(hari libur untuk grup reguler), lalu mengharapkan man hour normal 120 menit.
Yang gagal:

- `ReportEditAlatTambahanTest::baris salinan multi item tidak ikut dihitung pada man hour`
- `ReportEditKolaborasiTest::salinan kolaborasi ikut terhitung pada man hour teknisi kolaborator`
- `ReportTeknisiKolaborasiTest::jumlah baris teknisi kolaborator sama dengan kolom Pekerjaan rincian man hour harian`

Perubahan ini hanya menyentuh `.md`; tidak ada kode yang memengaruhi man hour.

## Berkas yang disentuh

- `AGENTS.md` - dijadikan peta
- `docs/` - 20 dokumen pecahan + 6 dokumen pendukung
- `.github/pull_request_template.md` - dipindah ke `docs/pull_request_template.md`
- `ASSET_MAPPING.md`, `PROMPT.md`, `README.md`,
  `rancangan-proyek-sensor-suhu-esp32.md`, `sensor-suhu-esp32/README.md`
  - dipindah ke `docs/`
- `tugas/erik.md` - papan klaim
- `tugas/pr-pisahkan-dokumentasi-agents-ke-docs.md` - catatan PR ini
