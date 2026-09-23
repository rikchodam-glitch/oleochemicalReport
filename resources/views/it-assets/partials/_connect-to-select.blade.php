{{--
    Partial: dropdown "Connect To" untuk perangkat CCTV/NVR.

    Isinya diambil dari perangkat jaringan yang sudah terdaftar di modul IT
    Assets: Switch Managed, Switch PoE Unmanaged, dan Access Point (termasuk
    Radio yang dipetakan sebagai Access Point). Dikirim controller lewat
    variabel $connectToGroups (label tipe => daftar tag_name).

    Dipakai form.blade.php lewat satu baris @include.

    Variabel yang dipakai:
    - $connectToGroups : array<string, array<int, string>>
    - $cctvSpec        : array spec CCTV perangkat yang sedang diedit
--}}

@php
    // Nilai diambil dari model (bukan $cctvSpec) karena partial ini di-include
    // di bagian atas form, sementara $cctvSpec baru disiapkan di bagian bawah.
    $nilaiConnectTo = (string) old('spec_cctv_connect_to', $itAsset->spec['connect_to'] ?? '');
    $semuaPilihan   = collect($connectToGroups ?? [])->flatten()->all();
@endphp

<div>
    <label class="block text-xs font-medium text-slate-500 mb-1.5">Connect To (Switch / Access Point)</label>
    <select name="spec_cctv_connect_to"
            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="">- Belum ditentukan -</option>
        @foreach (($connectToGroups ?? []) as $labelTipe => $daftarTag)
            <optgroup label="{{ $labelTipe }}">
                @foreach ($daftarTag as $tag)
                    <option value="{{ $tag }}" @selected($nilaiConnectTo === $tag)>{{ $tag }}</option>
                @endforeach
            </optgroup>
        @endforeach

        {{-- Nilai lama hasil import yang belum terdaftar sebagai perangkat tetap
             ditampilkan agar tidak hilang saat form disimpan. --}}
        @if ($nilaiConnectTo !== '' && !in_array($nilaiConnectTo, $semuaPilihan, true))
            <option value="{{ $nilaiConnectTo }}" selected>{{ $nilaiConnectTo }} (belum terdaftar)</option>
        @endif
    </select>
    <p class="text-[11px] text-slate-400 mt-1">
        Pilih perangkat jaringan tempat kamera ini terhubung. Daftar diambil dari
        perangkat bertipe Switch Managed, Switch PoE Unmanaged, dan Access Point.
    </p>
</div>
