@extends('layouts.app')

@section('title', 'Tambah Functional Location')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('func-locs.index') }}" class="hover:text-slate-700">Functional Location</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah</span>
@endsection

@section('content')
    @php
        // Nilai awal baris children: dari old() jika validasi sebelumnya gagal,
        // atau satu baris kosong jika ini kunjungan pertama ke form.
        $initialChildren = old('children', [['segment' => '', 'name' => '']]);

        // Kumpulkan error per baris (children.{index}.segment / children.{index}.name)
        // menjadi array asosiatif agar mudah dikonsumsi Alpine di tiap baris.
        $rowErrors = [];
        foreach ($errors->keys() as $errorKey) {
            if (preg_match('/^children\.(\d+)\.(segment|name)$/', $errorKey, $matches)) {
                $rowErrors[(int) $matches[1]][$matches[2]] = $errors->first($errorKey);
            }
        }

        // Error umum (bukan per baris), mis. parent_id atau children (array kosong).
        $generalErrors = collect($errors->keys())
            ->filter(fn ($key) => ! str_starts_with($key, 'children.'))
            ->map(fn ($key) => $errors->first($key));
    @endphp

    <div class="max-w-xl">
        <div class="bg-white rounded-xl border border-slate-200 p-6">

            @if ($generalErrors->isNotEmpty())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($generalErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('func-locs.store') }}"
                  x-data="funcLocForm({{ Illuminate\Support\Js::from($initialChildren) }}, {{ Illuminate\Support\Js::from($rowErrors) }})"
                  x-init="init()">
                @csrf

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Parent</label>
                    <select name="parent_id" @change="updateParentPrefix()"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                        <option value="" data-code="" data-level="-1">Tidak ada (buat sebagai node Level 0 / PT)</option>
                        @foreach($parentOptions as $parent)
                            <option value="{{ $parent->id }}"
                                    data-code="{{ $parent->code }}"
                                    data-level="{{ $parent->level }}"
                                    {{ (string) $selectedParentId === (string) $parent->id ? 'selected' : '' }}>
                                {{ str_repeat('— ', $parent->level) }}{{ $parent->code }} (L{{ $parent->level }} - {{ $levelLabels[$parent->level] ?? 'Tidak Diketahui' }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Pilih parent untuk menentukan level dan prefix kode secara otomatis. Semua Anak yang ditambahkan sekaligus berada di bawah parent yang sama.</p>
                    <p class="text-xs text-slate-400 mt-1">Node PT, Departemen, Area, dan Section otomatis tersinkron sebagai Company, Department, Area, dan SubArea di Asset Management.</p>
                </div>

                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700">Anak yang Ditambahkan</label>
                    <button type="button" @click="addRow()"
                            class="text-xs font-medium text-blue-600 hover:text-blue-700">
                        + Tambah Baris
                    </button>
                </div>

                <template x-for="(row, index) in rows" :key="row.key">
                    <div class="mb-4 p-3 border rounded-lg"
                         :class="(errorFor(index, 'segment') || errorFor(index, 'name')) ? 'border-red-300 bg-red-50/40' : 'border-slate-200'">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-medium text-slate-500">Baris <span x-text="index + 1"></span></p>
                            <button type="button" x-show="rows.length > 1" @click="removeRow(index)"
                                    class="text-xs text-red-600 hover:text-red-700">
                                Hapus
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Segment</label>
                            <input type="text" :name="`children[${index}][segment]`" x-model="row.segment"
                                   @input="row.segment = row.segment.toUpperCase()"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="mis. BD01" required>
                            <p class="text-xs text-red-600 mt-1" x-show="errorFor(index, 'segment')" x-text="errorFor(index, 'segment')"></p>
                        </div>

                        <div class="mb-3">
                            <label class="block text-sm font-medium text-slate-700 mb-1">Nama</label>
                            <input type="text" :name="`children[${index}][name]`" x-model="row.name"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Deskripsi lokasi" required>
                            <p class="text-xs text-red-600 mt-1" x-show="errorFor(index, 'name')" x-text="errorFor(index, 'name')"></p>
                        </div>

                        <div class="p-3 bg-slate-50 rounded-lg border border-slate-100">
                            <p class="text-xs text-slate-500 uppercase tracking-wide mb-1">Preview Kode</p>
                            <p class="font-mono text-sm text-slate-900" x-text="previewCode(row)"></p>
                        </div>
                    </div>
                </template>

                <div class="flex items-center gap-3">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Simpan
                    </button>
                    <a href="{{ route('func-locs.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function funcLocForm(initialChildren, rowErrors) {
        return {
            parentPrefix: '',
            rows: [],
            rowErrors: rowErrors || {},
            nextKey: 0,

            init() {
                this.rows = (initialChildren && initialChildren.length ? initialChildren : [{ segment: '', name: '' }])
                    .map((child) => this.makeRow(child.segment, child.name));
                this.updateParentPrefix();
            },

            makeRow(segment, name) {
                this.nextKey += 1;

                return { key: this.nextKey, segment: segment || '', name: name || '' };
            },

            addRow() {
                this.rows.push(this.makeRow('', ''));
            },

            removeRow(index) {
                if (this.rows.length <= 1) {
                    return;
                }

                this.rows.splice(index, 1);
            },

            updateParentPrefix() {
                const select = document.querySelector('select[name="parent_id"]');
                const selectedOption = select ? select.options[select.selectedIndex] : null;

                this.parentPrefix = selectedOption ? (selectedOption.getAttribute('data-code') || '') : '';
            },

            previewCode(row) {
                const segment = (row.segment || '').trim().toUpperCase();

                if (! segment) {
                    return '-';
                }

                return this.parentPrefix ? this.parentPrefix + '-' + segment : segment;
            },

            errorFor(index, field) {
                return (this.rowErrors[index] && this.rowErrors[index][field]) || null;
            }
        };
    }
</script>
@endpush
