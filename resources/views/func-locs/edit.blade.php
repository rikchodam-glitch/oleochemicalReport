@extends('layouts.app')

@section('title', 'Edit Functional Location')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('func-locs.index') }}" class="hover:text-slate-700">Functional Location</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Edit</span>
@endsection

@section('content')
    @php
        // Error milik form edit utama (name, is_active) dipisahkan dari error
        // milik form tambah anak (parent_id, children.*), supaya masing-masing
        // kotak error hanya menampilkan pesan yang relevan.
        $editFormErrorKeys = ['name', 'is_active'];
        $editFormErrors = collect($errors->keys())
            ->filter(fn ($key) => in_array($key, $editFormErrorKeys, true))
            ->map(fn ($key) => $errors->first($key));

        // Anak boleh ditambahkan hanya jika node ini belum di level Section (L3).
        $canAddChild = $funcLoc->level < \App\Models\FunctionalLocation::LEVEL_SECTION;

        if ($canAddChild) {
            $initialChildren = old('children', [['segment' => '', 'name' => '']]);

            $childRowErrors = [];
            foreach ($errors->keys() as $errorKey) {
                if (preg_match('/^children\.(\d+)\.(segment|name)$/', $errorKey, $matches)) {
                    $childRowErrors[(int) $matches[1]][$matches[2]] = $errors->first($errorKey);
                }
            }

            $addChildGeneralErrors = collect($errors->keys())
                ->filter(fn ($key) => $key === 'parent_id' || $key === 'children')
                ->map(fn ($key) => $errors->first($key));
        }
    @endphp

    <div class="max-w-xl space-y-6">
        <div class="bg-white rounded-xl border border-slate-200 p-6">

            @if ($editFormErrors->isNotEmpty())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($editFormErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('func-locs.update', $funcLoc) }}">
                @csrf
                @method('PUT')

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Kode (full path)</label>
                    <input type="text" value="{{ $funcLoc->code }}" disabled
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono bg-slate-50 text-slate-500">
                    <p class="text-xs text-slate-400 mt-1">Kode bersifat permanen dan tidak bisa diubah setelah dibuat.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Level</label>
                    <input type="text" value="L{{ $funcLoc->level }} - {{ $levelLabels[$funcLoc->level] ?? 'Tidak Diketahui' }}" disabled
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nama</label>
                    <input type="text" name="name" value="{{ old('name', $funcLoc->name) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                           required>
                </div>

                <div class="mb-6">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $funcLoc->is_active) ? 'checked' : '' }}
                               class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-slate-700">Aktif</span>
                    </label>
                    <p class="text-xs text-slate-400 mt-1">Node nonaktif tidak akan muncul di keyboard pemilihan FuncLoc pada bot.</p>
                </div>

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

        @if ($canAddChild)
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h2 class="text-sm font-semibold text-slate-800 mb-1">Tambah Anak Baru</h2>
                <p class="text-xs text-slate-400 mb-4">Anak baru akan dibuat langsung di bawah <span class="font-mono">{{ $funcLoc->code }}</span> tanpa berpindah halaman.</p>

                @if ($addChildGeneralErrors->isNotEmpty())
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($addChildGeneralErrors as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('func-locs.store') }}"
                      x-data="addChildForm({{ Illuminate\Support\Js::from($initialChildren) }}, {{ Illuminate\Support\Js::from($childRowErrors) }}, {{ Illuminate\Support\Js::from($funcLoc->code) }})"
                      x-init="init()">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $funcLoc->id }}">

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

                    <div class="flex items-center justify-between">
                        <button type="button" @click="addRow()"
                                class="text-xs font-medium text-blue-600 hover:text-blue-700">
                            + Tambah Baris
                        </button>

                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                            Simpan Anak
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection

@if ($canAddChild)
    @push('scripts')
    <script>
        function addChildForm(initialChildren, rowErrors, parentPrefix) {
            return {
                parentPrefix: parentPrefix || '',
                rows: [],
                rowErrors: rowErrors || {},
                nextKey: 0,

                init() {
                    this.rows = (initialChildren && initialChildren.length ? initialChildren : [{ segment: '', name: '' }])
                        .map((child) => this.makeRow(child.segment, child.name));
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
@endif
