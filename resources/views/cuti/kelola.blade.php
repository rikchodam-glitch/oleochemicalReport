@extends('layouts.app')

@section('title', 'Kelola Cuti Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.index') }}" class="hover:text-slate-700">Teknisi</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.show', $teknisi) }}" class="hover:text-slate-700">{{ $teknisi->name }}</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Cuti</span>
@endsection

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Kelola Cuti — {{ $teknisi->name }}</h2>
                <p class="text-sm text-slate-500 mt-1">
                    NIK {{ $teknisi->nik ?? '-' }} ·
                    {{ \App\Models\Technician::GROUPS[$teknisi->group] ?? ($teknisi->group ?? '-') }} ·
                    {{ \App\Models\Technician::SECTIONS[$teknisi->section] ?? ($teknisi->section ?? '-') }}
                </p>
                <p class="text-xs text-slate-400 mt-1">
                    Cuti yang dicatat langsung berlaku dan langsung mengubah target man hour. Teknisi hanya bisa melihat.
                </p>
            </div>
            <a href="{{ route('technicians.show', $teknisi) }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Kembali ke profil teknisi</a>
        </div>

        @if(session('status'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('cuti.kelola.store', $teknisi) }}"
              class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
            @csrf
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold text-slate-900">Catat cuti baru</h3>
                <span class="text-[11px] text-slate-400">Langsung disetujui</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label for="tanggal_mulai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal mulai</label>
                    <input type="date" name="tanggal_mulai" id="tanggal_mulai" value="{{ old('tanggal_mulai', now()->toDateString()) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="tanggal_selesai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal selesai</label>
                    <input type="date" name="tanggal_selesai" id="tanggal_selesai" value="{{ old('tanggal_selesai', now()->toDateString()) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="jenis" class="block text-xs font-medium text-slate-600 mb-1">Jenis</label>
                    <select name="jenis" id="jenis" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        @foreach($jenisCuti as $nilai => $label)
                            <option value="{{ $nilai }}" @selected(old('jenis', 'tahunan') === $nilai)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="alasan" class="block text-xs font-medium text-slate-600 mb-1">Alasan</label>
                    <input type="text" name="alasan" id="alasan" value="{{ old('alasan') }}" maxlength="1000" placeholder="Opsional"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
            </div>

            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:#0E9E8E;">
                Simpan cuti
            </button>
        </form>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Periode</th>
                            <th class="px-4 py-3 text-left font-semibold">Jenis</th>
                            <th class="px-4 py-3 text-left font-semibold">Alasan</th>
                            <th class="px-4 py-3 text-left font-semibold">Dicatat</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $cuti)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                    {{ $cuti->tanggal_mulai->format('d/m/Y') }} – {{ $cuti->tanggal_selesai->format('d/m/Y') }}
                                    <p class="text-slate-400">{{ $cuti->jumlahHari() }} hari</p>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium text-slate-700">{{ $cuti->labelJenis() }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-sm">{{ $cuti->alasan ?: '-' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{{ $cuti->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('cuti.kelola.edit', $cuti) }}"
                                       class="inline-flex px-2.5 py-1 rounded-md border border-slate-200 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Edit</a>
                                    <form method="POST" action="{{ route('cuti.kelola.destroy', $cuti) }}" class="inline"
                                          onsubmit="return confirm('Hapus cuti ini? Target man hour akan berubah.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2.5 py-1 rounded-md bg-rose-50 border border-rose-200 text-[11px] font-semibold text-rose-700 hover:bg-rose-100">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-400">Belum ada cuti untuk teknisi ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-400">Total {{ number_format($daftar->total(), 0, ',', '.') }} cuti.</p>
                {{ $daftar->links() }}
            </div>
        </div>
    </div>
@endsection
