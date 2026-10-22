# Perbaiki format jam laporan web (500)

## Penyebab

Kolom `reports.start_time` dan `reports.end_time` bertipe `varchar(5)`, tetapi
form web menyimpan `08:00:00` (8 karakter). MySQL menolak dengan:

`SQLSTATE[22001]: Data too long for column 'start_time'`

sehingga submit laporan menghasilkan 500.

## Perbaikan

- `ReportFormService` menyimpan jam sebagai `H:i` (contoh `08:00`), sesuai
  `varchar(5)`.
- Durasi tetap dihitung dari Carbon.
- Test menambahkan assertion `start_time = 08:00` dan `end_time = 09:30`.

## Bukti

- `ReportFormTest`: 14 test hijau, 57 assertion.
- Seluruh suite: 715 test hijau, 3.512 assertion.
- Error PROD berasal dari submit `Perbaikan WiFi CCR NPA` dengan
  `start_time=08:00:00`.

## Berkas

- `app/Services/ReportFormService.php`
- `tests/Feature/ReportFormTest.php`
- `docs/25-form-laporan-teknisi.md`
- `tugas/erik.md`
