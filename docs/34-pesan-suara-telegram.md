# Pesan Suara Telegram (Voice-to-Text)

Fitur ini memungkinkan teknisi mengganti isian teks dengan voice note. Audio
ditranskripsi menjadi teks, ditampilkan untuk dikonfirmasi, lalu diproses
seperti teks yang diketik. Hasil akhir laporan tetap berupa teks.

## Alur

1. Teknisi mengirim voice note kapan saja, kecuali saat wizard sedang menunggu
   foto.
2. Bot mengunduh audio, mengirim ke Groq Whisper, lalu menghapus audio lokal.
3. Bot menampilkan transkrip + tombol **Gunakan**, **Ulangi**, **Batalkan**.
4. **Gunakan** memproses transkrip:
   - jika wizard laporan/request aktif: menjadi jawaban langkah tersebut;
   - jika tidak ada wizard: menjadi teks biasa dan memulai laporan baru.
5. **Ulangi** meminta voice note baru. **Batalkan** membuang transkrip.

Jalur webhook tidak mendukung inline keyboard; bot meminta balasan teks
`GUNAKAN`, `ULANGI`, atau `BATAL`.

## Batasan penting

- Voice **tidak boleh** dipakai saat langkah foto. Bot membalas agar mengirim
  foto atau mengetik `-` untuk melewati.
- Perintah slash (`/pakai`, `/laporan`, `/req`, dan lainnya) tetap diketik.
  Voice hanya menggantikan isian teks bebas.
- Satu chat hanya punya satu transkrip menunggu; voice baru menggantikan yang
  lama. Teks biasa, foto, atau `/reset` membuang transkrip menunggu.
- Transkrip yang disetujui tetap dapat ditolak/diulang sebelum dipakai.

## Groq Whisper

- Provider/key diambil dari tabel `ai_providers` (`provider_type = groq`,
  `status = healthy`), bukan dari `.env` baru.
- Telegram sering mengirim voice note dengan ekstensi `.oga`; file dinormalisasi
  menjadi `.ogg` karena Groq menolak `.oga` dengan pesan
  `unsupported_audio_format`.
- Model bawaan `whisper-large-v3-turbo`; `whisper-large-v3` dapat dipilih lewat
  konfigurasi bila akurasi perlu ditingkatkan.
- Bahasa `id`, prompt berisi istilah alat/equipment dan kode agar istilah
  teknis lebih akurat.
- confidence dihitung dari `no_speech_prob` dan `avg_logprob` segmen Whisper
  (tinggi/sedang/rendah).

## Batas operasional

- Durasi voice note maksimal 300 detik.
- Ukuran audio maksimal 20 MB.
- Maksimal 5 transkripsi per menit per teknisi.
- Audio dihapus setelah transkripsi; yang disimpan hanya log metadata dan
  transkrip di `voice_transcriptions`.

## Konfigurasi

Semua pengaturan ada di `config/voice.php`:

- `enabled`, `model`, `language`, `prompt`, `endpoint`;
- `disk`, `folder`, `max_bytes`, `max_durasi_detik`;
- `pending_ttl_menit`, `rate_limit_per_menit`, `glossary_limit`.

## Berkas utama

- `config/voice.php`
- `app/Services/SpeechToTextService.php`
- `app/Services/Telegram/VoiceStorageService.php`
- `app/Services/Telegram/PendingTranscriptService.php`
- `app/Services/Telegram/VoiceTranscriptionService.php`
- `app/Models/VoiceTranscription.php`
- `database/migrations/2026_11_23_000001_create_voice_transcriptions_table.php`
- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Console/Commands/PollTelegramUpdates.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `tests/Feature/VoiceTranscriptionTest.php`

## Uji

- Test fitur mencakup: transkripsi + konfirmasi, persetujuan lewat teks dan
  callback, penolakan saat langkah foto, kegagalan Groq, serta jalur webhook.
- Uji langsung Groq Whisper dengan audio WAV sintetis ~15 detik: transkripsi
  ~0,9 detik dan kode `BRBT001` terbaca benar.
- Format voice note Telegram adalah OGG/Opus; uji dengan voice note asli tetap
  diperlukan sebelum deploy.

## Deploy

- Migrasi `voice_transcriptions` berjalan otomatis saat deploy.
- Tidak ada perubahan menu command; tidak perlu `telegram:set-commands`.
- Pastikan provider Groq berstatus healthy dan memiliki akses Whisper.
- Setelah deploy, uji satu voice note jernih dan satu voice note bising dari
  akun teknisi aktif.
