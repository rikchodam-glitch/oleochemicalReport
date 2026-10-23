@extends('layouts.app')

@section('title', $asset->tech_ident_no ?? $asset->equipment_no ?? 'Detail Asset')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $asset->tech_ident_no ?? $asset->equipment_no ?? 'Detail Asset' }}</span>
@endsection

@section('header-actions')
    @if($cmRingkasan !== null)
        <a href="{{ route('cm.equipment-show', $cmRingkasan['equipment']->equipment_tag) }}"
           class="inline-flex items-center gap-2 px-4 py-2 border border-teal-300 hover:bg-teal-50 text-teal-700 text-sm font-medium rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m8-3v-2a2 2 0 00-2-2h-2a2 2 0 00-2 2v2m0 0v6a2 2 0 002 2h2a2 2 0 002-2v-6"/>
            </svg>
            Buka Halaman CM
        </a>
    @endif
    <a href="{{ route('assets.edit', $asset) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors">
        Edit Asset
    </a>
    <form method="POST" action="{{ route('assets.destroy', $asset) }}" class="inline"
          onsubmit="return confirm('Hapus asset ini? Semua foto equipment ikut dihapus.')">
        @csrf
        @method('DELETE')
        <button type="submit"
                class="inline-flex items-center gap-2 px-4 py-2 border border-rose-300 hover:bg-rose-50 text-rose-700 text-sm font-medium rounded-lg transition-colors">
            Hapus Asset
        </button>
    </form>
@endsection

@section('content')
    <div x-data="assetDetail()" class="space-y-6">

        <!-- Ringkasan Info Asset + Kartu Statistik -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Info Asset -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">Informasi Asset</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                @if($asset->equipment_no)
                                    EQ: <span class="font-mono">{{ $asset->equipment_no }}</span>
                                @endif
                                @if($asset->functional_loc)
                                    &middot; FL:
                                    @if($asset->functionalLocation)
                                        <a href="{{ route('locations.show', $asset->functionalLocation) }}"
                                           class="font-mono text-teal-700 hover:underline">{{ $asset->functional_loc }}</a>
                                    @else
                                        <span class="font-mono">{{ $asset->functional_loc }}</span>
                                    @endif
                                @endif
                            </p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                        <div class="md:col-span-3">
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Deskripsi</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $asset->description ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Tech Ident No</dt>
                            <dd class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ $asset->tech_ident_no ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Equipment Type</dt>
                            <dd class="mt-1">
                                @if($asset->equipment_type)
                                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">{{ $asset->equipment_type }}</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-amber-50 text-amber-700 rounded">Tanpa Tipe</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Data Source</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ ['import_excel' => 'Import Excel', 'manual' => 'Manual', 'it_sync' => 'Sinkron IT Assets', 'panel_sync' => 'Sinkron Ruang Panel', 'cm_sync' => 'Sinkron CM'][$asset->data_source] ?? $asset->data_source }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Manufacturer</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $asset->manufacturer ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Model Number</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $asset->model_number ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Construct Year</dt>
                            <dd class="mt-1 text-sm text-slate-900">{{ $asset->construct_year ?? '-' }}</dd>
                        </div>
                    </dl>

                    @include('assets.partials._foto-equipment', [
                        'asset' => $asset,
                        'bisaKelola' => true,
                        'konteks' => 'Informasi Asset',
                    ])
                </div>
            </div>

            <!-- Lokasi -->
            <div>
                <div class="bg-white rounded-xl border border-slate-200 p-6 h-full">
                    <h3 class="text-sm font-semibold text-slate-900 mb-4">Lokasi</h3>
                    <div class="space-y-4">
                        <div>
                            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                                Company
                            </div>
                            <p class="text-sm font-medium text-slate-900">{{ $asset->company?->code ?? '-' }}</p>
                            @if($asset->company)<p class="text-xs text-slate-400">{{ $asset->company->name }}</p>@endif
                        </div>
                        @if($asset->department)
                        <div class="border-t border-slate-100 pt-3">
                            <div class="flex items-center gap-2 text-xs text-slate-400 mb-2">Departemen</div>
                            <p class="text-sm text-slate-900">{{ $asset->department->code }} — {{ $asset->department->name }}</p>
                        </div>
                        @endif
                        @if($asset->area)
                        <div class="border-t border-slate-100 pt-3">
                            <div class="flex items-center gap-2 text-xs text-slate-400 mb-2">Area</div>
                            <p class="text-sm font-medium text-slate-900">{{ $asset->area->code }} — {{ $asset->area->name }}</p>
                        </div>
                        @endif
                        @if($asset->subArea)
                        <div class="border-t border-slate-100 pt-3">
                            <div class="flex items-center gap-2 text-xs text-slate-400 mb-2">Sub Area</div>
                            <p class="text-sm font-medium text-slate-900">{{ $asset->subArea->code }} — {{ $asset->subArea->name }}</p>
                        </div>
                        @endif
                        @if($asset->functional_loc)
                        <div class="border-t border-slate-100 pt-3">
                            <div class="flex items-center gap-2 text-xs text-slate-400 mb-2">Functional Loc</div>
                            <p class="text-sm font-mono text-slate-900">
                                @if($asset->functionalLocation)
                                    <a href="{{ route('locations.show', $asset->functionalLocation) }}"
                                       class="text-teal-700 hover:underline">{{ $asset->functional_loc }}</a>
                                @else
                                    {{ $asset->functional_loc }}
                                @endif
                            </p>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @include('assets.partials._cm-ringkasan')

        <!-- Kartu Statistik -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-slate-200 p-5 text-center">
                <p class="text-3xl font-bold text-slate-900">{{ $totalReports }}</p>
                <p class="text-xs text-slate-500 mt-1">Total Perbaikan</p>
            </div>
            <div class="bg-white rounded-xl border border-green-200 p-5 text-center">
                <p class="text-3xl font-bold text-green-600">{{ $completedReports }}</p>
                <p class="text-xs text-slate-500 mt-1">Selesai</p>
            </div>
            <div class="bg-white rounded-xl border border-amber-200 p-5 text-center">
                <p class="text-3xl font-bold text-amber-600">{{ $needsReviewReports }}</p>
                <p class="text-xs text-slate-500 mt-1">Perlu Review</p>
            </div>
            <div class="bg-white rounded-xl border border-blue-200 p-5 text-center">
                <p class="text-3xl font-bold text-blue-600">{{ $teknisiPernahRepair->count() }}</p>
                <p class="text-xs text-slate-500 mt-1">Teknisi Pernah Menangani</p>
            </div>
            <div class="bg-white rounded-xl border border-purple-200 p-5 text-center">
                <p class="text-3xl font-bold text-purple-600">{{ $asset->technicians->count() }}</p>
                <p class="text-xs text-slate-500 mt-1">Teknisi Ditugaskan</p>
            </div>
        </div>

        <!-- Panel Teknisi Ditugaskan -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    Teknisi Ditugaskan
                </h3>
                <button @click="openAssignModal"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tambah Teknisi
                </button>
            </div>
            <div class="px-6 py-4">
                @if($asset->technicians->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($asset->technicians as $assignedTech)
                            <div class="flex items-center justify-between p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                        {{ substr($assignedTech->name, 0, 1) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-900 truncate">{{ $assignedTech->name }}</p>
                                        <div class="flex items-center gap-2 text-[11px] text-slate-400">
                                            @if($assignedTech->nik)<span>{{ $assignedTech->nik }}</span>@endif
                                            @if($assignedTech->group)
                                                <span>&middot;</span>
                                                <span class="text-purple-500">{{ \App\Models\Technician::GROUPS[$assignedTech->group] ?? $assignedTech->group }}</span>
                                            @endif
                                            @if($assignedTech->section)
                                                <span>&middot;</span>
                                                <span class="text-blue-500">{{ \App\Models\Technician::SECTIONS[$assignedTech->section] ?? $assignedTech->section }}</span>
                                            @endif
                                        </div>
                                        @if($assignedTech->pivot->note)
                                            <p class="text-xs text-slate-500 mt-0.5 italic truncate">{{ $assignedTech->pivot->note }}</p>
                                        @endif
                                    </div>
                                </div>
                                <button onclick="removeTechnician({{ $asset->id }}, {{ $assignedTech->id }})"
                                        class="text-red-400 hover:text-red-600 transition-colors p-1 shrink-0" title="Hapus">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-6 text-sm text-slate-400">
                        Belum ada teknisi yang ditugaskan untuk asset ini.
                    </div>
                @endif
            </div>
        </div>

        {{-- Grafik tren perbaikan: lebar penuh + komposisi jenis + ringkasan --}}
        @include('partials._grafik-perbaikan', ['konteks' => 'equipment ini'])

        {{-- Daftar perbaikan: satu baris = satu perbaikan (tombol Detail ke laporan) --}}
        @include('assets.partials._show-tabel-perbaikan')

        <!-- Modal Assign Teknisi -->
        <div x-show="showAssignModal"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             x-cloak>
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[80vh] overflow-hidden"
                 @@click.away="showAssignModal = false">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">Tambah Teknisi</h3>
                    <button @@click="showAssignModal = false" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Pilih Teknisi</label>
                        <select x-model="selectedTechnicianId" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                            <option value="">-- Pilih Teknisi --</option>
                            @foreach($allTechnicians as $tech)
                                <option value="{{ $tech->id }}">{{ $tech->name }} @if($tech->nik)({{ $tech->nik }})@endif @if($tech->telegram_username) — @{{ $tech->telegram_username }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Catatan Tugas</label>
                        <textarea x-model="assignNote" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" placeholder="Contoh: Mohon cek rutin pompa di area 6600"></textarea>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Jika teknisi memiliki akun Telegram aktif, notifikasi akan dikirim otomatis.
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2">
                    <button @@click="showAssignModal = false" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">Batal</button>
                    <button @@click="assignTechnician" :disabled="!selectedTechnicianId"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg">
                        <span x-show="!assigning">Tambahkan & Kirim Notifikasi</span>
                        <span x-show="assigning" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Menambahkan...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
@include('partials._grafik-perbaikan-script')
<script>
function assetDetail() {
    return {
        showAssignModal: false,
        selectedTechnicianId: '',
        assignNote: '',
        assigning: false,

        openAssignModal() {
            this.showAssignModal = true;
            this.selectedTechnicianId = '';
            this.assignNote = '';
        },

        assignTechnician() {
            if (!this.selectedTechnicianId) return;
            this.assigning = true;

            fetch('{{ route('assets.technicians.assign', $asset) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    technician_id: this.selectedTechnicianId,
                    note: this.assignNote,
                }),
            })
            .then(res => res.json())
            .then(data => {
                this.showAssignModal = false;
                if (data.success) {
                    location.reload();
                } else {
                    alert('Gagal: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Terjadi kesalahan jaringan.'))
            .finally(() => { this.assigning = false; });
        },
    };
}

function removeTechnician(assetId, technicianId) {
    if (!confirm('Hapus teknisi ini dari asset?')) return;
    fetch(`/assets/${assetId}/technicians/${technicianId}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
    })
    .then(res => res.json())
    .then(data => { if (data.success) location.reload(); else alert('Gagal menghapus teknisi.'); })
    .catch(err => alert('Terjadi kesalahan.'));
}
</script>
@endpush
