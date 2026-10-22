@extends('layouts.app')

@section('title', 'Edit Asset')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.show', $asset) }}" class="hover:text-slate-700">{{ $asset->equipment_no ?? 'Detail' }}</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Edit</span>
@endsection

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <form method="POST" action="{{ route('assets.update', $asset) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Equipment No</label>
                        <div class="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-sm font-mono text-slate-600">
                            {{ $asset->equipment_no ?? 'Belum ada' }}
                        </div>
                        <p class="text-xs text-slate-500">
                            Nomor ini dibuat sistem dan tidak dapat diubah manual.
                        </p>
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" value="{{ old('tech_ident_no', $asset->tech_ident_no) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono">
                        @error('tech_ident_no') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Deskripsi</label>
                    <textarea name="description" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('description', $asset->description) }}</textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Equipment Type</label>
                        <input type="text" name="equipment_type" value="{{ old('equipment_type', $asset->equipment_type) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono">
                    </div>
                    <x-funcloc-picker
                        name="funcloc_id"
                        label="Lokasi (Functional Location)"
                        :selected-id="$selectedFuncLocId"
                        :selected-label="$selectedFuncLocLabel"
                        placeholder="Cari kode atau nama lokasi..."
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Manufacturer</label>
                        <input type="text" name="manufacturer" value="{{ old('manufacturer', $asset->manufacturer) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Model Number</label>
                        <input type="text" name="model_number" value="{{ old('model_number', $asset->model_number) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Construct Year</label>
                        <input type="text" name="construct_year" value="{{ old('construct_year', $asset->construct_year) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <input type="hidden" name="status" value="{{ old('status', $asset->status) }}">

                <div class="space-y-3 border-t border-slate-100 pt-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Foto Equipment</label>
                        <p class="text-xs text-slate-500">Bisa menambahkan lebih dari satu foto.</p>
                    </div>

                    @if(count($asset->photo_urls) > 0)
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            @foreach($asset->photo_urls as $path => $url)
                                @php $fotoPath = $asset->photos[$path] ?? ''; @endphp
                                <label class="relative block aspect-square rounded-lg overflow-hidden border border-slate-200 bg-slate-50 group cursor-pointer">
                                    <img src="{{ $url }}" alt="Foto equipment" class="w-full h-full object-cover">
                                    <span class="absolute inset-x-0 bottom-0 bg-black/60 text-white text-[10px] px-2 py-1 flex items-center gap-1.5">
                                        <input type="checkbox" name="hapus_foto[]" value="{{ $fotoPath }}" class="rounded border-white/50 text-rose-600 focus:ring-rose-500">
                                        Hapus foto
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-slate-400">Belum ada foto.</p>
                    @endif

                    <div>
                        <label for="photos" class="block text-xs font-medium text-slate-600 mb-1">Tambah foto baru</label>
                        <input type="file" name="photos[]" id="photos" multiple accept="image/*"
                               class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:font-medium">
                        <div id="previewFoto" class="flex flex-wrap gap-2 mt-2"></div>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Simpan Perubahan
                    </button>
                    <a href="{{ route('assets.show', $asset) }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('photos');
    const preview = document.getElementById('previewFoto');

    if (!input || !preview) return;

    input.addEventListener('change', function () {
        preview.innerHTML = '';
        Array.from(input.files || []).forEach(function (file) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.className = 'w-16 h-16 rounded-lg object-cover border border-slate-200';
            img.onload = () => URL.revokeObjectURL(img.src);
            preview.appendChild(img);
        });
    });
});
</script>
@endpush
