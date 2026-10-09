@extends('layouts.app')

@section('title', 'Daftar Pantau Sparepart')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('sparepart.index') }}" class="hover:text-slate-700">Sparepart</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Daftar Pantau</span>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Daftar Pantau Sparepart</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Daftar ini menentukan material mana yang dihitung pada halaman Integrasi PR/PO.
                    Isinya dapat diimpor dari sheet MASTER DATA (kolom Kode Material) atau diisi manual:
                    tambah, ubah, dan hapus baris langsung di tabel bawah.
                </p>
            </div>
            <a href="{{ route('sparepart.index') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                Kembali ke daftar
            </a>
        </div>

        @if(session('catatan_pantau'))
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs text-slate-600">
                <p class="font-semibold text-slate-700 mb-1">Catatan impor daftar pantau</p>
                <ul class="list-disc list-inside space-y-1">
                    @foreach((array) session('catatan_pantau') as $catatan)
                        <li>{{ $catatan }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Total dipantau</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($ringkasan['total'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Ditambahkan manual</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($ringkasan['manual'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Tanpa stok minimum</p>
                <p class="text-2xl font-bold text-amber-600 mt-1">{{ number_format($ringkasan['tanpa_stok_min'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Halaman</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $daftar->currentPage() }} / {{ $daftar->lastPage() }}</p>
            </div>
        </div>

        {{-- Impor daftar pantau --}}
        <form method="POST" action="{{ route('sparepart.pantau.impor') }}" enctype="multipart/form-data"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            @csrf
            <div class="md:col-span-2">
                <label for="berkas" class="block text-xs font-medium text-slate-600 mb-1">Impor daftar pantau (Excel/CSV)</label>
                <input type="file" name="berkas" id="berkas" accept=".xlsx,.xls,.csv"
                       class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                <p class="text-xs text-slate-400 mt-1">Berkas SparePart_Monitoring.xlsx dibaca dari sheet MASTER DATA.</p>
            </div>
            <div>
                <label for="mode" class="block text-xs font-medium text-slate-600 mb-1">Perlakuan</label>
                <select name="mode" id="mode" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="gabung">Gabungkan (tambah / perbarui)</option>
                    <option value="ganti">Ganti seluruh daftar</option>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Impor daftar
                </button>
            </div>
        </form>

        {{-- Tambah manual --}}
        <form method="POST" action="{{ route('sparepart.pantau.store') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            @csrf
            <div>
                <label for="kode_material" class="block text-xs font-medium text-slate-600 mb-1">Kode material</label>
                <input type="text" name="kode_material" id="kode_material" required
                       value="{{ old('kode_material') }}" placeholder="30001234"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                @error('kode_material')
                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div class="md:col-span-2">
                <label for="deskripsi" class="block text-xs font-medium text-slate-600 mb-1">Deskripsi</label>
                <input type="text" name="deskripsi" id="deskripsi" value="{{ old('deskripsi') }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="stok_min" class="block text-xs font-medium text-slate-600 mb-1">Stok minimum</label>
                <input type="number" step="0.001" min="0" name="stok_min" id="stok_min" value="{{ old('stok_min') }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="uom" class="block text-xs font-medium text-slate-600 mb-1">Satuan</label>
                <input type="text" name="uom" id="uom" maxlength="20" value="{{ old('uom') }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Tambah
                </button>
            </div>
        </form>

        {{-- Pencarian daftar pantau --}}
        <form method="GET" action="{{ route('sparepart.pantau') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 max-w-sm">
                <label for="q-pantau" class="block text-xs font-medium text-slate-600 mb-1">Cari kode / deskripsi</label>
                <input type="text" name="q" id="q-pantau" value="{{ $kunci }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                Cari
            </button>
            @if($kunci !== '')
                <a href="{{ route('sparepart.pantau') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
            @endif
        </form>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-800">Daftar material</p>
                <p class="text-xs text-slate-400">Perubahan disimpan dengan tombol Simpan pada tiap baris</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-3 text-left font-medium">Kode material</th>
                            <th class="px-3 py-3 text-left font-medium">Deskripsi</th>
                            <th class="px-3 py-3 text-right font-medium">Stok minimum</th>
                            <th class="px-3 py-3 text-left font-medium">Satuan</th>
                            <th class="px-3 py-3 text-left font-medium">Sumber</th>
                            <th class="px-3 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $satu)
                            <tr data-kode-material="{{ $satu->kode_material }}">
                                <td class="px-3 py-2">
                                    <input form="pantau-{{ $satu->id }}" type="text" name="kode_material"
                                           value="{{ $satu->kode_material }}" required
                                           class="w-32 rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                                </td>
                                <td class="px-3 py-2">
                                    <input form="pantau-{{ $satu->id }}" type="text" name="deskripsi"
                                           value="{{ $satu->deskripsi }}"
                                           class="w-full min-w-64 rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input form="pantau-{{ $satu->id }}" type="number" step="0.001" min="0" name="stok_min"
                                           value="{{ $satu->stok_min }}"
                                           class="w-24 rounded-lg border-slate-200 text-sm text-right focus:border-teal-500 focus:ring-teal-500">
                                </td>
                                <td class="px-3 py-2">
                                    <input form="pantau-{{ $satu->id }}" type="text" name="uom" maxlength="20"
                                           value="{{ $satu->uom }}"
                                           class="w-20 rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                                </td>
                                <td class="px-3 py-2 text-slate-500">{{ $satu->label_sumber }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <form id="pantau-{{ $satu->id }}" method="POST"
                                          action="{{ route('sparepart.pantau.update', $satu) }}">
                                        @csrf
                                        @method('PUT')
                                    </form>
                                    <button form="pantau-{{ $satu->id }}" type="submit"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background-color:#0E9E8E;">
                                        Simpan
                                    </button>
                                    <form method="POST" action="{{ route('sparepart.pantau.destroy', $satu) }}"
                                          class="inline" onsubmit="return confirm('Keluarkan material ini dari daftar pantau?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-rose-200 text-rose-600 hover:bg-rose-50">
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-slate-400">
                                    Daftar pantau masih kosong. Impor berkas MASTER DATA atau tambah satu kode di atas.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-slate-200">
                {{ $daftar->links() }}
            </div>
        </div>
    </div>
@endsection
