@extends('layouts.app')

@section('title', 'Import Asset dari Excel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Import Excel</span>
@endsection

@section('content')
    <div x-data="importManager()" class="space-y-6">
        <!-- Upload Form -->
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Upload File Excel</h3>
            <p class="text-sm text-slate-500 mb-4">
                Upload file ZPM export dari SAP. Format .xlsx, .xls, atau .csv (maks 10MB).
                <br>Kolom yang diproses: Equipment, Description, TechIdentNo., Object Type, Functional Loc.
            </p>

            <form @submit.prevent="uploadFile" class="space-y-4">
                <div class="flex items-center gap-4">
                    <input type="file" x-ref="fileInput" accept=".xlsx,.xls,.csv"
                           class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4
                                  file:rounded-lg file:border-0 file:text-sm file:font-medium
                                  file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                </div>
                <div class="flex gap-3">
                    <button type="submit" x-bind:disabled="loading"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                        <span x-show="!loading">Analisa File</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Menganalisa...
                        </span>
                    </button>
                    <a href="{{ route('assets.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>

        <!-- Error -->
        <template x-if="error">
            <div class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                <div>
                    <p class="font-medium">Gagal Membaca File</p>
                    <p class="mt-0.5" x-text="error"></p>
                </div>
            </div>
        </template>

        <!-- Preview -->
        <template x-if="analysis">
            <div class="space-y-6">
                <!-- Summary -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-green-600" x-text="analysis.clean.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Baru & Siap Import</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-amber-600" x-text="analysis.duplicate.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Duplikat</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-red-600" x-text="analysis.no_equip.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Tanpa Equipment No</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-slate-600" x-text="analysis.total_rows"></p>
                        <p class="text-xs text-slate-500 mt-1">Total Baris</p>
                    </div>
                </div>

                <!-- FuncLoc Preview -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-medium text-slate-900">Functional Location Terdeteksi</h3>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium">
                                <span x-text="analysis.funcloc_preview.new_count"></span> FuncLoc Baru
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">
                                <span x-text="analysis.funcloc_preview.existing_count"></span> Sudah Ada
                            </span>
                        </div>
                    </div>

                    <div class="max-h-72 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                        <template x-for="node in analysis.funcloc_preview.nodes" :key="node.code">
                            <div class="flex items-center gap-3 px-4 py-2 text-sm" :style="{ paddingLeft: (16 + node.level * 20) + 'px' }">
                                <span class="font-mono text-xs text-slate-700 flex-1 truncate" x-text="node.code"></span>
                                <span class="text-xs text-slate-400 shrink-0" x-text="'L' + node.level"></span>
                                <span x-show="!node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded shrink-0">FuncLoc Baru</span>
                                <span x-show="node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded shrink-0">Sudah Ada</span>
                            </div>
                        </template>
                        <template x-if="analysis.funcloc_preview.nodes.length === 0">
                            <div class="px-4 py-6 text-center text-sm text-slate-400">Tidak ada Functional Location terdeteksi di file ini.</div>
                        </template>
                    </div>
                </div>

                <!-- Actions -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Pilihan Aksi Import</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Aksi untuk Duplikat</label>
                            <select x-model="duplicateAction" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="skip">Skip — Abaikan (biarkan data lama)</option>
                                <option value="replace">Replace — Ganti dengan data baru</option>
                                <option value="keep_flag">Tandai Review — Ubah status jadi perlu review</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Aksi untuk Tanpa Equipment No</label>
                            <select x-model="noEquipAction" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="flag">Masukkan & Tandai Review</option>
                                <option value="skip">Abaikan baris tersebut</option>
                                <option value="cancel">Batalkan seluruh import</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-3">
                        <button @click="executeImport"
                                x-bind:disabled="executing"
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                            <span x-show="!executing">Jalankan Import</span>
                            <span x-show="executing" class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Mengimport...
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Result -->
                <template x-if="result">
                    <div>
                        <div x-show="result.status === 'success'"
                             class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-green-50 border-green-200 text-green-800">
                            <div>
                                <p class="font-medium">Hasil Import</p>
                                <p class="mt-0.5" x-text="result.message"></p>
                            </div>
                        </div>
                        <div x-show="result.status !== 'success'"
                             class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                            <div>
                                <p class="font-medium">Hasil Import</p>
                                <p class="mt-0.5" x-text="result.message"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
@endsection

@push('scripts')
<script>
function importManager() {
    return {
        analysis: null,
        error: null,
        loading: false,
        executing: false,
        result: null,
        duplicateAction: 'skip',
        noEquipAction: 'flag',

        uploadFile() {
            const fileInput = this.$refs.fileInput;
            if (!fileInput.files || !fileInput.files[0]) {
                this.error = 'Pilih file terlebih dahulu.';
                return;
            }

            this.loading = true;
            this.error = null;
            this.analysis = null;
            this.result = null;

            const formData = new FormData();
            formData.append('file', fileInput.files[0]);

            const url = '{{ route('assets.import.preview') }}';
            console.log('Uploading to:', url);

            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: formData,
            })
            .then(res => {
                console.log('Response status:', res.status);
                if (!res.ok) {
                    return res.json().then(errData => {
                        throw new Error(errData.message || 'HTTP ' + res.status);
                    }).catch(e => {
                        // If JSON parse fails, try to get text
                        if (e instanceof SyntaxError) {
                            throw new Error('Server error ' + res.status + '. Cek log untuk detail.');
                        }
                        throw e;
                    });
                }
                return res.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    this.analysis = data.analysis;
                } else {
                    this.error = data.message || 'Gagal membaca file.';
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                this.error = err.message || 'Gagal mengupload file. Cek console (F12) untuk detail.';
            })
            .finally(() => {
                this.loading = false;
            });
        },

        executeImport() {
            this.executing = true;
            this.result = null;

            fetch('{{ route('assets.import.execute') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    analysis: this.analysis,
                    duplicate_action: this.duplicateAction,
                    no_equip_action: this.noEquipAction,
                    filename: this.$refs.fileInput?.files[0]?.name || 'unknown.xlsx',
                }),
            })
            .then(res => res.json())
            .then(data => {
                this.result = data;
                if (data.status === 'success') {
                    this.analysis = null;
                }
            })
            .catch(err => {
                this.result = { status: 'error', message: 'Terjadi kesalahan.' };
            })
            .finally(() => {
                this.executing = false;
            });
        },
    };
}
</script>
@endpush
