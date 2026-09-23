{{--
    Partial: pemilih slot Channel untuk perangkat CCTV.

    Saat NVR (field "NVR (Show)") dipilih, daftar channel dibangun dari
    kapasitas NVR tersebut (kolom channel pada spec perangkat NVR, mis. 16/32)
    dan slot yang sudah dipakai kamera lain ditandai serta tidak bisa dipilih
    ulang. Kamera yang sedang diedit tetap boleh memakai slot miliknya sendiri.

    Teknis: select ini memakai nama field yang sama dengan input Channel asli
    (spec_cctv_channel). Input asli dimatikan (disabled) oleh script di bawah
    agar tidak ikut terkirim, sehingga nilai dari sini yang tersimpan. Bila
    JavaScript mati, input asli tetap berfungsi seperti sebelumnya.

    Variabel yang dipakai:
    - $itAsset        : instance ItAsset (perangkat CCTV yang diedit)
    - $nvrKapasitas   : array<string, int>   tag NVR -> jumlah channel
    - $nvrPemakaian   : array<string, array<string, string>> tag NVR -> channel -> tag kamera
--}}

@php
    $slotSaatIni = (string) old('spec_cctv_channel', $itAsset->spec['channel'] ?? '');
    $nvrSaatIni  = (string) old('spec_cctv_show', $itAsset->spec['show'] ?? '');
@endphp

<div id="channelSlotWrapper"
     data-saat-ini="{{ $slotSaatIni }}"
     data-nvr-saat-ini="{{ $nvrSaatIni }}">
    <label class="block text-xs font-medium text-slate-500 mb-1.5">Channel (slot di NVR)</label>
    <select name="spec_cctv_channel" id="channelSlotSelect"
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="{{ $slotSaatIni }}">{{ $slotSaatIni !== '' ? $slotSaatIni : '- Pilih NVR dulu -' }}</option>
    </select>
    <p class="text-[11px] text-slate-400 mt-1" id="channelSlotInfo">
        Pilih NVR terlebih dahulu, lalu pilih slot channel yang masih kosong.
        Slot yang sudah terpakai ditandai nama kamera pemakainya.
    </p>
</div>

<script>
(function () {
    const kapasitasNvr = @json($nvrKapasitas ?? []);
    const pemakaianNvr = @json($nvrPemakaian ?? []);
    const cadanganKapasitas = 32;

    /**
     * Bangun daftar option slot channel untuk NVR terpilih.
     *
     * @param {string} nvr
     * @param {string} slotSaatIni
     * @returns {HTMLOptionElement[]}
     */
    function buatOption(nvr, slotSaatIni) {
        const total = parseInt(kapasitasNvr[nvr] || cadanganKapasitas, 10);
        const dipakai = pemakaianNvr[nvr] || {};
        const options = [];

        const kosong = document.createElement('option');
        kosong.value = '';
        kosong.textContent = '- Pilih slot channel -';
        options.push(kosong);

        for (let i = 1; i <= total; i++) {
            const slot = 'D' + i;
            const pemakai = dipakai[slot] || null;
            const milikSendiri = slot === slotSaatIni;
            const opt = document.createElement('option');

            opt.value = slot;
            opt.textContent = slot
                + (pemakai && !milikSendiri ? ' - dipakai: ' + pemakai : '')
                + (milikSendiri ? ' - slot kamera ini' : '');
            opt.disabled = Boolean(pemakai) && !milikSendiri;
            opt.selected = milikSendiri;

            options.push(opt);
        }

        return options;
    }

    /**
     * Perbarui dropdown slot channel mengikuti NVR yang dipilih pada form.
     */
    function perbaruiSlot() {
        const selectNvr = document.querySelector('select[name="spec_cctv_show"]');
        const slot = document.getElementById('channelSlotSelect');
        const info = document.getElementById('channelSlotInfo');

        if (!selectNvr || !slot) {
            return;
        }

        const wrapper = document.getElementById('channelSlotWrapper');
        const slotSaatIni = wrapper ? (wrapper.dataset.saatIni || '') : '';
        const nvr = selectNvr.value;

        slot.innerHTML = '';

        if (!nvr) {
            slot.appendChild(createOpt('- Pilih NVR dulu -', ''));
            if (info) {
                info.textContent = 'Pilih NVR terlebih dahulu, lalu pilih slot channel yang masih kosong.';
            }

            return;
        }

        buatOption(nvr, slotSaatIni).forEach(function (opt) {
            slot.appendChild(opt);
        });

        const total = parseInt(kapasitasNvr[nvr] || cadanganKapasitas, 10);
        const terpakai = Object.keys(pemakaianNvr[nvr] || {}).length;

        if (info) {
            info.textContent = 'NVR ' + nvr + ' punya ' + total + ' channel, '
                + terpakai + ' sudah terpakai, ' + Math.max(total - terpakai, 0) + ' masih kosong.';
        }
    }

    /**
     * Buat satu elemen option sederhana.
     *
     * @param {string} teks
     * @param {string} nilai
     * @returns {HTMLOptionElement}
     */
    function createOpt(teks, nilai) {
        const opt = document.createElement('option');
        opt.value = nilai;
        opt.textContent = teks;

        return opt;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const selectNvr = document.querySelector('select[name="spec_cctv_show"]');
        const slot = document.getElementById('channelSlotSelect');

        if (!selectNvr || !slot) {
            return;
        }

        // Input Channel asli (dari form dasar) dimatikan supaya tidak ikut
        // terkirim; nilai dari dropdown ini yang dipakai.
        document.querySelectorAll('input[name="spec_cctv_channel"]').forEach(function (input) {
            input.disabled = true;
        });

        selectNvr.addEventListener('change', perbaruiSlot);
        perbaruiSlot();
    });
})();
</script>

<script>
(function () {
    /**
     * Perbaikan tampilan untuk kasus data lama:
     * 1. Nilai NVR yang belum terdaftar (mis. "NVR 4") ditambahkan sebagai opsi
     *    cadangan pada dropdown Show (NVR) supaya field tidak tampak kosong,
     *    lalu pilihan slot channel dihitung ulang.
     * 2. Kolom Channel asli pada kartu Spesifikasi CCTV disembunyikan karena
     *    sudah digantikan pemilih slot channel.
     */
    document.addEventListener('DOMContentLoaded', function () {
        const nvrWrapper = document.getElementById('channelSlotWrapper');
        const selectNvr  = document.querySelector('select[name="spec_cctv_show"]');

        if (!nvrWrapper || !selectNvr) {
            return;
        }

        const nilai    = nvrWrapper.dataset.nvrSaatIni || '';
        const sudahAda = Array.prototype.some.call(selectNvr.options, function (option) {
            return option.value === nilai;
        });

        if (nilai !== '' && !sudahAda) {
            const option = document.createElement('option');
            option.value = nilai;
            option.textContent = nilai + ' (belum terdaftar di daftar NVR)';
            option.selected = true;
            selectNvr.appendChild(option);

            // Bangun ulang daftar slot memakai nama NVR tersebut.
            selectNvr.dispatchEvent(new Event('change'));
        }

        document.querySelectorAll('input[name="spec_cctv_channel"]').forEach(function (input) {
            const kolom = input.closest('div');
            if (kolom) {
                kolom.classList.add('hidden');
            }
        });
    });
})();
</script>

