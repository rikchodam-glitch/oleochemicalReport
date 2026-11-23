@php
    $statusWarna = [
        'tersedia' => 'bg-green-100 text-green-700',
        'dipakai'  => 'bg-amber-100 text-amber-700',
        'rusak'    => 'bg-red-100 text-red-700',
    ][$alat['status']] ?? 'bg-slate-100 text-slate-600';

    if (! empty($alat['menunggu_kembali'])) {
        $statusWarna = 'bg-amber-100 text-amber-700';
    }
@endphp

<div class="bg-white rounded-xl border border-slate-200 p-2.5 flex gap-3 cursor-pointer transition-colors hover:border-teal-300"
     data-kartu-tool
     data-href-card="{{ route('alat.show', $alat['id']) }}"
     tabindex="0"
     role="link"
     aria-label="Buka detail {{ $alat['group_name'] ?? $alat['name'] }} ({{ $alat['code'] }})"
     data-cari="{{ strtolower(($alat['group_name'] ?? $alat['name']) . ' ' . $alat['code'] . ' ' . ($alat['type_name'] ?? '')) }}"
     data-lokasi="{{ $loopLokasiId ?? '' }}"
     data-rak="{{ $loopRakId ?? '' }}"
     data-status="{{ $alat['status'] }}">

    <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-lg bg-slate-100 overflow-hidden shrink-0">
        @if($alat['foto_url'])
            <img src="{{ $alat['foto_url'] }}" alt="{{ $alat['group_name'] ?? $alat['name'] }}"
                 class="w-full h-full object-cover" loading="lazy">
        @else
            <div class="w-full h-full flex items-center justify-center text-slate-300">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
        @endif
    </div>

    <div class="min-w-0 flex-1 flex flex-col">
        <div class="flex items-start justify-between gap-1.5">
            <div class="min-w-0">
                <a href="{{ route('alat.show', $alat['id']) }}"
                   class="block text-sm font-semibold text-slate-900 truncate hover:text-teal-700">
                    {{ $alat['group_name'] ?? $alat['name'] }}
                </a>
                <p class="text-[11px] font-mono text-slate-500">{{ $alat['code'] }}</p>
            </div>
            <span class="shrink-0 px-1.5 py-0.5 rounded-full text-[10px] font-semibold {{ $statusWarna }}">
                {{ $alat['status_label'] }}
            </span>
        </div>

        <div class="mt-1 flex flex-wrap items-center gap-1 text-[10px] text-slate-500">
            @if(! empty($alat['type_name']))
                <span class="px-1.5 py-0.5 rounded bg-slate-100 font-medium text-slate-600">{{ $alat['type_name'] }}</span>
            @endif
            <span class="truncate">{{ $lokasiName ?? '-' }} / {{ $rakName ?? '-' }}</span>
        </div>

        <p class="mt-1 text-[11px] text-slate-600 line-clamp-2 flex-1">
            @if(! empty($alat['menunggu_kembali']))
                Menunggu diterima tool keeper.
            @elseif($alat['status'] === 'dipakai')
                Dipakai oleh <span class="font-semibold">{{ $alat['peminjam'] ?? '-' }}</span>.
            @elseif($alat['peminjam_terakhir'])
                Peminjam terakhir: {{ $alat['peminjam_terakhir'] }}.
            @else
                <span class="text-slate-400">Belum pernah dipinjam.</span>
            @endif
        </p>

        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if($mode === 'admin')
                @if($bolehTulis && $alat['status'] === 'tersedia')
                    <button type="button"
                            class="tombol-pinjamkan px-2 py-1 rounded-md bg-teal-600 text-white text-[10px] font-semibold hover:bg-teal-700"
                            data-id="{{ $alat['id'] }}"
                            data-nama="{{ $alat['group_name'] ?? $alat['name'] }} ({{ $alat['code'] }})">
                        Pinjamkan
                    </button>
                @endif
            @else
                @if($alat['boleh_pinjam'])
                    <form method="POST" action="{{ route('alat.pinjam', $alat['id']) }}"
                          onsubmit="return confirm('Pinjam {{ $alat['group_name'] ?? $alat['name'] }} ({{ $alat['code'] }}) untuk diri sendiri?')">
                        @csrf
                        <button type="submit" class="px-2 py-1 rounded-md bg-teal-600 text-white text-[10px] font-semibold hover:bg-teal-700">
                            Pinjam
                        </button>
                    </form>
                @endif

                @if($alat['boleh_pinjamkan'])
                    <button type="button"
                            class="tombol-pinjamkan px-2 py-1 rounded-md border border-teal-300 text-teal-700 text-[10px] font-semibold hover:bg-teal-50"
                            data-id="{{ $alat['id'] }}"
                            data-nama="{{ $alat['group_name'] ?? $alat['name'] }} ({{ $alat['code'] }})">
                        Pinjamkan
                    </button>
                @endif

                @if($alat['boleh_kembalikan'])
                    <form method="POST" action="{{ route('alat.kembali', $alat['pinjaman_aktif_id']) }}"
                          onsubmit="return confirm('Ajukan pengembalian {{ $alat['group_name'] ?? $alat['name'] }} ({{ $alat['code'] }})?')">
                        @csrf
                        <button type="submit" class="px-2 py-1 rounded-md bg-amber-500 text-white text-[10px] font-semibold hover:bg-amber-600">
                            Kembalikan
                        </button>
                    </form>
                @endif
            @endif

            <span class="ml-auto text-[10px] text-slate-400">Detail</span>
        </div>
    </div>
</div>
