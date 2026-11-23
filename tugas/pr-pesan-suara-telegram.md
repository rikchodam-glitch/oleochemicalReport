# Voice-to-text Telegram: pesan suara menjadi teks dengan konfirmasi

## Ringkasan

- Teknisi dapat mengirim voice note kapan saja, kecuali saat wizard sedang
  menunggu foto.
- Audio diunduh ke disk private sementara, ditranskripsi memakai Groq Whisper
  (`whisper-large-v3-turbo`, bahasa `id`), lalu audio dihapus.
- Bot menampilkan transkrip beserta tombol **Gunakan**, **Ulangi**, dan
  **Batalkan**.
- Setelah **Gunakan**, transkrip diperlakukan persis seperti teks yang diketik:
  - masuk ke langkah wizard laporan/request yang aktif, atau
  - memulai alur teks biasa/laporan baru bila tidak ada wizard.
- Hasil laporan tetap berupa teks; audio tidak disimpan permanen.
- Voice ditolak pada langkah foto dengan pesan agar mengirim foto atau mengetik
  `-` untuk melewati.
- Perintah slash (`/pakai`, `/laporan`, `/req`, dll.) tetap diketik; voice hanya
  untuk isian teks bebas.
- Jalur webhook memakai balasan teks `GUNAKAN`/`ULANGI`/`BATAL` karena tidak
  mendukung inline keyboard.
- Transkrip menunggu disimpan per chat (TTL 10 menit); voice baru menggantikan
  yang lama. Teks lain, foto, atau `/reset` membuang transkrip menunggu.
- Batas: durasi 5 menit, ukuran 20 MB, 5 transkripsi/menit per teknisi.

## Bukti uji

- Uji langsung Groq Whisper dengan audio WAV sintetis ~15 detik:
  transkripsi **~0,9 detik**, kode `BRBT001` terbaca benar.
- `php artisan test` seluruh suite: **877 test hijau, 4.538 assertions**.
- `php artisan view:cache` dan `npm run build` sukses.

## Berkas

- `config/voice.php`
- `app/Services/SpeechToTextService.php`
- `app/Services/Telegram/VoiceStorageService.php`
- `app/Services/Telegram/PendingTranscriptService.php`
- `app/Services/Telegram/VoiceTranscriptionService.php`
- `app/Models/VoiceTranscription.php`
- `database/migrations/2026_11_23_000001_create_voice_transcriptions_table.php`
- `app/Services/Telegram/ReportWizardService.php` (`menungguFoto`)
- `app/Services/Telegram/SparepartRequestWizardService.php` (`menungguFoto`)
- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Console/Commands/PollTelegramUpdates.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `tests/Feature/VoiceTranscriptionTest.php`
- `docs/34-pesan-suara-telegram.md`, `docs/27-menu-perintah-bot-telegram.md`,
  `AGENTS.md`

## Checklist

- [x] Voice note diunduh, ditranskripsi, dan audio dihapus.
- [x] Konfirmasi Gunakan/Ulangi/Batalkan.
- [x] Persetujuan lewat teks dan callback.
- [x] Integrasi wizard laporan/request dan alur teks biasa.
- [x] Penolakan voice saat langkah foto (laporan/request).
- [x] Batas durasi/ukuran/rate limit.
- [x] Log `voice_transcriptions` tanpa menyimpan audio.
- [x] Jalur webhook dengan balasan teks.
- [x] Test fitur dan seluruh suite hijau.
- [x] Dokumentasi diperbarui.

## Catatan deploy

- Migrasi `voice_transcriptions` berjalan otomatis saat deploy.
- Tidak ada command menu baru; tidak perlu `telegram:set-commands`.
- Provider Groq harus sehat dan memiliki akses model Whisper.
- Format voice note Telegram adalah OGG/Opus; uji nyata dengan voice note asli
  tetap perlu dilakukan setelah deploy.
- Jika akurasi bahasa Indonesia kurang, model dapat diganti ke
  `whisper-large-v3` lewat `config/voice.php`/env.
- Berkas kotor sesi lain di copy DEV tidak ikut di-commit.
