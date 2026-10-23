@extends('layouts.app')

@section('title', 'Import Spare Part')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('sparepart.index') }}" class="hover:text-slate-700">Sparepart</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Import</span>
@endsection

@section('content')
    <div class="space-y-6 max-w-4xl">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Import Berkas Spare Part</h1>
            <p class="text-sm text-slate-500 mt-1">
                Unggah berkas sumber yang berubah. Boleh sebagian: bagian yang tidak diunggah memakai
                nilai terakhir yang tersimpan, sehingga update mingguan cukup mengirim berkas yang baru.
                Setiap import memperbarui baris material berdasarkan kode material (bukan menambah baris baru).
            </p>
        </div>

        @if($errors->any())
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-sm text-rose-800">
                <p class="font-semibold mb-1">Berkas belum bisa diproses:</p>
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $galat)
                        <li>{{ $galat }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('sparepart.impor.proses') }}" enctype="multipart/form-data"
              class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="stok" class="block text-sm font-medium text-slate-700 mb-1">1. Laporan stok gudang</label>
                    <input type="file" name="stok" id="stok" accept=".xlsx,.xls"
                           class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                    <p class="text-xs text-slate-400 mt-1">Wajib memuat lembar G001, MASUK, dan KELUAR.</p>
                </div>
                <div>
                    <label for="pr" class="block text-sm font-medium text-slate-700 mb-1">2. EXPORT PR (SAP ME5A)</label>
                    <input type="file" name="pr" id="pr" accept=".xlsx,.xls"
                           class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                    <p class="text-xs text-slate-400 mt-1">Berisi blok "Purchase Requisition" per nomor PR.</p>
                </div>
                <div>
                    <label for="po" class="block text-sm font-medium text-slate-700 mb-1">3. EXPORT PO (SAP ME2M)</label>
                    <input type="file" name="po" id="po" accept=".xlsx,.xls"
                           class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                    <p class="text-xs text-slate-400 mt-1">Wajib memuat kolom sisa kirim ("Still to be delivered").</p>
                </div>
                <div>
                    <label for="mrp" class="block text-sm font-medium text-slate-700 mb-1">4. EXPORT MRP (SAP MD07/MD04)</label>
                    <input type="file" name="mrp" id="mrp" accept=".xlsx,.xls"
                           class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                    <p class="text-xs text-slate-400 mt-1">Opsional. Safety Stk menjadi stok minimum; Stock by SAP dipakai untuk indikator Stok Beda.</p>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label for="pantau" class="block text-sm font-medium text-slate-700 mb-1">
                        Daftar pantau (opsional)
                    </label>
                    <input type="file" name="pantau" id="pantau" accept=".xlsx,.xls,.csv"
                           class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700">
                    <p class="text-xs text-slate-400 mt-1">
                        Isi sheet MASTER DATA (kolom Kode Material, Deskripsi, Jumlah) atau CSV.
                        Saat ini dipantau <span class="font-medium text-slate-600">{{ number_format($jumlahPantau, 0, ',', '.') }}</span> kode.
                    </p>
                </div>
                <div>
                    <label for="mode_pantau" class="block text-sm font-medium text-slate-700 mb-1">Perlakuan daftar pantau</label>
                    <select name="mode_pantau" id="mode_pantau" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="gabung">Gabungkan (tambah / perbarui)</option>
                        <option value="ganti">Ganti seluruh daftar dengan isi berkas</option>
                    </select>
                </div>
            </div>

            <div>
                <label for="label" class="block text-sm font-medium text-slate-700 mb-1">Catatan import (opsional)</label>
                <input type="text" name="label" id="label" maxlength="120" placeholder="mis. Update minggu ke-2 Oktober 2026"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="px-5 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Proses import
                </button>
                <a href="{{ route('sparepart.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Kembali ke daftar</a>
                <a href="{{ route('sparepart.pantau') }}" class="text-sm text-slate-500 hover:text-slate-700">Kelola daftar pantau</a>
            </div>
        </form>

        {{-- Format kepala kolom yang dikunci: dipakai untuk menolak berkas yang salah --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="text-sm font-semibold text-slate-800 mb-1">Judul kolom yang dikunci</h2>
            <p class="text-xs text-slate-500 mb-4">
                Import hanya menerima berkas yang memuat judul kolom berikut. Bila salah satu hilang,
                import dibatalkan tanpa mengubah data dan pesannya menyebut kolom yang tidak ditemukan.
                Posisi kolom boleh bergeser.
            </p>

            <div class="space-y-4 text-xs text-slate-600">
                <div>
                    <p class="font-semibold text-slate-700">Laporan stok gudang</p>
                    <p>Lembar <span class="font-mono">G001</span>: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_STOK) }}</p>
                    <p>Lembar <span class="font-mono">MASUK</span>: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_MASUK) }}
                        (<span class="font-mono">UN</span> dipakai bila ada sebagai satuan)</p>
                    <p>Lembar <span class="font-mono">KELUAR</span>: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_KELUAR) }}</p>
                    <p class="text-slate-400 mt-1">
                        Sisa stok dihitung dari stok awal + barang masuk - barang keluar per kode material
                        (satu perhitungan per material, walau S.Loc-nya lebih dari satu).
                    </p>
                </div>
                <div>
                    <p class="font-semibold text-slate-700">EXPORT PR (SAP ME5A)</p>
                    <p>{{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_PR) }}</p>
                    <p class="text-slate-400 mt-1">Nomor PR dibaca dari baris judul "Purchase Requisition".</p>
                </div>
                <div>
                    <p class="font-semibold text-slate-700">EXPORT PO (SAP ME2M)</p>
                    <p>{{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_PO) }}
                        | {{ \App\Services\Sparepart\SparepartReader::JUDUL_PO_AWALAN }} (qty)</p>
                </div>
                <div>
                    <p class="font-semibold text-slate-700">EXPORT MRP (SAP MD07/MD04)</p>
                    <p>{{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_MRP) }}</p>
                    <p class="text-slate-400 mt-1">Kolom pendukung: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_MRP_OPSIONAL) }}.
                        Safety Stk dipakai sebagai stok minimum; material yang tidak ada di berkas MRP berstatus Belum MRP.</p>
                </div>
                <div>
                    <p class="font-semibold text-slate-700">Daftar pantau</p>
                    <p>Kolom wajib: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_PANTAU) }}</p>
                    <p>Kolom pendukung: {{ implode(' | ', \App\Services\Sparepart\SparepartReader::JUDUL_PANTAU_OPSIONAL) }}</p>
                </div>
            </div>
        </div>

        @if($terakhir !== null)
            <div class="bg-white rounded-xl border border-slate-200 p-4 text-sm text-slate-600">
                Import terakhir: <span class="font-medium text-slate-800">#{{ $terakhir->id }}</span>
                {{ $terakhir->selesai_at?->format('d/m/Y H:i') ?? $terakhir->created_at?->format('d/m/Y H:i') }}
                oleh {{ $terakhir->pengguna?->name ?? 'Sistem' }}
                ({{ $terakhir->label_status }}).
                <a href="{{ route('sparepart.riwayat', ['impor' => $terakhir->id]) }}" class="text-teal-700 hover:text-teal-800 font-medium">Lihat analisanya</a>.
            </div>
        @endif
    </div>
@endsection
