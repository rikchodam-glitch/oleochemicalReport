@extends('layouts.app')

@section('title', 'AI Providers')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">AI Providers</span>
@endsection

@section('content')

    {{-- =========================================================
    Stat Bar — ringkasan angka paling penting dalam 24 jam
    ========================================================= --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-slate-900">{{ $stats['total_providers'] }}</p>
            <p class="text-xs text-slate-500 mt-1">AI Provider</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-amber-600">
                {{ $stats['healthy_providers'] }}/{{ $stats['total_providers'] }}
            </p>
            <p class="text-xs text-slate-500 mt-1">Provider Sehat</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-blue-600">{{ $stats['requests_24h'] }}</p>
            <p class="text-xs text-slate-500 mt-1">Request 24 Jam</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-green-600">
                {{ $stats['requests_24h'] > 0 ? number_format(($stats['success_24h'] / $stats['requests_24h']) * 100, 1) : '0' }}%
            </p>
            <p class="text-xs text-slate-500 mt-1">Tingkat Sukses</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-2">
            <p class="text-2xl font-bold text-violet-600">{{ number_format($stats['total_tokens_24h']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Total Token 24 Jam</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-slate-700">
                {{ $stats['avg_response_ms'] ? number_format($stats['avg_response_ms']) . 'ms' : '-' }}
            </p>
            <p class="text-xs text-slate-500 mt-1">Rata-rata Response</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 lg:col-span-1">
            <p class="text-2xl font-bold text-green-600">{{ number_format($stats['sisa_harian']) }}</p>
            <p class="text-xs text-slate-500 mt-1">Sisa Kapasitas</p>
        </div>
    </div>

    {{-- =========================================================
    Tombol aksi utama
    ========================================================= --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex gap-2">
            <button onclick="openModal('addProvider')"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                + Tambah Provider
            </button>
            <button onclick="testAllProviders()"
                    id="btn-test-all"
                    class="px-4 py-2 border border-green-500 hover:bg-green-50 text-green-600 text-sm font-medium rounded-lg transition-colors">
                Test Semua
            </button>
        </div>
        <form method="POST" action="{{ route('ai-providers.reset-quota') }}" class="inline">
            @csrf
            <button type="submit"
                    class="px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors"
                    onclick="return confirm('Reset kuota harian semua provider?')">
                Reset Quota Harian
            </button>
        </form>
    </div>

    {{-- =========================================================
    Provider Cards — menampilkan status, progress bar token harian
    dan bulanan, serta statistik 24 jam per provider
    ========================================================= --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
        @forelse($providers as $provider)
            @php
                $providerStats = $statsPerProvider[$provider->id] ?? null;
            @endphp
            <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                {{-- Header kartu --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-semibold text-slate-900 text-sm truncate">{{ $provider->name }}</h3>
                            <span class="text-xs text-slate-400 font-mono bg-slate-100 rounded px-1.5 py-0.5">
                                P{{ $provider->priority }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5 truncate">
                            {{ $provider->provider_type }} &middot; {{ $provider->model }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <x-status-badge :status="$provider->status" />
                    </div>
                </div>

                {{-- Progress bar token harian --}}
                <div class="space-y-1">
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-slate-500">Token Harian</span>
                        <span class="text-xs font-mono text-slate-700">
                            {{ number_format($provider->tokens_used_today) }}
                            / {{ number_format($provider->daily_token_limit) }}
                        </span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                        @php
                            $pctDaily = $provider->daily_usage_percent;
                            $colorDaily = $pctDaily >= 90 ? 'bg-red-500' : ($pctDaily >= 70 ? 'bg-amber-500' : 'bg-blue-500');
                        @endphp
                        <div class="{{ $colorDaily }} h-1.5 rounded-full transition-all"
                             style="width: {{ min($pctDaily, 100) }}%"></div>
                    </div>
                    <p class="text-xs text-slate-400 text-right">{{ $pctDaily }}% terpakai</p>
                </div>

                {{-- Progress bar token bulanan --}}
                <div class="space-y-1">
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-slate-500">Token Bulanan</span>
                        <span class="text-xs font-mono text-slate-700">
                            {{ number_format($provider->tokens_used_month) }}
                            / {{ number_format($provider->monthly_token_limit) }}
                        </span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                        @php
                            $pctMonth = $provider->monthly_usage_percent;
                            $colorMonth = $pctMonth >= 90 ? 'bg-red-500' : ($pctMonth >= 70 ? 'bg-amber-500' : 'bg-violet-500');
                        @endphp
                        <div class="{{ $colorMonth }} h-1.5 rounded-full transition-all"
                             style="width: {{ min($pctMonth, 100) }}%"></div>
                    </div>
                    <p class="text-xs text-slate-400 text-right">{{ $pctMonth }}% terpakai</p>
                </div>

                {{-- Statistik 24 jam per provider --}}
                @if($providerStats)
                    <div class="grid grid-cols-3 gap-2 pt-1 border-t border-slate-100">
                        <div class="text-center">
                            <p class="text-sm font-semibold text-slate-800">{{ $providerStats->total_calls }}</p>
                            <p class="text-xs text-slate-400">Request</p>
                        </div>
                        <div class="text-center">
                            <p class="text-sm font-semibold text-green-600">{{ $providerStats->success_count }}</p>
                            <p class="text-xs text-slate-400">Sukses</p>
                        </div>
                        <div class="text-center">
                            <p class="text-sm font-semibold text-slate-600">
                                {{ $providerStats->avg_response_ms ? number_format($providerStats->avg_response_ms) . 'ms' : '-' }}
                            </p>
                            <p class="text-xs text-slate-400">Avg Response</p>
                        </div>
                    </div>
                @else
                    <div class="pt-1 border-t border-slate-100">
                        <p class="text-xs text-slate-400 text-center">Belum ada pemakaian dalam 24 jam.</p>
                    </div>
                @endif

                {{-- Info last used & tombol aksi --}}
                <div class="flex items-center justify-between pt-1 border-t border-slate-100">
                    <p class="text-xs text-slate-400">
                        @if($provider->last_used_at)
                            Terakhir: {{ $provider->last_used_at->diffForHumans() }}
                        @elseif($provider->last_health_check)
                            Health check: {{ $provider->last_health_check->diffForHumans() }}
                        @else
                            Belum pernah digunakan
                        @endif
                    </p>
                    <div class="flex gap-2">
                        <button onclick="testProvider({{ $provider->id }})"
                                class="text-xs text-green-600 hover:text-green-700 font-medium">
                            Test
                        </button>
                        <button onclick="editProvider({{ $provider->id }})"
                                class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                            Edit
                        </button>
                        <button onclick="deleteProvider({{ $provider->id }})"
                                class="text-xs text-red-500 hover:text-red-600 font-medium">
                            Hapus
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="md:col-span-2 bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-400">
                Belum ada AI Provider. Tambahkan provider pertama.
            </div>
        @endforelse
    </div>

    {{-- =========================================================
    Breakdown per request_type — ringkasan jenis pekerjaan AI
    ========================================================= --}}
    @if($statsByType->isNotEmpty())
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-medium text-slate-900">Breakdown per Jenis Tugas (24 Jam)</h3>
                <span class="text-xs text-slate-400">{{ $stats['requests_24h'] }} total request</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Jenis Tugas
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Total Call
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Sukses
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Error
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Total Token
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Rata-rata Token
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Avg Response
                            </th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">
                                Tingkat Sukses
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($statsByType as $stat)
                            @php
                                $successRate = $stat->total_calls > 0
                                    ? round(($stat->success_count / $stat->total_calls) * 100, 1)
                                    : 0;
                                $rateColor = $successRate >= 90 ? 'text-green-600' : ($successRate >= 70 ? 'text-amber-600' : 'text-red-600');
                                $barColor  = $successRate >= 90 ? 'bg-green-500' : ($successRate >= 70 ? 'bg-amber-500' : 'bg-red-500');
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3">
                                    <span class="font-mono text-xs bg-slate-100 text-slate-700 rounded px-2 py-0.5">
                                        {{ $stat->request_type ?? '(tidak diketahui)' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-slate-800">
                                    {{ number_format($stat->total_calls) }}
                                </td>
                                <td class="px-5 py-3 text-right text-green-600">
                                    {{ number_format($stat->success_count) }}
                                </td>
                                <td class="px-5 py-3 text-right {{ $stat->error_count > 0 ? 'text-red-500' : 'text-slate-400' }}">
                                    {{ number_format($stat->error_count) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-slate-700">
                                    {{ number_format($stat->total_tokens) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-slate-600">
                                    {{ number_format($stat->avg_tokens) }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600">
                                    {{ $stat->avg_response_ms ? number_format($stat->avg_response_ms) . 'ms' : '-' }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                            <div class="{{ $barColor }} h-1.5 rounded-full"
                                                 style="width: {{ $successRate }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium {{ $rateColor }} w-12 text-right">
                                            {{ $successRate }}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- =========================================================
    Baris bawah: Log terbaru & Alias yang Dipelajari
    ========================================================= --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Log Terbaru — diperkaya dengan kolom request_type & response_time --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-medium text-slate-900">Log Pemakaian Terbaru</h3>
                <span class="text-xs text-slate-400">30 entri terakhir</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Provider
                            </th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Jenis
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Token
                            </th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Resp.
                            </th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Status
                            </th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">
                                Waktu
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($recentLogs as $log)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-4 py-2.5 text-slate-600 text-xs">
                                    {{ $log->provider?->name ?? '-' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($log->request_type)
                                        <span class="font-mono text-xs bg-slate-100 text-slate-600 rounded px-1.5 py-0.5">
                                            {{ $log->request_type }}
                                        </span>
                                    @else
                                        <span class="text-slate-300 text-xs">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs text-slate-700">
                                    {{ number_format($log->tokens_used) }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-xs text-slate-500">
                                    {{ $log->response_time_ms ? number_format($log->response_time_ms) . 'ms' : '—' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :status="$log->status" />
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-400 whitespace-nowrap">
                                    {{ $log->created_at->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-slate-400 text-sm">
                                    Belum ada pemakaian.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Alias yang Dipelajari --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="font-medium text-slate-900">Alias yang Dipelajari</h3>
                <span class="text-xs text-slate-400">50 terbaru</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Alias</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Mapping</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($aliases as $alias)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3 font-mono text-sm text-slate-900">{{ $alias->alias_text }}</td>
                                <td class="px-5 py-3 text-xs text-slate-600">
                                    {{ $alias->asset?->tech_ident_no ?? $alias->area?->code ?? '-' }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-badge :status="$alias->status" />
                                </td>
                                <td class="px-5 py-3">
                                    @if($alias->status === 'pending')
                                        <div class="flex gap-2">
                                            <form method="POST"
                                                  action="{{ route('ai-providers.aliases.confirm', $alias) }}"
                                                  class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="text-green-600 hover:text-green-700 text-xs font-medium"
                                                        onclick="return confirm('Konfirmasi alias ini?')">
                                                    Konfirmasi
                                                </button>
                                            </form>
                                            <form method="POST"
                                                  action="{{ route('ai-providers.aliases.reject', $alias) }}"
                                                  class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="text-red-600 hover:text-red-700 text-xs font-medium"
                                                        onclick="return confirm('Tolak alias ini?')">
                                                    Tolak
                                                </button>
                                            </form>
                                        </div>
                                    @elseif($alias->status === 'confirmed')
                                        <span class="text-xs text-slate-400">Dikonfirmasi</span>
                                    @elseif($alias->status === 'rejected')
                                        <span class="text-xs text-slate-400">Ditolak</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-center text-slate-400 text-sm">
                                    Belum ada alias.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- =========================================================
    Modal Edit Provider
    ========================================================= --}}
    <x-modal name="editProvider" title="Edit Provider AI" maxWidth="lg">
        <form method="POST" id="editProviderForm" action="" class="space-y-4">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Nama Provider</label>
                    <input type="text" name="name" id="edit_name" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tipe</label>
                    <select name="provider_type" id="edit_provider_type" required
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="groq">Groq</option>
                        <option value="ollama">Ollama</option>
                        <option value="openai">OpenAI</option>
                    </select>
                </div>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Model</label>
                <input type="text" name="model" id="edit_model" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">
                    API Key
                    <span class="text-slate-400 font-normal text-xs ml-1">(kosongkan jika tidak ingin mengganti)</span>
                </label>
                <input type="password" name="api_key_encrypted" id="edit_api_key"
                       placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                       autocomplete="new-password"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Endpoint URL</label>
                <input type="url" name="endpoint_url" id="edit_endpoint_url"
                       placeholder="https://api.groq.com/openai/v1/chat/completions"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Prioritas</label>
                    <input type="number" name="priority" id="edit_priority" min="1"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Status</label>
                    <select name="status" id="edit_status"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="healthy">Sehat</option>
                        <option value="exhausted">Quota Habis</option>
                        <option value="error">Error</option>
                        <option value="disabled">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Batas Bulanan</label>
                    <input type="number" name="monthly_token_limit" id="edit_monthly_token_limit"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Batas Harian</label>
                    <input type="number" name="daily_token_limit" id="edit_daily_token_limit"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Simpan Perubahan
                </button>
                <button type="button" onclick="closeModal('editProvider')"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                    Batal
                </button>
            </div>
        </form>
    </x-modal>

    {{-- =========================================================
    Modal Tambah Provider
    ========================================================= --}}
    <x-modal name="addProvider" title="Tambah Provider AI" maxWidth="lg">
        <form method="POST" action="{{ route('ai-providers.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Nama Provider</label>
                    <input type="text" name="name" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="Groq Primary">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tipe</label>
                    <select name="provider_type" required
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="groq">Groq</option>
                        <option value="ollama">Ollama</option>
                        <option value="openai">OpenAI</option>
                    </select>
                </div>
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Model</label>
                <input type="text" name="model" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="llama-3.3-70b-versatile">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">API Key</label>
                <input type="password" name="api_key_encrypted"
                       autocomplete="new-password"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">
                    Endpoint URL
                    <span class="text-slate-400 font-normal text-xs ml-1">(opsional, default Groq)</span>
                </label>
                <input type="url" name="endpoint_url"
                       placeholder="https://api.groq.com/openai/v1/chat/completions"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Prioritas</label>
                    <input type="number" name="priority" value="1" min="1"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Status</label>
                    <select name="status"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="healthy">Sehat</option>
                        <option value="disabled">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Batas Bulanan</label>
                    <input type="number" name="monthly_token_limit" value="10000000"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Batas Harian</label>
                    <input type="number" name="daily_token_limit" value="500000"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Simpan
                </button>
                <button type="button" onclick="closeModal('addProvider')"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                    Batal
                </button>
            </div>
        </form>
    </x-modal>

@endsection

@push('scripts')
<script>
// Data provider dari server — dipakai oleh editProvider()
const providerData = @json($providers->keyBy('id'));
const baseUrl = '{{ url('ai-providers') }}';

/**
 * Test semua provider sekaligus via AJAX.
 */
function testAllProviders() {
    if (!confirm('Test semua provider AI? Ini akan melakukan HTTP request nyata ke setiap provider.')) return;

    const btn = document.getElementById('btn-test-all');
    btn.disabled = true;
    btn.textContent = 'Testing...';

    fetch('{{ route('ai-providers.test-all') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => {
        if (!res.ok) throw new Error('Server error ' + res.status);
        return res.json();
    })
    .then(data => {
        const results = data.results || [];
        const success = results.filter(r => r.success).length;
        const failed  = results.filter(r => !r.success);

        let msg = success + '/' + results.length + ' provider berhasil.';
        if (failed.length > 0) {
            msg += '\n\nGagal:';
            failed.forEach(r => {
                msg += '\n- ' + r.name + ': ' + (r.error || r.message || 'error tidak diketahui');
            });
        }
        alert(msg);
        location.reload();
    })
    .catch(err => {
        alert('Gagal test: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Test Semua';
    });
}

/**
 * Buka modal edit dan isi field dengan data provider yang dipilih.
 *
 * @param {number} id ID provider yang akan diedit
 */
function editProvider(id) {
    const p = providerData[id];
    if (!p) {
        alert('Data provider tidak ditemukan.');
        return;
    }

    document.getElementById('editProviderForm').action = baseUrl + '/' + id;

    document.getElementById('edit_name').value                 = p.name                 || '';
    document.getElementById('edit_provider_type').value        = p.provider_type        || 'groq';
    document.getElementById('edit_model').value                = p.model                || '';
    document.getElementById('edit_endpoint_url').value         = p.endpoint_url         || '';
    document.getElementById('edit_priority').value             = p.priority             || 1;
    document.getElementById('edit_status').value               = p.status               || 'healthy';
    document.getElementById('edit_monthly_token_limit').value  = p.monthly_token_limit  || 0;
    document.getElementById('edit_daily_token_limit').value    = p.daily_token_limit    || 0;
    // Kosongkan API key — admin isi ulang hanya jika ingin mengganti
    document.getElementById('edit_api_key').value = '';

    openModal('editProvider');
}

/**
 * Test koneksi ke satu provider via AJAX.
 *
 * @param {number} id ID provider yang akan ditest
 */
function testProvider(id) {
    if (!confirm('Test koneksi ke provider ini? Akan melakukan HTTP request nyata ke API.')) return;

    const btn = event.target;
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '...';

    fetch(baseUrl + '/' + id + '/test', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => {
        if (!res.ok) throw new Error('Server error ' + res.status);
        return res.json();
    })
    .then(data => {
        alert(data.message || (data.success ? 'Berhasil' : 'Gagal'));
        location.reload();
    })
    .catch(err => {
        alert('Error: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = origText;
    });
}

/**
 * Hapus provider via AJAX dengan konfirmasi.
 *
 * @param {number} id ID provider yang akan dihapus
 */
function deleteProvider(id) {
    if (!confirm('Hapus provider #' + id + '? Tindakan ini tidak bisa dibatalkan.')) return;

    fetch(baseUrl + '/' + id, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ _method: 'DELETE' }),
    })
    .then(res => {
        if (!res.ok) throw new Error('Gagal menghapus provider.');
        location.reload();
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}
</script>
@endpush
