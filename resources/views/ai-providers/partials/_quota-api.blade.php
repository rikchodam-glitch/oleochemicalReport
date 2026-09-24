{{--
    Kuota menurut API provider.
    Nilai diambil dari header x-ratelimit-* pada respons chat terakhir
    (x-ratelimit-limit-tokens / remaining-tokens / limit-requests /
    remaining-requests) - Groq tidak punya endpoint sisa kuota, jadi header
    inilah sumber kebenaran yang ditampilkan. Masa tunggu (cooldown) diisi
    otomatis saat provider kena HTTP 429, memakai retry-after dari API.
--}}
@php
    $adaDataKuota = $provider->quota_checked_at !== null
        || $provider->quota_remaining_tokens_per_minute !== null
        || $provider->cooldown_until !== null;
@endphp

@if ($adaDataKuota)
    <div class="space-y-1 pt-1 border-t border-slate-100">
        <div class="flex justify-between items-center">
            <span class="text-xs text-slate-500">Kuota per menit (menurut API)</span>
            <span class="text-xs font-mono text-slate-700">
                @if ($provider->quota_remaining_tokens_per_minute !== null)
                    {{ number_format((int) $provider->quota_remaining_tokens_per_minute) }}
                    / {{ number_format((int) $provider->quota_limit_tokens_per_minute) }} token
                @else
                    -
                @endif
            </span>
        </div>

        @if ($provider->quota_remaining_requests_per_minute !== null)
            <div class="flex justify-between items-center">
                <span class="text-xs text-slate-500">Sisa permintaan per menit</span>
                <span class="text-xs font-mono text-slate-700">
                    {{ number_format((int) $provider->quota_remaining_requests_per_minute) }}
                    / {{ number_format((int) $provider->quota_limit_requests_per_minute) }}
                </span>
            </div>
        @endif

        @if ($provider->cooldown_until !== null && $provider->cooldown_until->isFuture())
            <p class="text-xs text-amber-600">
                Kena batas API, dicoba lagi {{ $provider->cooldown_until->diffForHumans() }}
            </p>
        @endif

        <p class="text-xs text-slate-400">
            Diperiksa: {{ $provider->quota_checked_at?->diffForHumans() ?? 'belum pernah' }}
            @if ($provider->quota_day)
                &middot; penghitung harian direset {{ $provider->quota_day->format('d/m/Y') }}
            @endif
        </p>
    </div>
@endif
