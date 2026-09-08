@extends('layouts.app')

@section('title', 'Tambah Denah IT')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.layouts.index') }}" class="hover:text-slate-700">Denah IT</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.layouts.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        &larr; Kembali ke Daftar Denah
    </a>
@endsection

@section('content')
<form method="POST" action="{{ route('it-assets.layouts.store') }}" enctype="multipart/form-data"
      class="max-w-xl space-y-5">
    @csrf

    <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
        @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Nama Denah (wajib)</label>
            <input type="text" name="name" value="{{ old('name') }}" maxlength="255" required
                   placeholder="Mis. NPA, EPE, CES"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Label Tampilan (opsional)</label>
            <input type="text" name="label" value="{{ old('label') }}" maxlength="255"
                   placeholder="Mis. Denah Pabrik NPA"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Deskripsi (opsional)</label>
            <textarea name="description" rows="2"
                      class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="Catatan singkat tentang denah ini">{{ old('description') }}</textarea>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1.5">File Denah (PDF atau gambar PNG/JPG/WEBP, wajib)</label>
            <input type="file" name="file" accept=".pdf,image/png,image/jpeg,image/webp" required
                   class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-600 file:text-white hover:file:bg-blue-700">
            <p class="text-xs text-slate-400 mt-1.5">PDF dirender di browser (PDF.js). Disarankan denah satu halaman agar penempatan akurat.</p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1.5">Halaman PDF yang dipakai (untuk PDF, default 1)</label>
            <input type="number" name="page" value="{{ old('page', 1) }}" min="1"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('it-assets.layouts.index') }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">Batal</a>
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Simpan Denah</button>
        </div>
    </div>
</form>
@endsection
