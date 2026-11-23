<?php

/**
 * Konfigurasi transkripsi pesan suara Telegram (Groq Whisper).
 */
return [
    'enabled' => (bool) env('VOICE_TRANSCRIPTION_ENABLED', true),

    /*
    | Provider Groq yang dipakai. Kunci API diambil dari tabel ai_providers
    | (provider_type = groq, status healthy) agar tidak ada kunci di .env.
    */
    'provider_type' => env('VOICE_TRANSCRIPTION_PROVIDER', 'groq'),
    'endpoint'      => env('VOICE_TRANSCRIPTION_ENDPOINT', 'https://api.groq.com/openai/v1/audio/transcriptions'),
    'model'         => env('VOICE_TRANSCRIPTION_MODEL', 'whisper-large-v3-turbo'),
    'language'      => env('VOICE_TRANSCRIPTION_LANGUAGE', 'id'),
    'timeout_detik' => (int) env('VOICE_TRANSCRIPTION_TIMEOUT', 60),

    /*
    | Prompt awal untuk Whisper. Nama alat/kode akan ditambahkan otomatis
    | (maksimum beberapa puluh entri) agar istilah teknis lebih akurat.
    */
    'prompt' => env(
        'VOICE_TRANSCRIPTION_PROMPT',
        'Transkrip bahasa Indonesia untuk laporan maintenance pabrik oleochemical. '
        . 'Pertahankan kode alat, nomor equipment, nama bagian, dan istilah teknis apa adanya.'
    ),

    /*
    | Penyimpanan audio sementara. Audio dihapus setelah transkripsi.
    */
    'disk'      => env('VOICE_TRANSCRIPTION_DISK', 'local'),
    'folder'    => env('VOICE_TRANSCRIPTION_FOLDER', 'telegram-voices'),
    'max_bytes' => (int) env('VOICE_TRANSCRIPTION_MAX_BYTES', 20 * 1024 * 1024),

    /*
    | Batas durasi voice note (detik) dan masa berlaku transkrip yang menunggu
    | persetujuan pengguna.
    */
    'max_durasi_detik'    => (int) env('VOICE_TRANSCRIPTION_MAX_DURATION', 300),
    'pending_ttl_menit'   => (int) env('VOICE_TRANSCRIPTION_PENDING_TTL', 10),
    'rate_limit_per_menit' => (int) env('VOICE_TRANSCRIPTION_RATE_LIMIT', 5),

    /*
    | Jumlah nama alat dan kode yang dimasukkan ke prompt Whisper.
    */
    'glossary_limit' => (int) env('VOICE_TRANSCRIPTION_GLOSSARY_LIMIT', 30),
];
