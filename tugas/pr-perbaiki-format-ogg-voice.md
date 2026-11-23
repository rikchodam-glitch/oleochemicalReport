# Perbaiki voice note Telegram ditolak Groq karena ekstensi .oga

## Penyebab

Uji nyata di PROD menghasilkan pesan:

`Transkripsi gagal (HTTP 400) Coba ulangi atau ketik pesan anda`

Log PROD menunjukkan Groq menolak file:

```
file must be one of the following types:
[flac mp3 mp4 mpeg mpga m4a ogg opus wav webm]
unsupported_audio_format
```

Telegram menyimpan voice note sebagai `.oga`; Groq Whisper menerima `.ogg`
tetapi tidak menerima `.oga`. File sudah diunduh dengan benar, hanya ekstensinya
yang tidak dikenali Groq.

## Perbaikan

- `VoiceStorageService` menormalisasi ekstensi `.oga` menjadi `.ogg` sebelum
  file disimpan dan dikirim ke Groq.
- `SpeechToTextService` memberi pesan yang lebih jelas bila provider tetap
  menolak format audio.
- Test regresi memastikan voice note Telegram ber-ekstensi `.oga` disimpan
  sebagai `.ogg`.

## Bukti uji

- `VoiceTranscriptionTest`: **7 test hijau, 21 assertions**.
- Seluruh suite dijalankan sebelum unggah.

## Berkas

- `app/Services/Telegram/VoiceStorageService.php`
- `app/Services/SpeechToTextService.php`
- `tests/Feature/VoiceTranscriptionTest.php`
- `docs/34-pesan-suara-telegram.md`

## Deploy

- Tidak ada migrasi baru.
- Setelah merge dan deploy, uji ulang voice note yang sama.
- Tidak ada perubahan command menu.
