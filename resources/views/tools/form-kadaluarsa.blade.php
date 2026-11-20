<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tautan Tidak Berlaku - Management Tools</title>
    @vite('resources/css/app.css')
    <style>body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 p-6 text-center space-y-3">
        <div class="w-14 h-14 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mx-auto">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>

        <h1 class="text-lg font-bold text-slate-900">Tautan Tidak Berlaku</h1>
        <p class="text-sm text-slate-600">{{ $pesan ?? 'Tautan tidak valid atau sudah kedaluwarsa.' }}</p>
        <p class="text-[11px] text-slate-400">Minta tautan baru melalui bot Telegram: /pinjam, /pakai, atau /selesai.</p>
    </div>
</body>
</html>
