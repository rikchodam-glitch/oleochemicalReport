@extends('layouts.app')

@section('title', 'Denah IT')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Denah IT</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.layouts.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah Denah
    </a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-600">
            Kelola denah (layout) perangkat IT. Buat beberapa denah, lalu tempatkan setiap perangkat IT pada denah
            sesuai lokasi fisiknya. Setiap perangkat hanya bisa berada di satu denah.
        </p>
    </div>

    @if($layouts->isEmpty())
        <div class="bg-white rounded-xl border border-dashed border-slate-300 p-12 text-center">
            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-slate-500">Belum ada denah.</p>
            <a href="{{ route('it-assets.layouts.create') }}"
               class="inline-flex items-center gap-2 mt-4 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                Tambah Denah Pertama
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($layouts as $layout)
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col">
                    <div class="relative h-40 bg-slate-100 flex items-center justify-center">
                        @if($layout->file_type === 'image' && $layout->width && $layout->height)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($layout->file_path) }}"
                                 alt="{{ $layout->label ?: $layout->name }}"
                                 class="w-full h-full object-contain">
                        @else
                            <svg class="w-12 h-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <span class="absolute bottom-2 right-2 px-2 py-0.5 text-[10px] font-medium bg-white text-slate-600 rounded shadow">{{ strtoupper($layout->file_type) }}</span>
                        @endif
                    </div>
                    <div class="p-4 flex flex-col flex-1">
                        <h3 class="text-base font-semibold text-slate-900">{{ $layout->label ?: $layout->name }}</h3>
                        @if($layout->description)
                            <p class="text-xs text-slate-500 mt-0.5 line-clamp-2">{{ $layout->description }}</p>
                        @endif
                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
                            <a href="{{ route('it-assets.layouts.show', $layout) }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                                Buka Denah
                            </a>
                            <form method="POST" action="{{ route('it-assets.layouts.destroy', $layout) }}"
                                  onsubmit="return confirm('Hapus denah ini beserta posisi & jalurnya?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-slate-400 hover:text-red-600 transition-colors" title="Hapus denah">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
