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
    'model'         => env('VOICE_TRANSCRIPTION_MODEL', 'whisper-large-v3'),
    'language'      => env('VOICE_TRANSCRIPTION_LANGUAGE', 'id'),
    'timeout_detik' => (int) env('VOICE_TRANSCRIPTION_TIMEOUT', 60),

    /*
    | Prompt awal untuk Whisper. Nama alat/kode akan ditambahkan otomatis
    | (maksimum beberapa puluh entri) agar istilah teknis lebih akurat.
    */
    'prompt' => env(
        'VOICE_TRANSCRIPTION_PROMPT',
        'Transkripsi bahasa Indonesia yang jelas dan alami. Pertahankan istilah teknis yang diucapkan.'
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
    | Glossary nama alat/equipment dimatikan secara bawaan. Daftar kode yang
    | terlalu banyak membuat Whisper berhalusinasi kode pada voice note pendek
    | (kejadian nyata: "pengembangan sistem maintenance" menjadi
    | "PENGTNPA-PROD-..."). Aktifkan hanya bila benar-benar diperlukan.
    */
    'glossary_enabled' => (bool) env('VOICE_TRANSCRIPTION_GLOSSARY_ENABLED', false),
    'glossary_limit'   => (int) env('VOICE_TRANSCRIPTION_GLOSSARY_LIMIT', 30),
];
