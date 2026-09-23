{{--
    Partial: pilihan lokasi (Functional Location) dan informasi Tipe Equipment
    untuk form tambah/edit perangkat IT.

    Dipakai form.blade.php lewat satu baris @include. Lokasi perangkat IT
    memakai hierarki yang sama dengan asset industri (PT -> Departemen -> Area
    -> Section) supaya perangkat ini bisa dipilih pada wizard laporan Telegram.

    Variabel yang dipakai:
    - $itAsset      : instance ItAsset (bisa instance baru untuk form tambah)
    - $funclocGroups: array<string, array<int, array{id: int, label: string}>>
--}}

<div>
    <label class="block text-xs font-medium text-slate-500 mb-1.5">
        Lokasi (Functional Location)
    </label>
    <select name="funcloc_id"
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="">- Belum ditentukan -</option>
        @foreach (($funclocGroups ?? []) as $pt => $items)
            <optgroup label="{{ $pt }}">
                @foreach ($items as $item)
                    <option value="{{ $item['id'] }}"
                        @selected((int) old('funcloc_id', $itAsset->funcloc_id) === (int) $item['id'])>
                        {{ $item['label'] }}
                    </option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    <p class="text-[11px] text-slate-400 mt-1">
        Dipakai untuk pencarian lokasi di wizard laporan Telegram. Perangkat IT
        dan asset industri memakai satu daftar Functional Location yang sama.
    </p>
</div>

<div>
    <label class="block text-xs font-medium text-slate-500 mb-1.5">Tipe Equipment</label>
    <input type="text" value="{{ $itAsset->equipmentTypeLabel() }}" readonly
           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600">
    <p class="text-[11px] text-slate-400 mt-1">
        Diisi otomatis dari Tipe Perangkat dan dipakai sebagai jenis alat di
        hierarki wizard laporan (lihat ItAsset::DEVICE_EQUIPMENT_TYPES).
    </p>
</div>
