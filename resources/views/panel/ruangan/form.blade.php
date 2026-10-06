@extends('layouts.app')

@section('title', $ruangan->exists ? 'Pengaturan Ruangan Panel' : 'Tambah Ruangan Panel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('panel.index') }}" class="hover:text-slate-700">Monitoring Ruang Panel</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $ruangan->exists ? 'Pengaturan' : 'Tambah' }}</span>
@endsection

@section('content')
    @php
        // Nilai terpilih dipakai untuk menandai pilihan radio/kolom; old()
        // menang supaya isian tidak hilang saat validasi gagal.
        $protokolTerpilih = old('protokol', $ruangan->protokol ?: 'otomatis');
        $portTcpTerpilih = old('port_tcp', $ruangan->port_tcp ?: $portTcpBawaan);
    @endphp

    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $ruangan->exists ? route('panel.ruangan.update', $ruangan) : route('panel.ruangan.store') }}"
          class="max-w-4xl space-y-6">
        @csrf
        @if ($ruangan->exists)
            @method('PUT')
        @endif

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Identitas Ruangan</h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Nama Ruangan <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="nama_ruangan" maxlength="100" required
                           value="{{ old('nama_ruangan', $ruangan->nama_ruangan) }}"
                           placeholder="Mis. Ruang Panel EPE 1"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">PT Location</label>
                    <input type="text" name="pt_location" maxlength="100"
                           value="{{ old('pt_location', $ruangan->pt_location) }}"
                           placeholder="Mis. EPE"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Plant / Area</label>
                    <input type="text" name="plant" maxlength="50"
                           value="{{ old('plant', $ruangan->plant) }}"
                           placeholder="Mis. 6010"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Keterangan</label>
                    <input type="text" name="keterangan" maxlength="255"
                           value="{{ old('keterangan', $ruangan->keterangan) }}"
                           placeholder="Catatan singkat tentang ruangan ini"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>

                <div class="md:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="aktif" value="1"
                               @checked(old('aktif', $ruangan->exists ? $ruangan->aktif : true))
                               class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Tarik data ruangan ini secara berkala (aktif)
                    </label>
                    <p class="text-[11px] text-slate-400 mt-1">
                        Ruangan nonaktif tidak ikut ditarik, dan baris asetnya diberi status Nonaktif.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Cara Baca Perangkat (Sumber Data)</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Pilih dari mana aplikasi mengambil angka suhu dan kelembapan. Ada dua jenis sumber
                    data: dashboard buatan sendiri pada Arduino (sketsa) dan firmware proyek yang
                    menyediakan JSON API.
                </p>
            </div>
            <div class="p-6 space-y-4">
                <label class="flex items-start gap-3 border rounded-xl p-4 cursor-pointer {{ $protokolTerpilih === 'otomatis' ? 'border-teal-400 bg-teal-50' : 'border-slate-200' }}">
                    <input type="radio" name="protokol" value="otomatis" @checked($protokolTerpilih === 'otomatis')
                           class="mt-1 text-teal-600 focus:ring-teal-500">
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">Otomatis (deteksi sendiri)</span>
                        <span class="block text-xs text-slate-500 mt-1">
                            Aplikasi mencoba berurutan: JSON API, JSON TCP port, lalu dashboard HTML.
                            Pilihan paling aman saat jenis perangkat belum dipastikan.
                        </span>
                    </span>
                </label>

                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-700">
                        Dashboard Arduino (sketsa sendiri)
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Sketsa yang menyajikan dashboard HTML dan satu baris JSON pada port TCP. Sketsa
                        tidak menyimpan buffer, jadi aplikasi menyimpan nilai terkini saja.
                    </p>

                    <div class="mt-3 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="radio" name="protokol" value="tcp-json" @checked($protokolTerpilih === 'tcp-json')
                                   class="mt-1 text-teal-600 focus:ring-teal-500">
                            <span>
                                <span class="block text-sm text-slate-900">
                                    JSON dari port TCP (bawaan {{ $portTcpBawaan }})
                                </span>
                                <span class="block text-xs text-slate-500">
                                    Perangkat membalas setelah aplikasi mengirim satu byte.
                                </span>
                            </span>
                        </label>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">
                                Port JSON TCP perangkat ini
                            </label>
                            <input type="number" name="port_tcp" min="1" max="65535" value="{{ $portTcpTerpilih }}"
                                   class="w-40 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <p class="text-[11px] text-slate-400 mt-1">
                                Kosongkan bila ingin memakai bawaan {{ $portTcpBawaan }}
                                (config panel.sketch.port_tcp).
                            </p>
                        </div>

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="radio" name="protokol" value="html" @checked($protokolTerpilih === 'html')
                                   class="mt-1 text-teal-600 focus:ring-teal-500">
                            <span>
                                <span class="block text-sm text-slate-900">
                                    Angka dibaca dari dashboard HTML (port HTTP)
                                </span>
                                <span class="block text-xs text-slate-500">
                                    Dipakai bila port TCP tidak aktif, mis. halaman memuat "Suhu: 24.90".
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-700">
                        Firmware proyek (JSON API)
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Firmware ethernet_dht11 menyediakan /api/status, /api/buffer, dan pengaturan
                        ambang. Keunggulannya ada buffer pembacaan, sehingga data yang tertinggal bisa
                        ditarik menyusul setelah komputer sempat mati.
                    </p>
                    <label class="mt-3 flex items-start gap-3 cursor-pointer">
                        <input type="radio" name="protokol" value="api-json" @checked($protokolTerpilih === 'api-json')
                               class="mt-1 text-teal-600 focus:ring-teal-500">
                        <span>
                            <span class="block text-sm text-slate-900">
                                JSON API firmware (HTTP /api/status)
                            </span>
                            <span class="block text-xs text-slate-500">
                                Port HTTP pada kartu alamat perangkat dipakai untuk memanggil endpoint itu.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Alamat Perangkat</h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Alamat IP <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="ip_address" required maxlength="45"
                           value="{{ old('ip_address', $ruangan->ip_address) }}"
                           placeholder="192.168.41.240"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Port HTTP <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="port" required min="1" max="65535"
                           value="{{ old('port', $ruangan->port ?: 80) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Dashboard sketsa memakai port 80; endpoint JSON API firmware juga 80.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Alamat MAC</label>
                    <input type="text" name="mac_address" maxlength="17"
                           value="{{ old('mac_address', $ruangan->mac_address) }}"
                           placeholder="02:00:5A:41:00:01"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Ambang Suhu Ruangan</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Dipakai untuk menandai kondisi suhu tinggi atau rendah pada dashboard panel.
                </p>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Ambang atas (C) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" step="0.01" name="ambang_suhu_atas" required
                           value="{{ old('ambang_suhu_atas', $ruangan->ambang_suhu_atas ?? $ambangBawaan['atas']) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Ambang bawah (C) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" step="0.01" name="ambang_suhu_bawah" required
                           value="{{ old('ambang_suhu_bawah', $ruangan->ambang_suhu_bawah ?? $ambangBawaan['bawah']) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Lokasi dan Baris Aset</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Dengan mengisi lokasi dan kode alat, ruangan ini otomatis dibuatkan satu baris aset
                    (nomor equipment 8 digit mengikuti seri nomor PT lokasi itu) sehingga bisa dipilih
                    sebagai equipment pada laporan web maupun wizard Telegram.
                </p>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">
                        Lokasi (Functional Location)
                    </label>
                    <select name="funcloc_id"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="">- Belum ditentukan -</option>
                        @foreach (($funclocGroups ?? []) as $pt => $items)
                            <optgroup label="{{ $pt }}">
                                @foreach ($items as $item)
                                    <option value="{{ $item['id'] }}"
                                        @selected((int) old('funcloc_id', $ruangan->funcloc_id) === (int) $item['id'])>
                                        {{ $item['label'] }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">
                        Hierarki sama dengan aset industri: PT - Departemen - Area - Section.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Kode Alat</label>
                    <input type="text" name="kode_alat" maxlength="100"
                           value="{{ old('kode_alat', $ruangan->kode_alat) }}"
                           placeholder="Mis. PANEL-EPE-01"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Dipakai sebagai Tech Ident No pada daftar equipment laporan.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Jenis Alat</label>
                    <input type="text" name="equipment_type" maxlength="100"
                           value="{{ old('equipment_type', $ruangan->equipment_type) }}"
                           placeholder="Panel Monitoring"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Kosongkan untuk memakai "Panel Monitoring".
                    </p>
                </div>

                @if ($asetCermin)
                    <div class="md:col-span-2 rounded-lg border px-4 py-3 text-sm"
                         style="background-color:#e6f6f3; border-color:#bfe7e1; color:#0b7f72;">
                        Baris aset cermin:
                        <span class="font-semibold">{{ $asetCermin->tech_ident_no }}</span>
                        @if ($asetCermin->equipment_no)
                            (nomor {{ $asetCermin->equipment_no }})
                        @endif
                        &middot;
                        <a href="{{ route('assets.show', $asetCermin) }}" class="underline">
                            buka di Asset Management
                        </a>
                    </div>
                @elseif ($alasanLewat)
                    <div class="md:col-span-2 rounded-lg border px-4 py-3 text-sm"
                         style="background-color:#fef6e7; border-color:#f4dfb3; color:#8a5a10;">
                        Baris aset belum dibuat: {{ $alasanLewat }}
                    </div>
                @endif
            </div>
        </div>

        @include('panel.ruangan.partials._penanggung-jawab')

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ $ruangan->exists ? route('panel.ruangan.show', $ruangan) : route('panel.index') }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                Batal
            </a>
            <button type="submit"
                    class="px-4 py-2 text-white text-sm font-medium rounded-lg"
                    style="background-color:#0E9E8E;">
                {{ $ruangan->exists ? 'Simpan Perubahan' : 'Simpan Ruangan' }}
            </button>
        </div>

    </form>
@endsection
