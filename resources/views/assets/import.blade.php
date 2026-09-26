@extends('layouts.app')

@section('title', 'Import Asset dari Excel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Import Excel</span>
@endsection

@section('content')
    <div x-data="importManager()" class="space-y-6">
        <!-- Upload Form -->
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Upload File Excel</h3>
            <p class="text-sm text-slate-500 mb-4">
                Format .xlsx, .xls, atau .csv (maks 10MB).
                <br>Kolom yang diproses: TechIdentNo., Description, Object Type, Equipment Type, Functional Loc.,
                Manufacturer, Model number, ConstructYear — <a href="{{ route('assets.import.template') }}" class="text-teal-600 hover:underline font-medium">Download Template Excel</a>
            </p>

            <div class="mb-4 rounded-lg border border-teal-100 bg-teal-50 p-3">
                <p class="text-xs text-teal-800 leading-relaxed">
                    <span class="font-semibold">Pencocokan memakai Tech Ident No.</span>
                    Tech Ident No adalah identitas unik satu aset (satu aset = satu Tech Ident No).
                    Baris yang Tech Ident No-nya sudah ada akan <span class="font-semibold">diperbarui</span>,
                    yang belum ada akan <span class="font-semibold">ditambahkan sebagai data baru</span>.
                    <br>
                    <span class="font-semibold">Equipment No tidak perlu diisi</span> dan tidak dipakai untuk
                    pencocokan: nomor itu id internal yang dibuat sistem mengikuti seri milik PT dari lokasi,
                    sehingga nomor aset lama tidak pernah berubah. Kolom Description, Tech Ident No,
                    Equipment Type, Functional Loc, Company/Department/Area/Sub Area, Manufacturer, Model Number,
                    Construct Year, dan Status ikut diperbarui.
                    <br>
                    <span class="font-semibold">Perangkat IT tidak ikut diproses.</span>
                    Baris cermin perangkat IT di tabel asset (data_source <span class="font-mono text-xs">it_sync</span>)
                    dilewati dan datanya dikelola di halaman IT Assets; data itu juga tidak ikut diexport
                    dari halaman Asset Management.
                </p>
            </div>

            <form @submit.prevent="uploadFile" class="space-y-4">
                <div class="flex items-center gap-4">
                    <input type="file" x-ref="fileInput" accept=".xlsx,.xls,.csv"
                           class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4
                                  file:rounded-lg file:border-0 file:text-sm file:font-medium
                                  file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                </div>
                <div class="flex gap-3">
                    <button type="submit" x-bind:disabled="loading"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                        <span x-show="!loading">Analisa File</span>
                        <span x-show="loading" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Menganalisa...
                        </span>
                    </button>
                    <a href="{{ route('assets.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>

        <!-- Error -->
        <template x-if="error">
            <div class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                <div>
                    <p class="font-medium">Gagal Membaca File</p>
                    <p class="mt-0.5" x-text="error"></p>
                </div>
            </div>
        </template>

        <!-- Preview -->
        <template x-if="analysis">
            <div class="space-y-6">
                <!-- Summary -->
                <div class="grid grid-cols-2 md:grid-cols-7 gap-4">
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-green-600" x-text="analysis.clean.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Data Baru</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-amber-600"
                           x-text="analysis.duplicate.filter(r => r.perubahan.length > 0).length"></p>
                        <p class="text-xs text-slate-500 mt-1">Data Berubah</p>
                        <p class="text-[11px] text-slate-400 mt-0.5"
                           x-text="analysis.duplicate.length + ' cocok data lama'"></p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-red-600" x-text="analysis.no_equip.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Tanpa Tech Ident No</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-orange-600" x-text="analysis.bad_funcloc.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Tanpa Functional Loc.</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-rose-600" x-text="analysis.ambigu.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Perlu Diperiksa</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-sky-600" x-text="analysis.it_sync.length"></p>
                        <p class="text-xs text-slate-500 mt-1">Perangkat IT (dilewati)</p>
                    </div>
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-slate-600" x-text="analysis.total_rows"></p>
                        <p class="text-xs text-slate-500 mt-1">Total Baris</p>
                    </div>
                </div>

                <!-- Peringatan nilai Tech Ident No yang terpotong Excel/Google Sheets -->
                <template x-if="analysis.duplicate.filter(r => r.tech_ident_terpotong).length > 0">
                    <div class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-amber-50 border-amber-200 text-amber-900">
                        <div>
                            <p class="font-medium">Peringatan: sebagian nilai Tech Ident No di file sudah diubah Excel/Google Sheets</p>
                            <p class="mt-0.5">
                                <span class="font-semibold" x-text="analysis.duplicate.filter(r => r.tech_ident_terpotong).length"></span>
                                baris berisi Tech Ident No berbentuk angka (mis. <span class="font-mono text-xs">61530</span>)
                                yang di database aslinya berupa teks berkode E (mis. <span class="font-mono text-xs">6153E1</span>).
                                Baris itu tetap dicocokkan ke aset yang benar memakai nilai angkanya, dan kolom Tech Ident No
                                di database TIDAK diubah.
                            </p>
                            <p class="mt-1">
                                Supaya tidak terulang: di Google Sheets/Excel format kolom TechIdentNo. sebagai
                                <span class="font-semibold">Teks biasa (Plain text)</span> SEBELUM menempel/mengetik, lalu simpan sebagai
                                .xlsx (jangan CSV, karena CSV tidak menyimpan tipe sel).
                            </p>
                        </div>
                    </div>
                </template>

                <!-- Data Baru -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="font-medium text-slate-900">Data Baru</h3>
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded"
                              x-text="analysis.clean.length + ' baris'"></span>
                    </div>
                    <p class="text-sm text-slate-500 mb-4">
                        Tech Ident No berikut belum ada di database, jadi akan ditambahkan sebagai aset baru
                        dengan nomor equipment otomatis.
                    </p>
                    <div class="max-h-72 overflow-y-auto border border-slate-100 rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">Baris</th>
                                    <th class="px-3 py-2 text-left font-medium">Tech Ident No</th>
                                    <th class="px-3 py-2 text-left font-medium">Equipment Type</th>
                                    <th class="px-3 py-2 text-left font-medium">Functional Loc</th>
                                    <th class="px-3 py-2 text-left font-medium">Description</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <template x-for="row in analysis.clean" :key="row.row">
                                    <tr>
                                        <td class="px-3 py-2 text-xs text-slate-400" x-text="row.row"></td>
                                        <td class="px-3 py-2 font-mono text-xs text-slate-700" x-text="row.tech_ident_no"></td>
                                        <td class="px-3 py-2 text-xs text-slate-600" x-text="row.equipment_type"></td>
                                        <td class="px-3 py-2 font-mono text-xs text-slate-600" x-text="row.functional_loc"></td>
                                        <td class="px-3 py-2 text-slate-600" x-text="row.description"></td>
                                    </tr>
                                </template>
                                <template x-if="analysis.clean.length === 0">
                                    <tr>
                                        <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-400">
                                            Tidak ada data baru pada file ini.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>


                <!-- Data yang Akan Diperbarui (hanya baris dengan perubahan) -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="font-medium text-slate-900">Data yang Akan Diperbarui</h3>
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 rounded"
                              x-text="analysis.duplicate.filter(r => r.perubahan.length > 0).length + ' baris'"></span>
                    </div>
                    <p class="text-sm text-slate-500 mb-4">
                        Tech Ident No berikut sudah ada di database DAN ada nilai yang berbeda dengan isi file.
                        Nomor equipment lama tidak diubah; hanya field yang tercantum di bawah ini yang berubah.
                    </p>
                    <div class="max-h-96 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                        <template x-for="row in analysis.duplicate.filter(r => r.perubahan.length > 0).slice(0, 200)" :key="row.row">
                            <div class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-xs text-slate-400" x-text="'Baris ' + row.row"></span>
                                    <span class="font-mono text-xs text-slate-700"
                                          x-text="row.tech_ident_terpotong ? row.tech_ident_terpotong.nilai_database : row.tech_ident_no"></span>
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded font-mono"
                                          x-text="'EQ: ' + (row.equipment_no_lama || '-')"></span>
                                    <span x-show="row.tech_ident_terpotong"
                                          class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 rounded">
                                        <span>nilai di file</span>
                                        <span class="font-mono" x-text="row.tech_ident_terpotong?.nilai_file"></span>
                                        <span>dicocokkan dari nilai terpotong</span>
                                    </span>
                                </div>
                                <div class="mt-1.5 space-y-0.5">
                                    <template x-for="(ubah, i) in row.perubahan" :key="i">
                                        <p class="text-xs text-slate-600">
                                            <span class="font-medium text-slate-700" x-text="ubah.field"></span>:
                                            <span class="text-rose-600 line-through" x-text="ubah.lama || '(kosong)'"></span>
                                            <span class="text-slate-400">&rarr;</span>
                                            <span class="text-green-700" x-text="ubah.baru || '(kosong)'"></span>
                                        </p>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="analysis.duplicate.filter(r => r.perubahan.length > 0).length === 0">
                            <div class="px-4 py-6 text-center text-sm text-slate-400">
                                Tidak ada data yang berubah pada file ini.
                            </div>
                        </template>
                        <div class="px-4 py-2 text-xs text-slate-400 text-center"
                             x-show="analysis.duplicate.filter(r => r.perubahan.length > 0).length > 200"
                             x-text="'Menampilkan 200 baris pertama dari ' + analysis.duplicate.filter(r => r.perubahan.length > 0).length + ' baris yang berubah.'"></div>
                    </div>
                </div>

                <!-- Cocok data lama tanpa perubahan -->
                <template x-if="analysis.duplicate.filter(r => r.perubahan.length === 0).length > 0">
                    <div class="bg-white rounded-xl border border-slate-200 p-6">
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="font-medium text-slate-900">Cocok Data Lama Tanpa Perubahan</h3>
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded"
                                  x-text="analysis.duplicate.filter(r => r.perubahan.length === 0).length + ' baris'"></span>
                        </div>
                        <p class="text-sm text-slate-500 mb-4">
                            Tech Ident No berikut sudah ada di database dan isi file-nya SAMA dengan data lama,
                            jadi tidak ada nilai yang berubah. Baris ini tetap diproses tanpa mengubah apa pun.
                        </p>
                        <div class="max-h-72 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="row in analysis.duplicate.filter(r => r.perubahan.length === 0).slice(0, 50)" :key="row.row">
                                <div class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                                    <span class="text-xs text-slate-400 shrink-0" x-text="'Baris ' + row.row"></span>
                                    <span class="font-mono text-xs text-slate-600 shrink-0"
                                          x-text="row.tech_ident_terpotong ? row.tech_ident_terpotong.nilai_database : row.tech_ident_no"></span>
                                    <span class="font-mono text-xs text-slate-400 shrink-0" x-text="'EQ: ' + (row.equipment_no_lama || '-')"></span>
                                    <span class="text-slate-500 truncate" x-text="row.description"></span>
                                </div>
                            </template>
                            <div class="px-4 py-2 text-xs text-slate-400 text-center"
                                 x-show="analysis.duplicate.filter(r => r.perubahan.length === 0).length > 50"
                                 x-text="'Menampilkan 50 baris pertama dari ' + analysis.duplicate.filter(r => r.perubahan.length === 0).length + ' baris yang cocok tanpa perubahan.'"></div>
                        </div>
                    </div>
                </template>

                <!-- Perlu Diperiksa: Tech Ident No tidak unik -->
                <template x-if="analysis.ambigu.length > 0">
                    <div class="bg-white rounded-xl border border-slate-200 p-6">
                        <h3 class="font-medium text-slate-900 mb-1">Perlu Diperiksa</h3>
                        <p class="text-sm text-slate-500 mb-4">
                            Tech Ident No harus unik untuk satu aset. Baris berikut dilewati (tidak ditambahkan
                            dan tidak diperbarui) sampai Tech Ident No-nya diperbaiki.
                        </p>
                        <div class="max-h-56 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="row in analysis.ambigu" :key="row.row">
                                <div class="px-4 py-2 text-sm">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="text-xs text-slate-400 shrink-0" x-text="'Baris ' + row.row"></span>
                                        <span class="font-mono text-xs text-slate-700 shrink-0" x-text="row.tech_ident_no"></span>
                                        <span class="text-slate-600 truncate" x-text="row.description"></span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-rose-600" x-text="row.alasan"></p>
                                    <div class="mt-1 space-y-0.5">
                                        <template x-for="k in (row.kandidat || [])" :key="k.id">
                                            <p class="text-xs text-slate-500">
                                                <a :href="'/assets/' + k.id" target="_blank" class="text-teal-600 hover:underline font-mono"
                                                   x-text="k.equipment_no || '(tanpa nomor)'"></a>
                                                <span class="mx-1">&middot;</span>
                                                <span class="font-mono" x-text="k.tech_ident_no"></span>
                                                <span class="mx-1">&middot;</span>
                                                <span x-text="k.description"></span>
                                                <span class="mx-1">&middot;</span>
                                                <span class="font-mono" x-text="k.functional_loc"></span>
                                                <span class="mx-1">&middot;</span>
                                                <span x-text="k.data_source"></span>
                                            </p>
                                        </template>
                                        <p class="text-xs">
                                            <a :href="'/assets?search=' + encodeURIComponent(row.tech_ident_no)" target="_blank"
                                               class="text-teal-600 hover:underline">Buka di Asset Management</a>
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>


                <!-- Perangkat IT: tidak diproses import asset -->
                <template x-if="analysis.it_sync.length > 0">
                    <div class="bg-white rounded-xl border border-slate-200 p-6">
                        <h3 class="font-medium text-slate-900 mb-1">Perangkat IT (dilewati)</h3>
                        <p class="text-sm text-slate-500 mb-4">
                            Baris berikut adalah baris cermin perangkat IT di tabel asset
                            (data_source <span class="font-mono text-xs">it_sync</span>). Datanya dikelola di halaman
                            IT Assets lewat import perangkat IT, jadi import asset tidak mengubahnya.
                        </p>
                        <div class="max-h-56 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="row in analysis.it_sync" :key="row.row">
                                <div class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                                    <span class="text-xs text-slate-400 shrink-0" x-text="'Baris ' + row.row"></span>
                                    <span class="font-mono text-xs text-slate-700 shrink-0" x-text="row.tech_ident_no"></span>
                                    <span class="font-mono text-xs text-slate-500 shrink-0" x-text="'EQ: ' + (row.equipment_no_lama || '-')"></span>
                                    <span class="text-slate-600 truncate" x-text="row.description"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- FuncLoc Preview -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-medium text-slate-900">Functional Location Terdeteksi</h3>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium">
                                <span x-text="analysis.funcloc_preview.new_count"></span> FuncLoc Baru
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">
                                <span x-text="analysis.funcloc_preview.existing_count"></span> Sudah Ada
                            </span>
                        </div>
                    </div>

                    <div class="max-h-72 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                        <template x-for="node in analysis.funcloc_preview.nodes" :key="node.code">
                            <div class="flex items-center gap-3 px-4 py-2 text-sm" :style="{ paddingLeft: (16 + node.level * 20) + 'px' }">
                                <span class="font-mono text-xs text-slate-700 flex-1 truncate" x-text="node.code"></span>
                                <span class="text-xs text-slate-400 shrink-0" x-text="'L' + node.level"></span>
                                <span x-show="!node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded shrink-0">FuncLoc Baru</span>
                                <span x-show="node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded shrink-0">Sudah Ada</span>
                            </div>
                        </template>
                        <template x-if="analysis.funcloc_preview.nodes.length === 0">
                            <div class="px-4 py-6 text-center text-sm text-slate-400">Tidak ada Functional Location terdeteksi di file ini.</div>
                        </template>
                    </div>
                </div>

                <!-- Baris Tanpa Tech Ident No -->
                <template x-if="analysis.no_equip.length > 0">
                    <div class="bg-white rounded-xl border border-slate-200 p-6">
                        <h3 class="font-medium text-slate-900 mb-1">Baris Tanpa Tech Ident No</h3>
                        <p class="text-sm text-slate-500 mb-4">
                            Tanpa Tech Ident No, baris ini tidak bisa dicocokkan dengan aset mana pun.
                            Pilih aksinya di bagian bawah: dimasukkan sebagai aset baru berstatus Perlu Review
                            (lengkap dengan nomor equipment otomatis), atau dilewati.
                        </p>
                        <div class="max-h-56 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="row in analysis.no_equip" :key="row.row">
                                <div class="flex items-center gap-3 px-4 py-2 text-sm">
                                    <span class="text-xs text-slate-400 shrink-0" x-text="'Baris ' + row.row"></span>
                                    <span class="text-xs text-slate-500 shrink-0" x-text="row.equipment_type"></span>
                                    <span class="font-mono text-xs text-slate-600 shrink-0" x-text="row.functional_loc"></span>
                                    <span class="text-slate-600 truncate" x-text="row.description"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Baris Tanpa Functional Location -->
                <template x-if="analysis.bad_funcloc.length > 0">
                    <div class="bg-white rounded-xl border border-slate-200 p-6">
                        <h3 class="font-medium text-slate-900 mb-1">Baris Tanpa Functional Location</h3>
                        <p class="text-sm text-slate-500 mb-4">
                            Setiap asset wajib punya Functional Location, jadi baris berikut tidak bisa
                            diimport dan akan dilewati. Lengkapi lokasinya lalu import ulang, atau tambahkan
                            manual lewat form Tambah Asset.
                        </p>
                        <div class="max-h-56 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="row in analysis.bad_funcloc" :key="row.row">
                                <div class="flex items-center gap-3 px-4 py-2 text-sm">
                                    <span class="text-xs text-slate-400 shrink-0" x-text="'Baris ' + row.row"></span>
                                    <span class="font-mono text-xs text-slate-700 shrink-0" x-text="row.tech_ident_no || '(tanpa Tech Ident No)'"></span>
                                    <span class="text-slate-600 truncate" x-text="row.description"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>


                <!-- Actions -->
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Pilihan Aksi Import</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Aksi untuk Data yang Cocok (Tech Ident No sudah ada)</label>
                            <select x-model="duplicateAction" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="replace">Perbarui data lama dengan isi file</option>
                                <option value="skip">Abaikan (biarkan data lama)</option>
                                <option value="keep_flag">Tandai Review - ubah status jadi perlu review</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Aksi untuk Baris Tanpa Tech Ident No</label>
                            <select x-model="noEquipAction" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="skip">Abaikan baris tersebut</option>
                                <option value="flag">Masukkan sebagai aset baru (status Perlu Review)</option>
                                <option value="cancel">Batalkan seluruh import</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Aksi untuk Baris Tanpa Functional Loc.</label>
                            <select x-model="badFuncLocAction" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
                                <option value="skip">Abaikan baris tersebut</option>
                                <option value="cancel">Batalkan seluruh import</option>
                            </select>
                            <p class="text-xs text-slate-400 mt-1">
                                Setiap asset wajib punya Functional Location, jadi baris ini tidak bisa
                                langsung dimasukkan tanpa lokasi.
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Baris Perlu Diperiksa</label>
                            <p class="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-sm text-slate-500">
                                Selalu dilewati (tidak diimport) sampai Tech Ident No-nya unik.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-3">
                        <button @click="executeImport"
                                x-bind:disabled="executing"
                                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                            <span x-show="!executing">Jalankan Import</span>
                            <span x-show="executing" class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Mengimport...
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Result -->
                <template x-if="result">
                    <div>
                        <div x-show="result.status === 'success'"
                             class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-green-50 border-green-200 text-green-800">
                            <div>
                                <p class="font-medium">Hasil Import</p>
                                <p class="mt-0.5" x-text="result.message"></p>
                                <p class="mt-0.5 text-xs"
                                   x-show="typeof result.inserted_count === 'number'"
                                   x-text="'Data baru: ' + result.inserted_count + ', diperbarui: ' + result.updated_count + ', dilewati: ' + result.error_count"></p>
                            </div>
                        </div>
                        <div x-show="result.status !== 'success'"
                             class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                            <div>
                                <p class="font-medium">Hasil Import</p>
                                <p class="mt-0.5" x-text="result.message"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
@endsection


@push('scripts')
<script>
function importManager() {
    return {
        analysis: null,
        error: null,
        loading: false,
        executing: false,
        result: null,
        duplicateAction: 'replace',
        noEquipAction: 'skip',
        badFuncLocAction: 'skip',

        /**
         * Kirim file ke endpoint analisa, lalu simpan hasilnya untuk ditampilkan
         * sebagai daftar Data Baru dan Data yang Akan Diperbarui.
         */
        uploadFile() {
            const fileInput = this.$refs.fileInput;
            if (!fileInput.files || !fileInput.files[0]) {
                this.error = 'Pilih file terlebih dahulu.';
                return;
            }

            this.loading = true;
            this.error = null;
            this.analysis = null;
            this.result = null;

            const formData = new FormData();
            formData.append('file', fileInput.files[0]);

            fetch('{{ route('assets.import.preview') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: formData,
            })
            .then(res => {
                if (!res.ok) {
                    return res.json().then(errData => {
                        throw new Error(errData.message || 'HTTP ' + res.status);
                    }).catch(e => {
                        if (e instanceof SyntaxError) {
                            throw new Error('Server error ' + res.status + '. Cek log untuk detail.');
                        }
                        throw e;
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    this.analysis = data.analysis;
                } else {
                    this.error = data.message || 'Gagal membaca file.';
                }
            })
            .catch(err => {
                this.error = err.message || 'Gagal mengupload file. Cek console (F12) untuk detail.';
            })
            .finally(() => {
                this.loading = false;
            });
        },

        /**
         * Jalankan import sesuai pilihan aksi. Hasil analisa SENGAJA tidak
         * dikosongkan agar daftar Data Baru dan Data yang Diperbarui tetap
         * terlihat sebagai bukti apa yang baru saja diproses.
         */
        executeImport() {
            this.executing = true;
            this.result = null;

            fetch('{{ route('assets.import.execute') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    analysis: this.analysis,
                    duplicate_action: this.duplicateAction,
                    no_equip_action: this.noEquipAction,
                    bad_funcloc_action: this.badFuncLocAction,
                    filename: this.$refs.fileInput?.files[0]?.name || 'unknown.xlsx',
                }),
            })
            .then(res => res.json())
            .then(data => {
                this.result = data;
            })
            .catch(() => {
                this.result = { status: 'error', message: 'Terjadi kesalahan saat import.' };
            })
            .finally(() => {
                this.executing = false;
            });
        },
    };
}
</script>
@endpush

