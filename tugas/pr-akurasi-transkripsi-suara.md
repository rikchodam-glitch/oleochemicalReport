# Akurasi transkripsi suara: prompt bersih, large-v3, dan Ketik koreksi

## Masalah

Voice note "pengembangan sistem maintenance" (4 detik) ditranskripsi menjadi:

`PENGTNPA-PROD-PENGANGAN SISTEM MAINTENANCE`

Penyebab utama:

1. Prompt Whisper memuat daftar nama/kode equipment. Pada voice note pendek,
   daftar tersebut membuat model berhalusinasi kode (mis. `EPE-PROD`) dan
   menempelkannya ke awal transkrip.
2. Model `whisper-large-v3-turbo` lebih cepat tetapi kurang akurat untuk
   potongan pendek.
3. Belum ada cara memperbaiki transkrip selain merekam ulang.

## Perbaikan

- Prompt transkripsi dibersihkan: hanya instruksi bahasa Indonesia yang alami
  dan mempertahankan istilah teknis yang diucapkan.
- Glossary nama alat/equipment dimatikan secara bawaan
  (`VOICE_TRANSCRIPTION_GLOSSARY_ENABLED=false`). Kode alat tidak lagi
  dimasukkan ke prompt.
- Model bawaan diganti ke `whisper-large-v3` (lebih akurat); turbo tetap bisa
  dipilih lewat env/config.
- Tombol **Ketik koreksi** ditambahkan. Setelah ditekan, teks yang diketik
  pengguna menggantikan transkrip dan ditampilkan ulang untuk disetujui.
- Jalur webhook menerima balasan teks `KOREKSI` sebagai alternatif.
- Label konfirmasi berubah menjadi "hasil koreksi" setelah pengguna mengoreksi.

## Berkas

- `config/voice.php`
- `app/Services/SpeechToTextService.php`
- `app/Services/Telegram/PendingTranscriptService.php`
- `app/Services/Telegram/VoiceTranscriptionService.php`
- `tests/Feature/VoiceTranscriptionTest.php`
- `docs/34-pesan-suara-telegram.md`

## Bukti uji

- `VoiceTranscriptionTest`: **9 test hijau, 30 assertions**.
- Test baru memastikan prompt default tidak memuat kode alat dan memastikan
  alur Ketik koreksi mengganti transkrip.
- Seluruh suite: **880 test hijau, 4.549 assertions**.
- `view:cache` dan `npm run build` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Model default berubah ke `whisper-large-v3`; pastikan provider Groq punya
  akses model tersebut (sudah tersedia di akun).
- Setelah merge dan deploy, uji ulang voice note "pengembangan sistem
  maintenance". Jika masih meleset, gunakan tombol **Ketik koreksi** atau
  ganti model lewat env.
- Berkas kotor sesi lain di copy DEV tidak ikut di-commit.
