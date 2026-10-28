# Assets: export Excel lengkap mengikuti filter dan snapshot

## Ringkasan

- Menambahkan tombol **Export Excel Lengkap** di halaman Assets Management.
  Export memakai seluruh filter aktif (pencarian, status, PT, departemen, area,
  sub area, equipment type), termasuk baris cermin IT Assets `data_source =
  it_sync` yang sebelumnya tidak ikut export format impor.
- Kolom tambahan menyesuaikan kategori filter:
  - **CCTV/NVR**: NVR (Show), Channel, Pointing, Header, PIC, Connect To,
    waktu snapshot, link snapshot, dan snapshot yang disematkan.
  - **Switch managed/unmanaged**: Connect To, VLAN, Port.
  - **PC/Laptop**: Monitor Model/Serial, CPU Model/Serial, Processor, RAM,
    Penyimpanan.
  - Semua kategori: identitas IT, plant/departemen/lokasi IT, spesifikasi,
    status ping, latency, laporan, teknisi, tanggal import/update, link foto,
    dan koordinat.
- Snapshot diambil dari `it_assets.last_snapshot_path` (CCTV/NVR), lalu
  fallback ke `assets.photos`. Thumbnail JPEG disematkan di kolom terakhir
  supaya file tidak membengkak; link snapshot/foto tetap ditulis sebagai teks.
- Export lama **Export Excel (Import)** dan CSV tetap dipertahankan untuk
  kebutuhan import ulang dan tidak berubah perilakunya.

## Berkas yang disentuh

- `app/Http/Controllers/Traits/AssetExportLengkapTrait.php` (baru)
- `app/Http/Controllers/Traits/AssetExportTrait.php`
- `app/Http/Controllers/AssetController.php`
- `routes/web.php`
- `resources/views/assets/index.blade.php`
- `tests/Feature/AssetExportLengkapTest.php` (baru)
- `docs/28-assets-management.md`
- `docs/PROMPT.md`

## Bukti uji

- `php artisan test --filter=AssetExportLengkapTest`: **3 test hijau,
  32 assertions**.
- Seluruh suite: **777 test hijau, 3.908 assertions**.
- `php artisan view:cache` sukses.
- Realisasi DEV filter CCTV: **95 asset, 90 gambar snapshot, file sekitar
  923 KB**.

## Checklist

- [x] Export Excel mengikuti semua filter halaman Assets Management.
- [x] Baris cermin IT Assets ikut saat filter CCTV/switch/perangkat IT.
- [x] Kolom CCTV, switch, dan PC/Laptop menyesuaikan kategori.
- [x] Snapshot CCTV dan foto equipment disematkan sebagai thumbnail.
- [x] Link snapshot/foto tersedia sebagai teks.
- [x] Export format import dan CSV lama tetap berjalan.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru dan tidak ada perubahan data.
- Setelah deploy, buka Assets Management, pilih filter (mis. CCTV atau switch),
  lalu klik **Export Excel Lengkap**.
