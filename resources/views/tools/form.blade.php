<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Alat - {{ $pemilik->name }}</title>
    @vite('resources/css/app.css')
    <style>body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen py-5 px-3">
@php
    $judul = in_array($purpose, [
        \App\Models\ToolToken::PURPOSE_KEEPER_PINJAM,
        \App\Models\ToolToken::PURPOSE_TEKNISI_PINJAM,
    ], true) ? 'Form Peminjaman Alat' : 'Form Pengembalian Alat';
@endphp

<div class="max-w-6xl mx-auto space-y-4">
    <div class="text-center">
        <h1 class="text-xl font-bold text-slate-900">{{ $judul }}</h1>
        <p class="text-xs text-slate-500 mt-1">
            {{ $pemilik->name }} &middot; NIK {{ $pemilik->nik ?: '-' }} &middot;
            {{ \App\Models\Technician::GROUPS[$pemilik->group] ?? ($pemilik->group ?: '-') }}
        </p>
        <p class="text-[11px] text-slate-400 mt-1">
            {{ $modeKeeper ? 'Akses tool keeper.' : 'Akses teknisi.' }}
            Tautan berlaku sampai {{ $kadaluarsa->format('d/m/Y H:i') }}.
            @if($token->sekaliPakai()) Tautan ini sekali pakai. @endif
        </p>
    </div>

    @if($errors->any())
        <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first() }}
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if(in_array($purpose, [
        \App\Models\ToolToken::PURPOSE_KEEPER_PINJAM,
        \App\Models\ToolToken::PURPOSE_TEKNISI_PINJAM,
    ], true))
        @include('tools.partials._form-pinjam')
    @else
        @include('tools.partials._form-kembali')
    @endif
</div>
</body>
</html>
