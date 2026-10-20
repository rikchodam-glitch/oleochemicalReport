<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Cuti Terkirim</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl border border-slate-200 shadow-sm p-6 text-center">
        <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-700 flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="text-lg font-bold text-slate-900 mt-3">Cuti tercatat</h1>
        <p class="text-sm text-slate-500 mt-1">Terima kasih, {{ $hasil['nama'] ?? 'Teknisi' }}.</p>

        <div class="mt-5 rounded-lg bg-slate-50 border border-slate-100 p-4 text-left text-xs space-y-1.5">
            <div class="flex justify-between gap-3"><span class="text-slate-500">Periode</span><span class="font-medium text-slate-800">{{ $hasil['tanggal_mulai'] }} – {{ $hasil['tanggal_selesai'] }}</span></div>
            <div class="flex justify-between gap-3"><span class="text-slate-500">Jenis</span><span class="font-medium text-slate-800">{{ $hasil['jenis'] }}</span></div>
            <div class="flex justify-between gap-3"><span class="text-slate-500">Status</span><span class="font-semibold text-emerald-700">{{ $hasil['status'] }}</span></div>
        </div>

        <p class="text-xs text-slate-500 mt-4 leading-relaxed">
            Cuti langsung berlaku. Setelah login, Anda dapat mengubah atau menghapus cuti ini
            di menu <span class="font-semibold text-slate-700">Cuti Saya</span>.
        </p>

        @if($hasil['notif'] ?? false)
            <p class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2 mt-3">
                Notifikasi dan tautan halaman Cuti Saya sudah dikirim ke Telegram Anda.
            </p>
        @endif

        <a href="{{ url('/') }}" class="inline-block mt-5 text-xs font-semibold text-teal-700 hover:text-teal-800">
            Tutup halaman
        </a>
    </div>
</body>
</html>
