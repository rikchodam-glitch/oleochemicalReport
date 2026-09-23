{{--
    Partial: pilihan lokasi (Functional Location), informasi Tipe Equipment, dan
    dropdown Connect To untuk form tambah/edit perangkat IT.

    Dipakai form.blade.php lewat satu baris @include. Lokasi perangkat IT
    memakai hierarki yang sama dengan asset industri (PT -> Departemen -> Area
    -> Section) supaya perangkat ini bisa dipilih pada wizard laporan Telegram.

    Dropdown Connect To hanya ditampilkan untuk perangkat CCTV/NVR (lihat
    script kecil di bawah) dan isinya berasal dari partial
    _connect-to-select.blade.php.

    Variabel yang dipakai:
    - $itAsset         : instance ItAsset (bisa instance baru untuk form tambah)
    - $funclocGroups   : array<string, array<int, array{id: int, label: string}>>
    - $connectToGroups : array<string, array<int, string>>
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
                        data-plant="{{ $item['plant'] ?? '' }}"
                        data-department="{{ $item['department'] ?? '' }}"
                        data-code="{{ $item['code'] ?? '' }}"
                        data-name="{{ $item['name'] ?? '' }}"
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

{{-- Connect To + spesifikasi terstruktur + slot channel: lihat partial masing-masing. --}}
<div id="connectToWrapper" class="{{ in_array($itAsset->device_type, ['CCTV', 'NVR', 'SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED', 'ACCESS_POINT', 'ACCESS_DOOR'], true) ? '' : 'hidden' }}">
    @include('it-assets.partials._connect-to-select')
</div>

<div id="channelSlotSection" class="{{ in_array($itAsset->device_type, ['CCTV'], true) ? '' : 'hidden' }}">
    @include('it-assets.partials._cctv-channel-slots')
</div>

@include('it-assets.partials._spec-fields')

<script>
(function () {
    /**
     * Tampilkan atau sembunyikan blok Connect To dan slot Channel mengikuti
     * Tipe Perangkat yang dipilih, supaya field ini tidak membingungkan untuk
     * tipe lain.
     */
    function syncSectionVisibility() {
        const tipe = document.getElementById('device-type-select');

        if (!tipe) {
            return;
        }

        const wrapper = document.getElementById('connectToWrapper');
        if (wrapper) {
            wrapper.classList.toggle(
                'hidden',
                ['CCTV', 'NVR', 'SWITCH_MANAGED', 'SWITCH_POE_UNMANAGED', 'ACCESS_POINT', 'ACCESS_DOOR'].indexOf(tipe.value) === -1
            );
        }

        const slot = document.getElementById('channelSlotSection');
        if (slot) {
            slot.classList.toggle('hidden', tipe.value !== 'CCTV');
        }
    }

    /**
     * Isi Plant, Department, dan Lokasi mengikuti Functional Location yang
     * dipilih, sehingga user cukup memilih satu kali di Lokasi FuncLoc.
     * Hanya berjalan saat pilihan funcloc diubah (bukan saat halaman dibuka)
     * agar detail lokasi yang sudah diketik manual tidak tertimpa.
     */
    function syncFieldLokasi() {
        const select = document.querySelector('select[name="funcloc_id"]');

        if (!select || !select.value) {
            return;
        }

        const opsi = select.options[select.selectedIndex];

        if (!opsi) {
            return;
        }

        const isi = function (nama, nilai) {
            const input = document.querySelector('[name="' + nama + '"]');

            if (input && nilai) {
                input.value = nilai;
            }
        };

        isi('plant', opsi.dataset.plant || '');
        isi('department', opsi.dataset.department || '');
        isi('location', opsi.dataset.code || '');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const tipe = document.getElementById('device-type-select');

        if (tipe) {
            tipe.addEventListener('change', syncSectionVisibility);
        }

        const funcloc = document.querySelector('select[name="funcloc_id"]');

        if (funcloc) {
            funcloc.addEventListener('change', syncFieldLokasi);
        }

        syncSectionVisibility();
    });
})();
</script>

