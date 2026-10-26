# Sparepart request: hapus request yang belum punya PR

## Ringkasan

- Staff CM/PM dapat menghapus request pada status `menunggu`, `disetujui`,
  atau `ditolak` (belum ada nomor PR).
- Request yang sudah masuk `kejar_po`, `kejar_delivery`, atau `selesai` tidak
  bisa dihapus karena sudah menjadi riwayat pengadaan.
- Tombol hapus berupa ikon tempat sampah di kolom Aksi tab Pengajuan, dengan
  konfirmasi sebelum menghapus.
- Foto request ikut dihapus dari disk melalui event `deleting` pada model,
  sehingga tidak meninggalkan berkas yatim.
- Hanya Staff CM/PM (atau Administrator/Supervisor) yang boleh menghapus;
  Reguler Staff dari Bagian lain dan teknisi biasa ditolak server.

## Berkas yang disentuh

- `app/Models/SpRequest.php`
- `app/Services/Sparepart/SparepartRequestService.php`
- `app/Http/Controllers/SparepartRequestController.php`
- `resources/views/sparepart/request/partials/_pengajuan.blade.php`
- `resources/views/sparepart/request/partials/_tombol-hapus.blade.php`
- `config/akses.php`
- `routes/web.php`
- `tests/Feature/SparepartRequestWebTest.php`
- `docs/30-request-sparepart.md`

## Bukti uji

- `php artisan test --filter=SparepartRequest`: **23 test hijau,
  81 assertions**.
- Seluruh suite: **761 test hijau, 3.758 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Request menunggu, disetujui, dan ditolak bisa dihapus.
- [x] Request dengan PR/PO ditolak untuk dihapus.
- [x] Foto request ikut terhapus.
- [x] Reguler Staff non-CM/PM dan teknisi biasa tidak bisa menghapus.
- [x] Tombol hapus hanya tampil di tab Pengajuan.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Tombol hapus langsung tersedia setelah PR ini di-merge dan di-deploy.
