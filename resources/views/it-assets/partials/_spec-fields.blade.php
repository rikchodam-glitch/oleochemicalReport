{{--
    Partial: field terstruktur pengganti textarea "Spesifikasi (JSON opsional)".

    Semua kunci spec yang berbentuk nilai tunggal (bukan array) ditampilkan
    sebagai input terpisah dengan nama spec_fields[<kunci>], sehingga user tidak
    perlu menyunting JSON. Textarea JSON asli disembunyikan oleh script di
    partial ini (tetap ikut terkirim agar data yang belum punya field sendiri
    tidak hilang) dan nilainya ditimpa oleh field terstruktur di controller.

    Kunci yang TIDAK ditampilkan di sini karena sudah punya field sendiri:
    - cpu, monitor            -> bagian Spesifikasi PC terstruktur
    - pointing, show, channel -> bagian CCTV (termasuk pemilih slot channel NVR)
    - connect_to              -> dropdown Connect To Switch/Access Point

    Variabel yang dipakai:
    - $itAsset : instance ItAsset
--}}

@php
    $kunciKhusus = ['cpu', 'monitor', 'pointing', 'show', 'channel', 'connect_to'];
    $specDatar   = [];

    foreach (($itAsset->spec ?? []) as $kunci => $nilai) {
        if (in_array($kunci, $kunciKhusus, true) || is_array($nilai)) {
            continue;
        }

        $specDatar[$kunci] = (string) $nilai;
    }

    $labelKunci = [
        'header'   => 'Header (kode perangkat pada data CCTV)',
        'pic'      => 'PIC',
        'playback' => 'Playback',
        'group'    => 'Group',
        'remark'   => 'Remark',
        'capacity' => 'Capacity',
        'penggunaan' => 'Penggunaan',
        'perangkat'  => 'Perangkat',
    ];
@endphp

<div id="specFieldsWrapper">
    @if ($specDatar !== [])
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-medium text-slate-500">Spesifikasi Tambahan</span>
            <span class="text-[11px] text-slate-400">Nilai lama dari file import, bisa diubah langsung</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($specDatar as $kunci => $nilai)
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        {{ $labelKunci[$kunci] ?? ucwords(str_replace('_', ' ', $kunci)) }}
                    </label>
                    <input type="text" name="spec_fields[{{ $kunci }}]" value="{{ old('spec_fields.' . $kunci, $nilai) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
(function () {
    /**
     * Sembunyikan textarea JSON asli (tetap terkirim, jadi data yang belum
     * punya field sendiri tidak hilang). Nilainya ditimpa oleh field
     * terstruktur di controller.
     */
    function gantiTextareaJson() {
        const section = document.getElementById('specJsonSection');
        if (section) {
            section.classList.add('hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', gantiTextareaJson);
})();
</script>
