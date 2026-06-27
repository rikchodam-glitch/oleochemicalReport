@props([
    'provider' => null,
])

<div class="bg-white rounded-xl border border-slate-200 p-5">
    <div class="flex items-start justify-between mb-4">
        <div class="flex items-center gap-3">
            <span class="bg-slate-100 text-slate-500 text-xs px-2 py-0.5 rounded font-mono">#{{ $provider->priority }}</span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full 
                        {{ $provider->status === 'healthy' ? 'bg-green-500' : '' }}
                        {{ $provider->status === 'exhausted' ? 'bg-red-500' : '' }}
                        {{ $provider->status === 'error' ? 'bg-red-500' : '' }}
                        {{ $provider->status === 'disabled' ? 'bg-slate-400' : '' }}">
                    </span>
                    <h3 class="font-semibold text-slate-900">{{ $provider->name }}</h3>
                </div>
                <p class="text-slate-400 text-xs mt-0.5">{{ $provider->model }}</p>
            </div>
        </div>
        <x-status-badge :status="$provider->status" :label="App\Enums\ProviderStatus::tryFrom($provider->status)?->label() ?? $provider->status" />
    </div>

    <!-- Token Progress -->
    <div class="space-y-3 mb-4">
        <x-progress-bar
            :percent="$provider->monthly_usage_percent"
            color="green"
            label="Sisa token bulanan"
            :value="number_format($provider->monthly_remaining)" />

        <div class="mt-2">
            <x-progress-bar
                :percent="$provider->daily_usage_percent"
                color="amber"
                label="Sisa HARIAN"
                :value="number_format($provider->daily_remaining)" />
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-cols-3 gap-4 border-t border-slate-100 pt-4 mb-4">
        <div class="text-center border-r border-slate-100 pr-2">
            <p class="text-lg font-bold text-slate-900">{{ number_format($provider->tokens_used_month) }}</p>
            <p class="text-xs text-slate-500">Bulan Ini</p>
        </div>
        <div class="text-center border-r border-slate-100 pr-2">
            <p class="text-lg font-bold text-slate-900">{{ $provider->request_count_24h }}</p>
            <p class="text-xs text-slate-500">Request 24 Jam</p>
        </div>
        <div class="text-center">
            <p class="text-lg font-bold text-slate-900">{{ $provider->last_used_at ? $provider->last_used_at->diffForHumans() : '-' }}</p>
            <p class="text-xs text-slate-500">Terakhir</p>
        </div>
    </div>

    <!-- Actions -->
    <div class="grid grid-cols-3 gap-2">
        <button onclick="editProvider({{ $provider->id }})"
                class="px-3 py-1.5 border border-blue-300 text-blue-600 hover:bg-blue-50 text-xs font-medium rounded-lg transition-colors">
            Edit
        </button>
        <button onclick="testProvider({{ $provider->id }})"
                class="px-3 py-1.5 border border-green-500 text-green-600 hover:bg-green-50 text-xs font-medium rounded-lg transition-colors">
            Test
        </button>
        <button onclick="deleteProvider({{ $provider->id }})"
                class="px-3 py-1.5 border border-red-300 text-red-600 hover:bg-red-50 text-xs font-medium rounded-lg transition-colors">
            Hapus
        </button>
    </div>
</div>
