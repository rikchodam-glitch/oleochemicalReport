@extends('layouts.app')

@section('title', 'Daftar IP — IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Daftar IP</span>
@endsection

@section('content')
    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Tambah Subnet (Generate IP)</h3>
            <p class="text-[11px] text-slate-400 mt-1">Masukkan VLAN dan subnet (/24 dst). Network otomatis dihitung 192.168.[VLAN].0, lalu host usable dibuat otomatis.</p>
        </div>
        <form method="POST" action="{{ route('it-assets.ip-addresses.store') }}" class="p-6 grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">VLAN <span class="text-red-500">*</span></label>
                <input type="number" name="vlan" value="{{ old('vlan') }}" min="1" max="255"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="contoh: 41" required>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Subnet <span class="text-red-500">*</span></label>
                <select name="cidr"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white" required>
                    @foreach(range(24, 30) as $c)
                        <option value="{{ $c }}" {{ old('cidr') == $c ? 'selected' : '' }}>/{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Nama</label>
                <input type="text" name="name" value="{{ old('name') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="opsional">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Keterangan</label>
                <input type="text" name="description" value="{{ old('description') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="opsional">
            </div>
            <div>
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Generate IP</button>
            </div>
        </form>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Subnet Tersedia</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                        <th class="px-6 py-3">VLAN</th>
                        <th class="px-6 py-3">Network</th>
                        <th class="px-6 py-3">Netmask</th>
                        <th class="px-6 py-3">Broadcast</th>
                        <th class="px-6 py-3">IP Tersedia</th>
                        <th class="px-6 py-3">IP Terpakai</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($networks as $network)
                        <tr>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->vlan }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-800">{{ $network->network }}/{{ $network->cidr }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-600">{{ $network->netmask }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-600">{{ $network->broadcast }}</td>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->available_count }}</td>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->assigned_count }}</td>
                            <td class="px-6 py-3 text-right">
                                <button type="button" class="ip-toggle px-3 py-1.5 text-xs font-medium text-blue-600 hover:text-blue-700"
                                        data-network="{{ $network->id }}" data-url="{{ route('it-assets.ip-addresses.show', $network) }}">Lihat IP</button>
                                <form method="POST" action="{{ route('it-assets.ip-addresses.destroy', $network) }}" class="inline"
                                      onsubmit="return confirm('Hapus subnet {{ $network->prefix() }} beserta semua IP-nya?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1.5 text-xs font-medium text-red-600 hover:text-red-700">Hapus</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="ip-detail hidden" data-detail="{{ $network->id }}">
                            <td colspan="7" class="px-6 py-4 bg-slate-50">
                                <p class="ip-placeholder text-xs text-slate-400 mb-2">Memuat daftar IP&hellip;</p>
                                <div class="hidden ip-list"></div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-sm text-slate-400">Belum ada subnet. Tambahkan lewat form di atas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @push('scripts')
    <script>
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ipStatusBadge(status) {
        if (status === 'assigned') {
            return '<span class="px-2 py-0.5 text-[11px] font-medium bg-blue-50 text-blue-700 rounded">Terpakai</span>';
        }
        if (status === 'reserved') {
            return '<span class="px-2 py-0.5 text-[11px] font-medium bg-slate-100 text-slate-600 rounded">Reserved</span>';
        }
        return '<span class="px-2 py-0.5 text-[11px] font-medium bg-green-50 text-green-700 rounded">Tersedia</span>';
    }

    function loadIpDetail(btn) {
        const detail = document.querySelector('[data-detail="' + btn.dataset.network + '"]');
        const list = detail.querySelector('.ip-list');
        const placeholder = detail.querySelector('.ip-placeholder');
        fetch(btn.dataset.url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                placeholder.classList.add('hidden');
                list.classList.remove('hidden');
                list.dataset.loaded = '1';
                list.innerHTML = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">'
                    + data.ips.map(function (ip) {
                        return '<div class="flex items-center justify-between gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs">'
                            + '<span class="font-mono text-slate-800">' + escapeHtml(ip.address) + '</span>'
                            + '<span class="flex items-center gap-2">' + ipStatusBadge(ip.status)
                            + (ip.asset ? '<span class="text-slate-500">' + escapeHtml(ip.asset) + '</span>' : '')
                            + '</span></div>';
                    }).join('') + '</div>';
            })
            .catch(function () {
                placeholder.textContent = 'Gagal memuat daftar IP.';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ip-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const detail = document.querySelector('[data-detail="' + btn.dataset.network + '"]');
                const list = detail.querySelector('.ip-list');
                if (detail.classList.contains('hidden')) {
                    detail.classList.remove('hidden');
                    btn.textContent = 'Tutup IP';
                    if (list.dataset.loaded !== '1') {
                        loadIpDetail(btn);
                    }
                } else {
                    detail.classList.add('hidden');
                    btn.textContent = 'Lihat IP';
                }
            });
        });
    });
    </script>
    @endpush
@endsection
