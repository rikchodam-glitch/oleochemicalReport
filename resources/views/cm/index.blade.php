@extends('layouts.app')

@section('title', 'Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Condition Monitoring</span>
@endsection

@section('content')
    <p class="text-slate-500 text-sm">Halaman utama Condition Monitoring — silakan pilih tab di atas.</p>
@endsection
