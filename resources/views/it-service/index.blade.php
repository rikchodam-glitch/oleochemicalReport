@extends('layouts.app')

@section('title', 'Service IT')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Service IT</span>
@endsection

@section('content')
<div x-data="itServicePage(@js([
    'oldForm'   => old('_form'),
    'oldAsset'  => old('it_asset_id'),
    'oldEntry'  => old('entry_date'),
    'oldFinish' => old('finish_date'),
    'oldStatus' => old('status'),
    'oldNote'   => old('note'),
    'today'     => now()->toDateString(),
    'urlAset'   => route('it-service.asset-search'),
]))">

    @if ($errors->any())
        <div class="mb-4">
            <x-alert type="error" title="Periksa kembali isian">
                <ul class="list-disc ml-4 space-y-0.5">
                    @foreach ($errors->all() as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
            </x-alert>
        </div>
    @endif

    {{-- Ringkasan status: kartu bisa diklik untuk memfilter daftar. --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <a href="{{ route('it-service.index', ['status' => 'proses']) }}">
            <x-stat-card label="Proses" :value="$ringkasan['proses']" value-color="text-amber-600" icon="alert" />
        </a>
        <a href="{{ route('it-service.index', ['status' => 'rusak']) }}">
            <x-stat-card label="Rusak" :value="$ringkasan['rusak']" value-color="text-red-600" icon="alert" />
        </a>
        <a href="{{ route('it-service.index', ['status' => 'selesai']) }}">
            <x-stat-card label="Selesai" :value="$ringkasan['selesai']" value-color="text-green-600" icon="document" />
        </a>
    </div>

    {{-- Toolbar: filter daftar + tombol tambah manual. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" action="{{ route('it-service.index') }}" class="flex flex-wrap items-center gap-2">
            <select name="status" onchange="this.form.submit()"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="aktif" @selected($statusFilter === 'aktif')>Aktif (Proses + Rusak)</option>
                <option value="proses" @selected($statusFilter === 'proses')>Proses</option>
                <option value="rusak" @selected($statusFilter === 'rusak')>Rusak</option>
                <option value="selesai" @selected($statusFilter === 'selesai')>Selesai</option>
                <option value="semua" @selected($statusFilter === 'semua')>Semua</option>
            </select>

            <select name="device_type" onchange="this.form.submit()"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">Semua tipe</option>
                @foreach ($deviceTypes as $kode => $label)
                    <option value="{{ $kode }}" @selected(request('device_type') === $kode)>{{ $label }}</option>
                @endforeach
            </select>

            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Cari tag, nama, IP, atau serial..."
                   class="text-sm border border-slate-200 rounded-lg px-3 py-2 w-64 focus:outline-none focus:ring-2 focus:ring-blue-500">

            <button type="submit"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-700 border border-slate-300 hover:bg-slate-50 rounded-lg">
                Cari
            </button>

            @if (request()->hasAny(['status', 'device_type', 'search']))
                <a href="{{ route('it-service.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Reset</a>
            @endif
        </form>

        @if (auth()->user()?->bolehMenulis())
            <div class="ml-auto">
                <button type="button" @click="bukaTambah()"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tambah Service IT
                </button>
            </div>
        @endif
    </div>

    {{-- Daftar kasus Service IT. --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Perangkat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Lokasi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal Masuk</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal Selesai</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Catatan</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($records as $record)
                        @php
                            $aset = $record->itAsset;
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors align-top">
                            <td class="px-5 py-3">
                                <div class="font-medium text-slate-900">{{ $aset?->displayName() ?? 'Perangkat dihapus' }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ $aset?->deviceLabel() ?? '-' }}@if ($aset?->name) · {{ $aset->name }}@endif
                                </div>
                                @if ($aset?->ip_address)
                                    <div class="text-xs text-slate-400 font-mono mt-0.5">{{ $aset->ip_address }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $aset?->locationLabel() ?? '-' }}</td>
                            <td class="px-5 py-3 text-slate-700 whitespace-nowrap">{{ $record->entry_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-5 py-3 text-slate-700 whitespace-nowrap">{{ $record->finish_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-col gap-1 items-start">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-medium {{ $statusStyles[$record->status] ?? 'bg-slate-50 text-slate-600 border-slate-200' }}">
                                        {{ $record->statusLabel() }}
                                    </span>
                                    <span class="text-[11px] text-slate-400">{{ $record->sourceLabel() }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 max-w-xs">
                                @if ($record->note)
                                    <span title="{{ $record->note }}">{{ \Illuminate\Support\Str::limit($record->note, 60) }}</span>
                                @else
                                    <span class="text-slate-300">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-2">
                                    @if ($record->report_id)
                                        <a href="{{ route('reports.show', $record->report_id) }}"
                                           class="text-xs text-blue-600 hover:text-blue-800 font-medium" title="Buka laporan asal">Laporan</a>
                                    @endif

                                    @if (auth()->user()?->bolehMenulis())
                                        <button type="button"
                                                @click="bukaEdit(@js([
                                                    'id'          => $record->id,
                                                    'source'      => $record->source,
                                                    'status'      => $record->status,
                                                    'entry_date'  => $record->entry_date?->toDateString(),
                                                    'finish_date' => $record->finish_date?->toDateString() ?? '',
                                                    'note'        => $record->note ?? '',
                                                    'asset'       => $aset?->displayName() ?? 'Perangkat',
                                                    'asset_type'  => $aset?->deviceLabel() ?? '',
                                                    'entry_label' => $record->entry_date?->format('d/m/Y') ?? '-',
                                                ]))"
                                                class="text-xs text-slate-600 hover:text-slate-900 font-medium">
                                            Edit
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">
                                Belum ada kasus Service IT pada filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $records->links() }}
            </div>
        @endif
    </div>

    {{-- Modal tambah kasus manual. --}}
    <div x-show="tambahOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         @keydown.escape.window="tutupTambah()">
        <div class="fixed inset-0 bg-slate-900/50" @click="tutupTambah()"></div>

        <div class="relative mx-auto my-8 w-full max-w-3xl bg-white rounded-xl shadow-xl border border-slate-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900">Tambah Service IT</h3>
                <button type="button" @click="tutupTambah()" class="text-slate-400 hover:text-slate-600" title="Tutup">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-5">
                {{-- Langkah 1: pilih perangkat lewat pencarian + kelompok lokasi/tipe. --}}
                <template x-if="! selectedAsset">
                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <input type="text" x-model="search" placeholder="Cari tag, nama, IP, atau serial..."
                                   class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <span class="text-xs text-slate-400" x-text="asetTersaring.length + ' perangkat'"></span>
                        </div>

                        <div x-show="loadingAssets" class="text-sm text-slate-500 py-6 text-center">Memuat perangkat...</div>

                        <div class="max-h-80 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-100">
                            <template x-for="grup in grupTersaring" :key="grup.lokasi">
                                <div>
                                    <button type="button" @click="toggleLokasi(grup.lokasi)"
                                            class="w-full flex items-center gap-2 px-3 py-2.5 bg-slate-50 hover:bg-slate-100 text-left">
                                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                        <span class="text-sm font-medium text-slate-700" x-text="grup.lokasi"></span>
                                        <span class="ml-auto text-xs text-slate-400" x-text="grup.jumlah"></span>
                                    </button>

                                    <div x-show="search !== '' || bukaLokasi[grup.lokasi]" class="divide-y divide-slate-50">
                                        <template x-for="tipeGrup in grup.tipe" :key="tipeGrup.tipe">
                                            <div>
                                                <button type="button" @click="toggleTipe(grup.lokasi, tipeGrup.tipe)"
                                                        class="w-full flex items-center gap-2 px-6 py-2 hover:bg-slate-50 text-left">
                                                    <svg class="w-3.5 h-3.5 text-slate-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                    </svg>
                                                    <span class="text-xs font-medium text-slate-500 uppercase tracking-wide" x-text="tipeGrup.tipe"></span>
                                                    <span class="ml-auto text-xs text-slate-400" x-text="tipeGrup.daftar.length"></span>
                                                </button>

                                                <div x-show="search !== '' || bukaTipe[grup.lokasi + '|' + tipeGrup.tipe]">
                                                    <template x-for="aset in tipeGrup.daftar" :key="aset.id">
                                                        <button type="button" @click="pilihAset(aset)"
                                                                class="w-full text-left px-9 py-2 hover:bg-blue-50 transition-colors">
                                                            <div class="text-sm font-medium text-slate-800" x-text="aset.label"></div>
                                                            <div class="text-xs text-slate-500" x-text="[aset.nama, aset.detail].filter(Boolean).join(' · ')"></div>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <p x-show="! loadingAssets && grupTersaring.length === 0" class="text-sm text-slate-500 py-6 text-center">
                            Tidak ada perangkat yang cocok atau semua perangkat masih punya kasus terbuka.
                        </p>
                    </div>
                </template>

                {{-- Langkah 2: isi tanggal/status/catatan untuk perangkat terpilih. --}}
                <template x-if="selectedAsset">
                    <form method="POST" action="{{ route('it-service.store') }}">
                        @csrf
                        <input type="hidden" name="_form" value="tambah">
                        <input type="hidden" name="it_asset_id" :value="selectedAsset.id">

                        <div class="flex items-center gap-3 p-3 bg-blue-50 border border-blue-100 rounded-lg mb-4">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-slate-900" x-text="selectedAsset.label"></p>
                                <p class="text-xs text-slate-600" x-text="[selectedAsset.tipe, selectedAsset.detail].filter(Boolean).join(' · ')"></p>
                            </div>
                            <button type="button" @click="selectedAsset = null" class="text-xs text-blue-700 hover:text-blue-900 font-medium">
                                Ganti perangkat
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal masuk <span class="text-red-500">*</span></label>
                                <input type="date" name="entry_date" x-model="form.entry_date" required
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                                <select name="status" x-model="form.status"
                                        @change="if (form.status === 'proses') form.finish_date = ''"
                                        class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="proses">Proses</option>
                                    <option value="selesai">Selesai</option>
                                    <option value="rusak">Rusak</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">
                                    Tanggal selesai
                                    <span x-show="form.status !== 'proses'" class="text-red-500">*</span>
                                </label>
                                <input type="date" name="finish_date" x-model="form.finish_date"
                                       :required="form.status === 'selesai' || form.status === 'rusak'"
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-[11px] text-slate-400 mt-1">Kosongkan selama masih proses.</p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-xs font-medium text-slate-600 mb-1">Catatan (opsional)</label>
                            <textarea name="note" rows="3" x-model="form.note"
                                      class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                      placeholder="Mis. perangkat menunggu spare part"></textarea>
                        </div>

                        <div class="flex items-center justify-end gap-2 mt-5">
                            <button type="button" @click="tutupTambah()"
                                    class="px-4 py-2 text-sm font-medium text-slate-700 border border-slate-300 hover:bg-slate-50 rounded-lg">
                                Batal
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                                Simpan
                            </button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>

    {{-- Modal ubah catatan/status kasus. --}}
    <div x-show="editOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         @keydown.escape.window="tutupEdit()">
        <div class="fixed inset-0 bg-slate-900/50" @click="tutupEdit()"></div>

        <div class="relative mx-auto my-8 w-full max-w-xl bg-white rounded-xl shadow-xl border border-slate-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                <h3 class="text-base font-semibold text-slate-900">Ubah Service IT</h3>
                <button type="button" @click="tutupEdit()" class="text-slate-400 hover:text-slate-600" title="Tutup">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form method="POST" :action="'{{ url('it-service') }}/' + edit.id">
                @csrf
                @method('PUT')
                <input type="hidden" name="_form" value="edit">

                <div class="p-5 space-y-4">
                    <div class="p-3 bg-slate-50 border border-slate-100 rounded-lg">
                        <p class="text-sm font-semibold text-slate-900" x-text="edit.asset"></p>
                        <p class="text-xs text-slate-500" x-text="edit.asset_type"></p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-if="edit.source === 'manual'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal masuk <span class="text-red-500">*</span></label>
                                <input type="date" name="entry_date" x-model="edit.entry_date" required
                                       class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </template>

                        <template x-if="edit.source === 'report'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal masuk</label>
                                <p class="text-sm text-slate-800 py-2" x-text="edit.entry_label"></p>
                                <p class="text-[11px] text-slate-400">Mengikuti tanggal laporan continue.</p>
                            </div>
                        </template>

                        <template x-if="edit.source === 'manual'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                                <select name="status" x-model="edit.status"
                                        @change="if (edit.status === 'proses') edit.finish_date = ''"
                                        class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="proses">Proses</option>
                                    <option value="selesai">Selesai</option>
                                    <option value="rusak">Rusak</option>
                                </select>
                            </div>
                        </template>

                        <template x-if="edit.source === 'report' && edit.status === 'proses'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                                <select name="status" x-model="edit.status"
                                        class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="proses">Proses</option>
                                    <option value="rusak">Rusak</option>
                                </select>
                                <p class="text-[11px] text-slate-400 mt-1">Status selesai diisi otomatis dari laporan done.</p>
                            </div>
                        </template>

                        <template x-if="edit.source === 'report' && edit.status === 'rusak'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                                <input type="hidden" name="status" value="rusak">
                                <p class="text-sm text-red-700 py-2 font-medium">Rusak</p>
                            </div>
                        </template>

                        <template x-if="edit.source === 'report' && edit.status === 'selesai'">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                                <input type="hidden" name="status" value="selesai">
                                <p class="text-sm text-green-700 py-2 font-medium">Selesai</p>
                            </div>
                        </template>
                    </div>

                    <template x-if="edit.source === 'manual' || edit.status === 'rusak'">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">
                                Tanggal selesai
                                <span x-show="edit.status === 'selesai' || edit.status === 'rusak'" class="text-red-500">*</span>
                            </label>
                            <input type="date" name="finish_date" x-model="edit.finish_date"
                                   :required="edit.status === 'selesai' || edit.status === 'rusak'"
                                   class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="text-[11px] text-slate-400 mt-1" x-show="edit.status === 'proses'">Kosongkan selama masih proses.</p>
                        </div>
                    </template>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Catatan (opsional)</label>
                        <textarea name="note" rows="3" x-model="edit.note"
                                  class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Catatan pengerjaan atau kondisi perangkat"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-100">
                    <button type="button" @click="tutupEdit()"
                            class="px-4 py-2 text-sm font-medium text-slate-700 border border-slate-300 hover:bg-slate-50 rounded-lg">
                        Batal
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
/**
 * Komponen Alpine halaman Service IT.
 *
 * @param  {object} opsi  Nilai awal dari server (old input + URL pencarian)
 * @return {object}       State dan method komponen
 */
function itServicePage(opsi) {
    return {
        tambahOpen: opsi.oldForm === 'tambah',
        editOpen: false,
        loadingAssets: false,
        assets: [],
        search: '',
        bukaLokasi: {},
        bukaTipe: {},
        urlAset: opsi.urlAset,
        today: opsi.today,
        selectedAsset: (opsi.oldForm === 'tambah' && opsi.oldAsset)
            ? { id: opsi.oldAsset, label: 'Perangkat terpilih', nama: '', detail: '', tipe: '', lokasi: '' }
            : null,
        form: {
            entry_date: opsi.oldEntry || opsi.today,
            finish_date: opsi.oldFinish || '',
            status: opsi.oldStatus || 'proses',
            note: opsi.oldNote || '',
        },
        edit: {
            id: null,
            source: 'manual',
            status: 'proses',
            entry_date: '',
            finish_date: '',
            note: '',
            asset: '',
            asset_type: '',
            entry_label: '',
        },

        /**
         * Daftar aset yang cocok dengan kata kunci pencarian.
         *
         * @return {Array}
         */
        get asetTersaring() {
            const cari = this.search.trim().toLowerCase();

            if (cari === '') {
                return this.assets;
            }

            return this.assets.filter((aset) => aset.cari.includes(cari));
        },

        /**
         * Kelompokkan hasil pencarian: lokasi -> tipe perangkat -> aset.
         *
         * @return {Array}
         */
        get grupTersaring() {
            const peta = new Map();

            this.asetTersaring.forEach((aset) => {
                if (! peta.has(aset.lokasi)) {
                    peta.set(aset.lokasi, new Map());
                }

                const petaTipe = peta.get(aset.lokasi);

                if (! petaTipe.has(aset.tipe)) {
                    petaTipe.set(aset.tipe, []);
                }

                petaTipe.get(aset.tipe).push(aset);
            });

            return Array.from(peta.entries()).map(([lokasi, petaTipe]) => {
                const tipe = Array.from(petaTipe.entries()).map(([namaTipe, daftar]) => ({
                    tipe: namaTipe,
                    daftar: daftar,
                }));

                return {
                    lokasi: lokasi,
                    jumlah: tipe.reduce((total, baris) => total + baris.daftar.length, 0),
                    tipe: tipe,
                };
            });
        },

        /**
         * Buka modal tambah dan muat daftar perangkat sekali saja.
         *
         * @return {void}
         */
        bukaTambah() {
            this.selectedAsset = null;
            this.search = '';
            this.form = {
                entry_date: this.today,
                finish_date: '',
                status: 'proses',
                note: '',
            };
            this.tambahOpen = true;
            this.muatAset();
        },

        /**
         * Tutup modal tambah.
         *
         * @return {void}
         */
        tutupTambah() {
            this.tambahOpen = false;
        },

        /**
         * Ambil daftar perangkat IT yang boleh ditambahkan.
         *
         * @return {void}
         */
        muatAset() {
            if (this.loadingAssets || this.assets.length > 0) {
                return;
            }

            this.loadingAssets = true;

            fetch(this.urlAset, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then((respons) => respons.json())
                .then((data) => {
                    this.assets = data.assets || [];
                })
                .catch(() => {
                    this.assets = [];
                })
                .finally(() => {
                    this.loadingAssets = false;
                });
        },

        /**
         * Pilih perangkat untuk kasus manual.
         *
         * @param  {object} aset
         * @return {void}
         */
        pilihAset(aset) {
            this.selectedAsset = aset;
        },

        /**
         * Buka/tutup kelompok lokasi.
         *
         * @param  {string} lokasi
         * @return {void}
         */
        toggleLokasi(lokasi) {
            this.bukaLokasi[lokasi] = ! this.bukaLokasi[lokasi];
        },

        /**
         * Buka/tutup kelompok tipe di dalam satu lokasi.
         *
         * @param  {string} lokasi
         * @param  {string} tipe
         * @return {void}
         */
        toggleTipe(lokasi, tipe) {
            const kunci = lokasi + '|' + tipe;

            this.bukaTipe[kunci] = ! this.bukaTipe[kunci];
        },

        /**
         * Buka modal ubah untuk satu kasus.
         *
         * @param  {object} data
         * @return {void}
         */
        bukaEdit(data) {
            this.edit = Object.assign({}, data);
            this.editOpen = true;
        },

        /**
         * Tutup modal ubah.
         *
         * @return {void}
         */
        tutupEdit() {
            this.editOpen = false;
        },
    };
}
</script>
@endpush
