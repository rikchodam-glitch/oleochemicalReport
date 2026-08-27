@extends('layouts.app')

@section('title', 'Notifikasi IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Notifikasi</span>
@endsection

@php
    $typeLabels = [
        'offline'        => ['label' => 'Offline',        'class' => 'bg-red-100 text-red-700'],
        'high_latency'   => ['label' => 'Ping Tinggi',    'class' => 'bg-amber-100 text-amber-700'],
        'recovered'      => ['label' => 'Kembali Online', 'class' => 'bg-green-100 text-green-700'],
        'snapshot_error' => ['label' => 'Snapshot Error', 'class' => 'bg-purple-100 text-purple-700'],
    ];
@endphp

@section('header-actions')
    <a href="{{ route('it-assets.notifications', ['unread' => request()->boolean('unread') ? 0 : 1]) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        {{ request()->boolean('unread') ? 'Tampilkan Semua' : 'Tampilkan Belum Dibaca' }}
        @if($unreadNotificationCount > 0)
            <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full">{{ $unreadNotificationCount }}</span>
        @endif
    </a>
    @if($unreadNotificationCount > 0)
        <form method="POST" action="{{ route('it-assets.notifications.read-all') }}" class="inline">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                Tandai Semua Dibaca
            </button>
        </form>
    @endif
@endsection

@section('content')
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Daftar Notifikasi</h3>
            <span class="text-xs text-slate-400">{{ $notifications->total() }} notifikasi</span>
        </div>

        @if($notifications->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-slate-400">
                Belum ada notifikasi.
            </div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($notifications as $notification)
                    @php $typeInfo = $typeLabels[$notification->type] ?? ['label' => ucfirst($notification->type), 'class' => 'bg-slate-100 text-slate-700']; @endphp
                    <div class="px-6 py-4 flex items-start gap-4 {{ $notification->is_read ? 'bg-white' : 'bg-blue-50/50' }}">
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded {{ $typeInfo['class'] }} shrink-0 mt-0.5">
                            {{ $typeInfo['label'] }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-slate-800 leading-relaxed">
                                {{ $notification->message }}
                            </p>
                            <p class="text-[11px] text-slate-400 mt-1">
                                {{ $notification->created_at->format('d/m/Y H:i:s') }}
                                @if($notification->itAsset)
                                    &middot;
                                    <a href="{{ route('it-assets.show', $notification->itAsset) }}" class="text-blue-500 hover:text-blue-700">
                                        {{ $notification->itAsset->tag_name ?? $notification->itAsset->name ?? ('#' . $notification->itAsset->id) }}
                                    </a>
                                @endif
                            </p>
                        </div>
                        @if(!$notification->is_read)
                            <form method="POST" action="{{ route('it-assets.notifications.read', $notification) }}">
                                @csrf
                                <button type="submit"
                                        class="text-xs text-blue-600 hover:text-blue-700 font-medium shrink-0">
                                    Tandai dibaca
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if($notifications->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
@endsection
