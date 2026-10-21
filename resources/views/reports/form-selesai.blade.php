<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Terkirim</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-sm p-6 text-center">
        <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-700 flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-lg font-bold text-slate-900 mt-3">Laporan terkirim</h1>
        <p class="text-sm text-slate-500 mt-1">{{ $hasil['jumlah'] ?? 0 }} laporan berhasil disimpan.</p>
        <p class="text-xs text-slate-500 mt-4 leading-relaxed">
            Laporan sudah masuk ke sistem. Tautan form ini sudah tidak berlaku.
            Untuk melihat laporan, buka aplikasi dan login dengan akun Anda.
        </p>
    </div>
</body>
</html>
