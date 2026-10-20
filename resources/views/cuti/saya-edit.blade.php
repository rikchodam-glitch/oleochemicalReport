@extends('layouts.app')

@section('title', 'Edit Cuti')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cuti.saya') }}" class="hover:text-slate-700">Cuti Saya</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Edit</span>
@endsection

@section('content')
    <div class="max-w-2xl space-y-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Edit Cuti</h2>
            <p class="text-sm text-slate-500 mt-1">
                Perubahan langsung berlaku dan mengubah target man hour pada tanggal terkait.
            </p>
        </div>

        @if($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('cuti.saya.update', $cuti) }}"
              class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="tanggal_mulai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal mulai</label>
                    <input type="date" name="tanggal_mulai" id="tanggal_mulai"
                           value="{{ old('tanggal_mulai', $cuti->tanggal_mulai->format('Y-m-d')) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    @error('tanggal_mulai')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tanggal_selesai" class="block text-xs font-medium text-slate-600 mb-1">Tanggal selesai</label>
                    <input type="date" name="tanggal_selesai" id="tanggal_selesai"
                           value="{{ old('tanggal_selesai', $cuti->tanggal_selesai->format('Y-m-d')) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    @error('tanggal_selesai')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="jenis" class="block text-xs font-medium text-slate-600 mb-1">Jenis cuti</label>
                <select name="jenis" id="jenis" required
                        class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    @foreach($jenisCuti as $nilai => $label)
                        <option value="{{ $nilai }}" @selected(old('jenis', $cuti->jenis) === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('jenis')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="alasan" class="block text-xs font-medium text-slate-600 mb-1">Alasan / keterangan</label>
                <textarea name="alasan" id="alasan" rows="4" maxlength="1000"
                          class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('alasan', $cuti->alasan) }}</textarea>
                @error('alasan')<p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:#0E9E8E;">
                    Simpan perubahan
                </button>
                <a href="{{ route('cuti.saya') }}" class="text-sm text-slate-500 hover:text-slate-700">Batal</a>
            </div>
        </form>

        <form method="POST" action="{{ route('cuti.saya.destroy', $cuti) }}"
              class="bg-white rounded-xl border border-rose-100 p-4 flex items-center justify-between gap-3"
              onsubmit="return confirm('Hapus cuti ini? Target man hour akan berubah.')">
            @csrf
            @method('DELETE')
            <p class="text-xs text-slate-500">Menghapus cuti akan mengembalikan target man hour pada tanggal tersebut.</p>
            <button type="submit" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-sm font-semibold text-rose-700 hover:bg-rose-100 whitespace-nowrap">
                Hapus cuti
            </button>
        </form>
    </div>
@endsection
