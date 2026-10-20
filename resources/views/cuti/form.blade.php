<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Cuti — {{ $teknisi?->name ?? 'Teknisi' }}</title>
    @vite('resources/css/app.css')
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen py-6 px-4">
    <div class="w-full max-w-xl mx-auto space-y-4">
        <div class="text-center">
            <h1 class="text-xl font-bold text-slate-900">Form Cuti Teknisi</h1>
            <p class="text-xs text-slate-500 mt-1">Oleochemical Maintenance</p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-4">
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Teknisi</p>
                    <p class="text-base font-semibold text-slate-900">{{ $teknisi?->name ?? '-' }}</p>
                    <p class="text-xs text-slate-500 mt-0.5">
                        NIK {{ $teknisi?->nik ?? '-' }} ·
                        {{ \App\Models\Technician::GROUPS[$teknisi?->group] ?? ($teknisi?->group ?? '-') }}
                    </p>
                </div>
                <span class="inline-flex px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-semibold ring-1 ring-emerald-200">
                    Tautan aman
                </span>
            </div>

            @if($errors->any())
                <div class="mt-4 rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-xs text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            @if($alasanAwal)
                <div class="mt-4 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2 text-xs text-blue-700">
                    Alasan dari Telegram sudah diisi otomatis. Anda bisa mengubahnya sebelum mengirim.
                </div>
            @endif

            <form method="POST" action="{{ route('cuti.simpan', ['token' => $token]) }}" class="mt-4 space-y-4">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="tanggal_mulai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" id="tanggal_mulai"
                               value="{{ old('tanggal_mulai', now()->toDateString()) }}"
                               min="{{ now()->toDateString() }}" required
                               class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        @error('tanggal_mulai')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="tanggal_selesai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" id="tanggal_selesai"
                               value="{{ old('tanggal_selesai', now()->toDateString()) }}"
                               min="{{ now()->toDateString() }}" required
                               class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        @error('tanggal_selesai')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label for="jenis" class="block text-xs font-medium text-slate-600 mb-1">Jenis cuti</label>
                    <select name="jenis" id="jenis" required
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        @foreach($jenisCuti as $nilai => $label)
                            <option value="{{ $nilai }}" @selected(old('jenis', 'tahunan') === $nilai)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('jenis')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="alasan" class="block text-xs font-medium text-slate-600 mb-1">Alasan / keterangan</label>
                    <textarea name="alasan" id="alasan" rows="4" maxlength="1000"
                              placeholder="Contoh: cuti keluarga, sakit, atau keperluan dinas"
                              class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('alasan', $alasanAwal) }}</textarea>
                    <p class="text-[11px] text-slate-400 mt-1">Opsional, maksimal 1.000 karakter.</p>
                    @error('alasan')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="rounded-lg bg-slate-50 border border-slate-100 px-3 py-2 text-[11px] text-slate-500 leading-relaxed">
                    Cuti langsung tercatat dan berlaku tanpa persetujuan admin. Target man hour
                    pada tanggal tersebut langsung disesuaikan. Setelah login, Anda dapat
                    mengubah atau menghapus cuti di menu <span class="font-semibold text-slate-700">Cuti Saya</span>.
                    Cuti yang beririsan dengan cuti lain akan ditolak sistem.
                </div>

                <button type="submit"
                        class="w-full py-2.5 rounded-lg text-sm font-semibold text-white"
                        style="background-color:#0E9E8E;">
                    Kirim pengajuan cuti
                </button>
            </form>

            <p class="text-[10px] text-slate-400 text-center mt-3">
                Tautan berlaku sampai {{ $kedaluwarsa->format('d/m/Y H:i') }} dan hanya bisa dipakai sekali.
            </p>
        </div>

        @if($riwayat->isNotEmpty())
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                <p class="text-xs font-semibold text-slate-700 mb-2">Riwayat cuti terakhir</p>
                <div class="space-y-1.5">
                    @foreach($riwayat as $item)
                        <div class="flex items-center justify-between gap-3 text-[11px]">
                            <span class="text-slate-600">
                                {{ $item->tanggal_mulai->format('d/m/Y') }} – {{ $item->tanggal_selesai->format('d/m/Y') }}
                                · {{ $item->labelJenis() }}
                            </span>
                            <span class="font-medium {{ $item->status === 'disetujui' ? 'text-emerald-700' : ($item->status === 'ditolak' ? 'text-rose-700' : 'text-amber-700') }}">
                                {{ $item->labelStatus() }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const mulai = document.getElementById('tanggal_mulai');
            const selesai = document.getElementById('tanggal_selesai');

            if (mulai && selesai) {
                mulai.addEventListener('change', function () {
                    if (!selesai.value || selesai.value < mulai.value) {
                        selesai.value = mulai.value;
                    }
                });
            }
        });
    </script>
</body>
</html>
