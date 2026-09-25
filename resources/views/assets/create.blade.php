@extends('layouts.app')

@section('title', 'Tambah Asset Baru')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tambah Asset</span>
@endsection

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="mb-4 rounded-lg border border-teal-100 bg-teal-50 p-3">
                <p class="text-xs text-teal-800 leading-relaxed">
                    <span class="font-semibold">Equipment No tidak wajib diisi.</span>
                    Kosongkan kolom tersebut dan nomornya dibuat otomatis mengikuti seri milik PT
                    dari lokasi yang dipilih (contoh seri yang berjalan sekarang:
                    EPE 10003479, CES 60000318, NPA 70000288).
                </p>
            </div>

            <form method="POST" action="{{ route('assets.store') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">
                            Equipment No
                            <span class="text-xs font-normal text-slate-400">(opsional)</span>
                        </label>
                        <input type="text" name="equipment_no" value="{{ old('equipment_no') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                               placeholder="Kosongkan = otomatis">
                        <p id="petunjuk-equipment-no" class="text-xs text-slate-500">
                            Nomor dibuat otomatis setelah lokasi dipilih.
                        </p>
                        @error('equipment_no') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Tech Ident No</label>
                        <input type="text" name="tech_ident_no" value="{{ old('tech_ident_no') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Deskripsi</label>
                    <textarea name="description" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('description') }}</textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Equipment Type</label>
                        <input type="text" name="equipment_type" value="{{ old('equipment_type') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                               placeholder="PUMP">
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
                        <input type="text" name="manufacturer" value="{{ old('manufacturer') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Model Number</label>
                        <input type="text" name="model_number" value="{{ old('model_number') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Construct Year</label>
                        <input type="text" name="construct_year" value="{{ old('construct_year') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>


                <div class="space-y-1.5">
                    <label class="block text-sm font-medium text-slate-700">Status</label>
                    <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                        <option value="needs_review" {{ old('status') === 'needs_review' ? 'selected' : '' }}>Perlu Review</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Simpan
                    </button>
                    <a href="{{ route('assets.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
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
        var inputNomor  = document.querySelector('input[name="equipment_no"]');
        var inputLokasi = document.querySelector('input[name="funcloc_id"]');
        var petunjuk    = document.getElementById('petunjuk-equipment-no');

        if (! inputNomor || ! inputLokasi || ! petunjuk) {
            return;
        }

        var lokasiTerakhir = null;

        /**
         * Tampilkan pratinjau nomor equipment yang akan dipakai untuk lokasi
         * yang sedang terpilih. Nomor hanya muncul sebagai placeholder dan
         * keterangan (kolom tetap boleh kosong), karena nomor aslinya baru
         * ditentukan server saat data disimpan.
         */
        function tampilkanPratinjau() {
            var idLokasi = (inputLokasi.value || '').trim();

            if (idLokasi === lokasiTerakhir) {
                return;
            }

            lokasiTerakhir = idLokasi;

            if (idLokasi === '') {
                petunjuk.textContent = 'Nomor dibuat otomatis setelah lokasi dipilih.';
                return;
            }

            petunjuk.textContent = 'Menghitung nomor otomatis...';

            var url = '{{ route('assets.locations.next-equipment-no') }}?funcloc_id=' + encodeURIComponent(idLokasi);

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (respon) { return respon.json(); })
                .then(function (data) {
                    if (! data.success) {
                        petunjuk.textContent = 'Nomor otomatis belum bisa dihitung untuk lokasi ini.';
                        return;
                    }

                    inputNomor.placeholder = data.equipment_no;
                    petunjuk.textContent = 'Nomor otomatis berikutnya: ' + data.equipment_no
                        + (data.pt ? ' (PT ' + data.pt + ')' : '')
                        + '. Kosongkan kolom ini untuk memakainya.';
                })
                .catch(function () {
                    petunjuk.textContent = 'Nomor otomatis belum bisa dihitung untuk lokasi ini.';
                });
        }

        // Nilai input lokasi diisi oleh komponen picker (bukan diketik langsung
        // ke input), jadi perubahannya dipantau lewat event sekaligus berkala.
        inputLokasi.addEventListener('input', tampilkanPratinjau);
        inputLokasi.addEventListener('change', tampilkanPratinjau);
        window.setInterval(tampilkanPratinjau, 700);
        tampilkanPratinjau();
    });
</script>
@endpush

