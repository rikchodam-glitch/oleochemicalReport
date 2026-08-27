@extends('layouts.app')

@section('title', 'Import IT Assets dari Excel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Import Excel</span>
@endsection

@section('content')
    <div class="max-w-2xl space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Upload File Excel</h3>
            <p class="text-sm text-slate-500 mb-4">
                Upload file "List Perangkat IT Oleochemicals.xlsx". Format .xlsx atau .xls (maks 10MB).
                <br>Sheet yang diproses: PC, LAPTOP, PRINTER, CCTV APPROVED, NVR, Access Door & Absensi,
                WIFI, MODUL JARINGAN, UPS.
            </p>

            @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('it-assets.import.execute') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div class="flex items-center gap-4">
                    <input type="file" name="file" accept=".xlsx,.xls" required
                           class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4
                                  file:rounded-lg file:border-0 file:text-sm file:font-medium
                                  file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                </div>
                <div class="flex gap-3">
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Jalankan Import
                    </button>
                    <a href="{{ route('it-assets.index') }}"
                       class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection
