<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selesai - Management Tools</title>
    @vite('resources/css/app.css')
    <style>body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 p-6 text-center space-y-3">
        <div class="w-14 h-14 rounded-full bg-green-100 text-green-700 flex items-center justify-center mx-auto">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>

        <h1 class="text-lg font-bold text-slate-900">{{ $hasil['judul'] ?? 'Selesai' }}</h1>
        <p class="text-sm text-slate-600">{{ $hasil['pesan'] ?? 'Transaksi sudah dicatat.' }}</p>

        @if(! empty($hasil['kode']))
            <p class="text-xs text-slate-400">Kode pinjaman: {{ $hasil['kode'] }}</p>
        @endif

        <p class="text-[11px] text-slate-400">
            Tautan ini sudah tidak berlaku. Untuk transaksi berikutnya, gunakan /pakai atau /selesai di Telegram.
        </p>
    </div>
</body>
</html>
