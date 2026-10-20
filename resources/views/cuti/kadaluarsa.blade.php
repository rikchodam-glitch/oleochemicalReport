<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tautan Cuti Tidak Berlaku</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-sm p-6 text-center">
        <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-700 flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/>
            </svg>
        </div>
        <h1 class="text-lg font-bold text-slate-900 mt-3">Tautan tidak berlaku</h1>
        <p class="text-sm text-slate-500 mt-2 leading-relaxed">{{ $pesan }}</p>
        <p class="text-xs text-slate-400 mt-4">
            Buka Telegram, kirim <span class="font-semibold text-slate-600">/cuti</span> atau
            <span class="font-semibold text-slate-600">/cuti alasan Anda</span> untuk mendapatkan tautan baru.
        </p>
    </div>
</body>
</html>
