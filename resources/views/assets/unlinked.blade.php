@extends('layouts.app')

@section('title', 'Asset Belum Punya Lokasi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Belum Punya Lokasi</span>
@endsection

@section('header-actions')
    <form method="POST" action="{{ route('assets.unlinked-funcloc.run-sync') }}"
          onsubmit="return confirm('Jalankan sinkronisasi otomatis? Asset yang functional_loc lamanya cocok dengan kode FuncLoc akan langsung ditautkan.')">
        @csrf
        <button type="submit"
                class="inline-flex items-center gap-2 px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-700 text-sm font-medium rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Jalankan Sync Otomatis
        </button>
    </form>
    <a href="{{ route('assets.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        Kembali ke Asset Management
    </a>
@endsection

@section('content')
    <div x-data="unlinkedAssetsManager(@js($assets->pluck('id')))">
        <!-- Info banner -->
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 flex gap-3">
            <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <p class="text-sm text-amber-800">
                Daftar ini berisi asset yang <span class="font-medium">functional_loc</span> lamanya kosong, typo,
                atau formatnya tidak sesuai hierarki 4-level SAP, sehingga tidak berhasil ditautkan otomatis oleh
                sistem. Tautkan satu-per-satu, atau pilih beberapa asset lalu tautkan sekaligus ke satu FuncLoc yang
                sama lewat panel di bawah.
            </p>
        </div>

        <!-- Search -->
        <form method="GET" action="{{ route('assets.unlinked-funcloc') }}" class="mb-4">
            <div class="relative max-w-md">
                <input type="text" name="search" value="{{ request('search') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Cari equipment, deskripsi, tech ident, atau functional_loc lama...">
            </div>
        </form>

        <!-- Bulk assign panel -->
        <form method="POST" action="{{ route('assets.bulk-link-funcloc') }}" class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
            @csrf
            <template x-for="id in selectedIds" :key="id">
                <input type="hidden" name="asset_ids[]" :value="id">
            </template>

            <div class="flex items-end gap-4">
                <div class="flex-1">
                    <x-funcloc-picker
                        name="funcloc_id"
                        label="Tautkan asset terpilih ke FuncLoc"
                        :required="false"
                        placeholder="Cari kode atau nama FuncLoc..." />
                </div>
                <div>
                    <button type="submit"
                            :disabled="selectedIds.length === 0"
                            :class="selectedIds.length === 0 ? 'opacity-50 cursor-not-allowed' : ''"
                            class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
                        Tautkan <span x-text="selectedIds.length"></span> Asset Terpilih
                    </button>
                </div>
            </div>
        </form>

        <!-- Table -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-5 py-3 w-10">
                                <input type="checkbox" x-model="selectAll" x-on:change="toggleAll($event.target.checked)">
                            </th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Kode Alat</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Functional Loc Lama</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tautkan ke FuncLoc</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($assets as $asset)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3.5 align-top">
                                    <input type="checkbox" :value="{{ $asset->id }}" x-model="selectedIds">
                                </td>
                                <td class="px-5 py-3.5 align-top">
                                    <div class="flex flex-col">
                                        <span class="font-mono text-sm font-medium text-slate-900">{{ $asset->tech_ident_no ?? '-' }}</span>
                                        @if($asset->equipment_no)
                                            <span class="text-[10px] text-slate-400 font-mono mt-0.5">EQ: {{ $asset->equipment_no }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 align-top">
                                    <p class="text-slate-700">{{ Str::limit($asset->description, 50) }}</p>
                                </td>
                                <td class="px-5 py-3.5 align-top">
                                    @if($asset->functional_loc)
                                        <span class="font-mono text-xs text-slate-500">{{ $asset->functional_loc }}</span>
                                    @else
                                        <span class="text-xs text-slate-300 italic">kosong</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 align-top">
                                    <form method="POST" action="{{ route('assets.link-funcloc', $asset) }}" class="flex items-end gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <div class="w-72">
                                            <x-funcloc-picker
                                                name="funcloc_id"
                                                label=""
                                                :required="false"
                                                placeholder="Cari FuncLoc..." />
                                        </div>
                                        <button type="submit"
                                                class="px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                                            Tautkan
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-slate-400">
                                    Semua asset sudah punya lokasi. Tidak ada yang perlu direkonsiliasi.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($assets->hasPages())
                <div class="px-5 py-3 border-t border-slate-100">
                    {{ $assets->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
function unlinkedAssetsManager(allIds) {
    return {
        allIds: allIds,
        selectedIds: [],
        selectAll: false,

        toggleAll(checked) {
            this.selectedIds = checked ? [...this.allIds] : [];
            this.selectAll = checked;
        },
    };
}
</script>
@endpush
