@extends('layouts.app')

@section('title', 'Tambah Laporan Kerjaan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('work-orders.index') }}" class="hover:text-slate-700">Laporan Kerjaan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah</span>
@endsection

@section('content')
    <div class="bg-white rounded-xl border border-slate-200 p-6 max-w-3xl mx-auto">
        <h2 class="text-lg font-semibold text-slate-900 mb-6">Form Tambah Laporan Kerjaan</h2>

        <form method="POST" action="{{ route('work-orders.store') }}" class="space-y-5">
            @csrf

            {{-- Equipment Tag --}}
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Equipment Tag <span class="text-red-500">*</span></label>
                <select name="equipment_tag" id="equipment_tag" required
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">— Pilih Equipment —</option>
                    @foreach($equipmentList as $eq)
                        <option value="{{ $eq->equipment_tag }}" {{ old('equipment_tag') === $eq->equipment_tag ? 'selected' : '' }}>
                            {{ $eq->equipment_tag }} — {{ $eq->pt_location }} {{ $eq->plant ? '/ ' . $eq->plant : '' }}
                        </option>
                    @endforeach
                </select>
                @error('equipment_tag')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Row: Tanggal --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tanggal Kejadian <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_kejadian" value="{{ old('tanggal_kejadian', date('Y-m-d')) }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    @error('tanggal_kejadian')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Tanggal Eksekusi Selesai <span class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_eksekusi_selesai" value="{{ old('tanggal_eksekusi_selesai', date('Y-m-d')) }}" required
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    @error('tanggal_eksekusi_selesai')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Jenis Pekerjaan --}}
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">Jenis Pekerjaan <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-3 gap-3">
                    <label class="flex items-center p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50 transition-colors">
                        <input type="radio" name="jenis_pekerjaan" value="CM" {{ old('jenis_pekerjaan') === 'CM' ? 'checked' : '' }}
                               class="work-order-jenis mr-2 text-teal-600 focus:ring-teal-500" onchange="toggleSesuaiRencana()">
                        <div>
                            <span class="text-sm font-medium text-slate-800">CM</span>
                            <p class="text-xs text-slate-400">Corrective (darurat)</p>
                        </div>
                    </label>
                    <label class="flex items-center p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50 transition-colors">
                        <input type="radio" name="jenis_pekerjaan" value="dCM" {{ old('jenis_pekerjaan') === 'dCM' ? 'checked' : '' }}
                               class="work-order-jenis mr-2 text-amber-600 focus:ring-amber-500" onchange="toggleSesuaiRencana()">
                        <div>
                            <span class="text-sm font-medium text-slate-800">dCM</span>
                            <p class="text-xs text-slate-400">Deferred (ditunda)</p>
                        </div>
                    </label>
                    <label class="flex items-center p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition-colors">
                        <input type="radio" name="jenis_pekerjaan" value="PM" {{ old('jenis_pekerjaan') === 'PM' ? 'checked' : '' }}
                               class="work-order-jenis mr-2 text-blue-600 focus:ring-blue-500" onchange="toggleSesuaiRencana()">
                        <div>
                            <span class="text-sm font-medium text-slate-800">PM</span>
                            <p class="text-xs text-slate-400">Preventive (terjadwal)</p>
                        </div>
                    </label>
                </div>
                @error('jenis_pekerjaan')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Linked Finding ID --}}
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">
                    Linked Finding ID
                    <span class="text-slate-400 font-normal text-xs ml-1">(opsional — asal-usul dari Finding CM)</span>
                </label>
                <select name="linked_finding_id" id="linked_finding_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">— Tidak ada Finding terkait —</option>
                </select>
                <p id="findingLoading" class="text-xs text-slate-400 hidden">Memuat data finding...</p>
                @error('linked_finding_id')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Sesuai Rencana (hanya untuk dCM) --}}
            <div id="sesuaiRencanaField" class="space-y-1.5 {{ old('jenis_pekerjaan') === 'dCM' ? '' : 'hidden' }}">
                <label class="block text-sm font-medium text-slate-700">
                    Sesuai Rencana?
                    <span class="text-red-500">*</span>
                    <span class="text-slate-400 font-normal text-xs ml-1">(wajib diisi untuk dCM)</span>
                </label>
                <div class="flex gap-4">
                    <label class="inline-flex items-center">
                        <input type="radio" name="sesuai_rencana" value="ya" {{ old('sesuai_rencana') === 'ya' ? 'checked' : '' }}
                               class="mr-2 text-green-600 focus:ring-green-500">
                        <span class="text-sm text-slate-700">Ya</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="sesuai_rencana" value="tidak" {{ old('sesuai_rencana') === 'tidak' ? 'checked' : '' }}
                               class="mr-2 text-red-600 focus:ring-red-500">
                        <span class="text-sm text-slate-700">Tidak</span>
                    </label>
                </div>
                @error('sesuai_rencana')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Deskripsi --}}
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">
                    Deskripsi
                    <span class="text-slate-400 font-normal text-xs ml-1">(opsional)</span>
                </label>
                <textarea name="deskripsi" rows="3"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">{{ old('deskripsi') }}</textarea>
                @error('deskripsi')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Root Cause --}}
            <div class="space-y-1.5">
                <label class="block text-sm font-medium text-slate-700">
                    Root Cause
                    <span class="text-slate-400 font-normal text-xs ml-1">(opsional — untuk Pareto nantinya)</span>
                </label>
                <input type="text" name="root_cause" value="{{ old('root_cause') }}" maxlength="255"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                       placeholder="Contoh: Mechanical seal leak, Bearing failure, dll">
                @error('root_cause')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Tombol --}}
            <div class="flex gap-3 pt-2">
                <button type="submit" class="px-6 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Simpan Laporan
                </button>
                <a href="{{ route('work-orders.index') }}" class="px-6 py-2.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </a>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
    /**
     * Toggle visibility field "Sesuai Rencana" berdasarkan jenis pekerjaan.
     */
    function toggleSesuaiRencana() {
        var selected = document.querySelector('input[name="jenis_pekerjaan"]:checked');
        var field = document.getElementById('sesuaiRencanaField');
        if (selected && selected.value === 'dCM') {
            field.classList.remove('hidden');
        } else {
            field.classList.add('hidden');
            // Reset nilai jika bukan dCM
            document.querySelectorAll('input[name="sesuai_rencana"]').forEach(function(r) { r.checked = false; });
        }
    }

    /**
     * Load findings berdasarkan equipment_tag yang dipilih.
     */
    document.addEventListener('DOMContentLoaded', function() {
        var eqSelect = document.getElementById('equipment_tag');
        var findingSelect = document.getElementById('linked_finding_id');
        var loadingEl = document.getElementById('findingLoading');

        if (eqSelect) {
            eqSelect.addEventListener('change', function() {
                var tag = this.value;
                // Clear existing options
                findingSelect.innerHTML = '<option value="">— Tidak ada Finding terkait —</option>';

                if (!tag) return;

                loadingEl.classList.remove('hidden');
                findingSelect.disabled = true;

                fetch('{{ url('work-orders') }}/findings-by-equipment/' + encodeURIComponent(tag))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.length > 0) {
                            data.forEach(function(f) {
                                var opt = document.createElement('option');
                                opt.value = f.id;
                                opt.textContent = (f.kode_finding || '#' + f.id) + ' — ' + (f.kategori || '') + ' (' + f.severity + ', ' + f.tanggal_temuan + ')';
                                findingSelect.appendChild(opt);
                            });
                        }
                    })
                    .catch(function(err) {
                        console.error('Error loading findings:', err);
                    })
                    .finally(function() {
                        loadingEl.classList.add('hidden');
                        findingSelect.disabled = false;
                    });
            });

            // Trigger jika ada old value
            if (eqSelect.value) {
                eqSelect.dispatchEvent(new Event('change'));
            }
        }
    });
    </script>
    @endpush
@endsection
