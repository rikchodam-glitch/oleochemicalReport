@extends('layouts.app')

@section('title', 'Sparepart — Buat PR & Kejar PO')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Sparepart</span>
@endsection

@php
    // Format angka tanpa nol desimal yang tidak perlu; nilai kosong jadi tanda hubung.
    $angkaRapi = function ($nilai): string {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        $teks = number_format((float) $nilai, 3, ',', '.');

        return rtrim(rtrim($teks, '0'), ',') ?: '0';
    };

    $warnaAksi = [
        \App\Models\SpMaterial::AKSI_KEJAR   => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        \App\Models\SpMaterial::AKSI_BUAT_PO => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        \App\Models\SpMaterial::AKSI_CEK     => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
        \App\Models\SpMaterial::AKSI_BUAT_PR => 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
    ];

    $warnaStatusPr = function (?string $status): string {
        return match ($status) {
            'Terbuka (belum PO)' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
            'Sudah jadi PO'      => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
            'Dibatalkan'         => 'bg-slate-100 text-slate-500 ring-1 ring-slate-200',
            default              => 'bg-slate-100 text-slate-500 ring-1 ring-slate-200',
        };
    };

    $labelStokBeda = function ($nilai): string {
        return match ($nilai) {
            'Sama' => 'Stok Sama',
            'Beda' => 'Stok Beda',
            default => 'Belum bisa dibandingkan',
        };
    };

    // Penjelasan singkat yang muncul saat kursor diarahkan ke elemen.
    $tip = [
        'kode'          => 'Kode material dan deskripsi. Klik judul kolom untuk menyaring kode/deskripsi.',
        'stok_min'      => 'Stok minimum dari Safety Stk EXPORT MRP. Material yang tidak ada di MRP berstatus Belum MRP.',
        'stok_gudang'   => 'Sisa stok gudang G001 (stok awal + masuk - keluar). Tanda hubung berarti material tidak ada di laporan G001.',
        'status'        => 'REORDER bila stok gudang <= stok minimum MRP; Aman bila di atasnya; Belum MRP bila material tidak ada di berkas MRP.',
        'aksi'          => 'Tindakan yang disarankan: Kejar Delivery, Kejar PO, Cek/Buat PR, atau Buat PR.',
        'pr'            => 'Ringkasan PR dari EXPORT PR ME5A. Klik nomor PR untuk membuka History PR di popup.',
        'po'            => 'Ringkasan PO dari EXPORT PO ME2M. Klik nomor PO untuk membuka rincian PO di popup.',
        'purch'         => 'Purchaser dan vendor PO terpilih. Riwayat PO sebelumnya ada di popup bagian PO.',
        'mrp'           => 'Data SAP MD07/MD04: stok SAP dan indikator Stok Beda terhadap sisa G001.',
        'diperbarui'    => 'Waktu data material terakhir diperbarui oleh import.',
        'detail'        => 'Buka popup detail PR, PO, Stok G001, dan MRP untuk material ini.',
        'kartu_total'   => 'Jumlah seluruh material pada daftar pantau.',
        'kartu_reorder' => 'Material dengan stok gudang <= stok minimum (Safety Stk MRP).',
        'kartu_aman'    => 'Material dengan stok gudang di atas stok minimum MRP.',
        'kartu_belum'   => 'Material yang tidak ada di berkas EXPORT MRP sehingga belum punya stok minimum.',
        'punya_pr'      => 'Material yang punya minimal satu baris PR pada EXPORT PR ME5A.',
        'punya_po'      => 'Material yang punya minimal satu baris PO pada EXPORT PO ME2M.',
        'punya_mrp'     => 'Material yang punya data MRP (Safety Stk terbaca).',
        'tombol_pantau' => 'Kelola daftar material yang dipantau pada halaman ini.',
        'tombol_riwayat'=> 'Lihat riwayat import dan analisa berkas sumber.',
        'tombol_import' => 'Unggah laporan stok gudang G001, EXPORT PR ME5A, EXPORT PO ME2M, dan EXPORT MRP.',
        'tombol_export' => 'Unduh tabel keadaan sesuai saringan yang sedang dipakai.',
        'pr_terpilih'   => 'PR yang dianggap paling relevan: PR yang sudah menjadi PO terpilih, lalu PR terbuka terbaru.',
        'pr_terbuka'    => 'PR dengan Processing status N dan tidak dibatalkan.',
        'po_terbuka'    => 'PO dengan sisa kirim > 0 dan tidak dihapus.',
        'stok_beda'     => 'Membandingkan Sisa Stok G001 dengan Stock by SAP (Plnt stock MRP).',
        'safety'        => 'Safety Stk MRP; nilai inilah yang dipakai sebagai stok minimum.',
        'reorder_pt'    => 'Reorder Pt dari MRP, ditampilkan sebagai pembanding (tidak dipakai sebagai stok minimum).',
        'sisa_kirim'    => 'Sisa kuantitas PO yang belum dikirim (Still to be delivered).',
    ];

    // URL saringan tanpa beberapa kunci; dipakai tombol "Bersihkan" di header.
    $tautanFilter = function (array $kecuali = [], array $tambah = []) use ($saringan): string {
        $data = collect($saringan)
            ->except($kecuali)
            ->filter(static fn ($nilai): bool => $nilai !== '')
            ->all();

        return route('sparepart.index', array_merge($data, $tambah));
    };

    $adaMrp = (int) ($ringkasan['punya_mrp'] ?? 0) > 0;
@endphp

@section('content')
    <div class="space-y-6" x-data="{ buka: null, tab: 'pr' }">
        {{-- Kepala halaman --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Sparepart — Buat PR & Kejar PO</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Satu baris per kode material. Stok gudang dari Laporan Stok Gudang G001, stok minimum
                    dari Safety Stk EXPORT MRP, sedangkan PR/PO dari EXPORT PR ME5A dan EXPORT PO ME2M.
                    Sorot label untuk melihat penjelasan; klik judul kolom untuk menyaring; klik Detail
                    untuk membuka popup.
                </p>
                @if($terakhir !== null)
                    <p class="text-xs text-slate-400 mt-1">
                        Import terakhir: #{{ $terakhir->id }}
                        {{ $terakhir->selesai_at?->format('d/m/Y H:i') ?? $terakhir->created_at?->format('d/m/Y H:i') }}
                        oleh {{ $terakhir->pengguna?->name ?? 'Sistem' }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('sparepart.pantau') }}" title="{{ $tip['tombol_pantau'] }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Daftar Pantau ({{ number_format($ringkasan['total'], 0, ',', '.') }})
                </a>
                <a href="{{ route('sparepart.riwayat') }}" title="{{ $tip['tombol_riwayat'] }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Riwayat Import
                </a>
                <a href="{{ route('sparepart.impor') }}" title="{{ $tip['tombol_import'] }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium text-white"
                   style="background-color:#0E9E8E;">
                    Import Berkas
                </a>
                <a href="{{ route('sparepart.export', collect($saringan)->filter(static fn ($nilai): bool => $nilai !== '')->all()) }}"
                   title="{{ $tip['tombol_export'] }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Export Excel
                </a>
            </div>
        </div>

        @if($terakhir === null)
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                Belum ada import berkas. Buka
                <a href="{{ route('sparepart.impor') }}" class="font-semibold underline">Import Berkas</a>
                untuk mengunggah laporan stok gudang, EXPORT PR, EXPORT PO, dan EXPORT MRP.
            </div>
        @endif
        @include('sparepart.partials._peringatan-cakupan', ['impor' => $terakhir])

        {{-- Kartu ringkasan keadaan --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <a href="{{ route('sparepart.index') }}" title="{{ $tip['kartu_total'] }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === '' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Material dipantau</p>
                <p class="text-2xl font-bold text-slate-900 mt-1" data-kartu="total">{{ number_format($ringkasan['total'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'reorder']) }}" title="{{ $tip['kartu_reorder'] }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'reorder' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Perlu reorder (REORDER)</p>
                <p class="text-2xl font-bold text-rose-600 mt-1" data-kartu="reorder">{{ number_format($ringkasan['reorder'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'aman']) }}" title="{{ $tip['kartu_aman'] }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'aman' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Stok aman</p>
                <p class="text-2xl font-bold text-teal-700 mt-1" data-kartu="aman">{{ number_format($ringkasan['aman'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'belum']) }}" title="{{ $tip['kartu_belum'] }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'belum' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Belum MRP</p>
                <p class="text-2xl font-bold text-amber-600 mt-1" data-kartu="belum">{{ number_format($ringkasan['belum'], 0, ',', '.') }}</p>
            </a>
        </div>

        {{-- Keterangan lingkup kartu aksi --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <p class="text-sm font-semibold text-slate-700">
                Rincian tindakan {{ number_format($ringkasan['reorder'], 0, ',', '.') }} item REORDER (fokus tindakan)
            </p>
            <p class="text-xs text-slate-400">
                Sama seperti sheet "Daftar Aksi" dashboard lama; material yang stoknya masih aman tidak dihitung.
            </p>
        </div>

        {{-- Kartu tindak lanjut; penjelasan muncul saat disorot --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach($kartuAksi as $kartu)
                <a href="{{ route('sparepart.index', ['aksi' => $kartu['nilai'], 'status' => 'reorder']) }}"
                   title="{{ $kartu['keterangan'] }}"
                   class="bg-white rounded-xl border {{ $saringan['aksi'] === $kartu['nilai'] ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors"
                   data-kartu-aksi="{{ $kartu['label'] }}">
                    <p class="text-xs text-slate-500">{{ $kartu['label'] }}</p>
                    <p class="text-xl font-semibold text-slate-900 mt-1">{{ number_format($kartu['jumlah'], 0, ',', '.') }}</p>
                </a>
            @endforeach
        </div>

        {{-- Ringkasan kepemilikan data --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <a href="{{ route('sparepart.index', ['punya' => 'pr']) }}" title="{{ $tip['punya_pr'] }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'pr' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data PR</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_pr'], 0, ',', '.') }}</span>
            </a>
            <a href="{{ route('sparepart.index', ['punya' => 'po']) }}" title="{{ $tip['punya_po'] }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'po' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data PO</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_po'], 0, ',', '.') }}</span>
            </a>
            <a href="{{ route('sparepart.index', ['punya' => 'mrp']) }}" title="{{ $tip['punya_mrp'] }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'mrp' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data MRP</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_mrp'], 0, ',', '.') }}</span>
            </a>
        </div>

        {{-- Saringan cepat halaman --}}
        <form method="GET" action="{{ route('sparepart.index') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <div class="sm:col-span-2">
                <label for="q" class="block text-xs font-medium text-slate-600 mb-1"
                       title="Cari di kode, deskripsi, semua nomor PR/PO, vendor, dan tipe MRP.">Cari (kode, deskripsi, semua no PR/PO, vendor, tipe MRP)</label>
                <input type="text" name="q" id="q" value="{{ $saringan['q'] }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="status" class="block text-xs font-medium text-slate-600 mb-1"
                       title="{{ $tip['status'] }}">Status stok (MRP vs G001)</label>
                <select name="status" id="status" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Http\Controllers\SparepartController::STATUS_SARING as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($saringan['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="aksi" class="block text-xs font-medium text-slate-600 mb-1"
                       title="{{ $tip['aksi'] }}">Aksi</label>
                <select name="aksi" id="aksi" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Models\SpMaterial::AKSI as $nilai)
                        <option value="{{ $nilai }}" @selected($saringan['aksi'] === $nilai)>{{ \App\Models\SpMaterial::labelAksi($nilai) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="punya" class="block text-xs font-medium text-slate-600 mb-1"
                       title="Saring material yang sudah punya catatan stok, PR, PO, atau data MRP.">Kepemilikan data</label>
                <select name="punya" id="punya" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Http\Controllers\SparepartController::PUNYA_SARING as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($saringan['punya'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="urut" class="block text-xs font-medium text-slate-600 mb-1"
                       title="Urutan baris tabel.">Urutan</label>
                <select name="urut" id="urut" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="reorder" @selected($saringan['urut'] === 'reorder')>Perlu tindakan dulu</option>
                    <option value="kode" @selected($saringan['urut'] === 'kode')>Kode material</option>
                    <option value="stok" @selected($saringan['urut'] === 'stok')>Stok gudang terkecil</option>
                    <option value="aksi" @selected($saringan['urut'] === 'aksi')>Aksi</option>
                    <option value="terakhir" @selected($saringan['urut'] === 'terakhir')>Terakhir diperbarui</option>
                </select>
            </div>

            {{-- Saringan header tetap dipertahankan saat saringan cepat dipakai. --}}
            @foreach(['kode', 'deskripsi', 'pr', 'po', 'mrp', 'min_stok_min', 'max_stok_min', 'min_stok', 'max_stok', 'purch', 'vendor', 'dari', 'sampai'] as $namaFilter)
                @if(($saringan[$namaFilter] ?? '') !== '')
                    <input type="hidden" name="{{ $namaFilter }}" value="{{ $saringan[$namaFilter] }}">
                @endif
            @endforeach

            <div class="flex items-center gap-3 lg:col-span-6">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Terapkan saringan
                </button>
                <a href="{{ route('sparepart.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
                <span class="ml-auto text-xs text-slate-400">
                    Menampilkan {{ number_format($baris->count(), 0, ',', '.') }} dari
                    {{ number_format($baris->total(), 0, ',', '.') }} material
                </span>
            </div>
        </form>

        {{-- Tabel keadaan dengan filter pada judul kolom --}}
        <form method="GET" action="{{ route('sparepart.index') }}">
            @if($saringan['q'] !== '')
                <input type="hidden" name="q" value="{{ $saringan['q'] }}">
            @endif
            <input type="hidden" name="urut" value="{{ $saringan['urut'] }}">
            @if($saringan['punya'] !== '')
                <input type="hidden" name="punya" value="{{ $saringan['punya'] }}">
            @endif

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-[1500px] w-full text-sm">
                        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['kode'] }}">Kode / Deskripsi</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Kode material</label>
                                            <input type="text" name="kode" value="{{ $saringan['kode'] }}" placeholder="mis. 3000"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1 mt-2">Deskripsi</label>
                                            <input type="text" name="deskripsi" value="{{ $saringan['deskripsi'] }}" placeholder="mis. seal"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['kode', 'deskripsi']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['stok_min'] }}">Stok Min (MRP)</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[14rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <p class="text-[11px] text-slate-400 mb-2">Safety Stk EXPORT MRP.</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Min</label>
                                                    <input type="number" step="any" name="min_stok_min" value="{{ $saringan['min_stok_min'] }}"
                                                           class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                </div>
                                                <div>
                                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Maks</label>
                                                    <input type="number" step="any" name="max_stok_min" value="{{ $saringan['max_stok_min'] }}"
                                                           class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                </div>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['min_stok_min', 'max_stok_min']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['stok_gudang'] }}">Stok Gudang (G001)</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[14rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <p class="text-[11px] text-slate-400 mb-2">Sisa stok Laporan Stok Gudang G001.</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>
                                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Min</label>
                                                    <input type="number" step="any" name="min_stok" value="{{ $saringan['min_stok'] }}"
                                                           class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                </div>
                                                <div>
                                                    <label class="block text-[11px] font-medium text-slate-500 mb-1">Maks</label>
                                                    <input type="number" step="any" name="max_stok" value="{{ $saringan['max_stok'] }}"
                                                           class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                </div>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['min_stok', 'max_stok']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['status'] }}">Status</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Status stok</label>
                                            <select name="status" class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">Semua</option>
                                                @foreach(\App\Http\Controllers\SparepartController::STATUS_SARING as $nilai => $label)
                                                    <option value="{{ $nilai }}" @selected($saringan['status'] === $nilai)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['status']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['aksi'] }}">Aksi</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Aksi tindak lanjut</label>
                                            <select name="aksi" class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">Semua</option>
                                                @foreach(\App\Models\SpMaterial::AKSI as $nilai)
                                                    <option value="{{ $nilai }}" @selected($saringan['aksi'] === $nilai)>{{ \App\Models\SpMaterial::labelAksi($nilai) }}</option>
                                                @endforeach
                                            </select>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['aksi']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['pr'] }}">PR (ME5A)</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Kepemilikan PR</label>
                                            <select name="pr" class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">Semua</option>
                                                @foreach(\App\Http\Controllers\SparepartController::PR_SARING as $nilai => $label)
                                                    <option value="{{ $nilai }}" @selected($saringan['pr'] === $nilai)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['pr']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['po'] }}">PO (ME2M)</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Kepemilikan PO</label>
                                            <select name="po" class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">Semua</option>
                                                @foreach(\App\Http\Controllers\SparepartController::PO_SARING as $nilai => $label)
                                                    <option value="{{ $nilai }}" @selected($saringan['po'] === $nilai)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['po']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['purch'] }}">Purchaser / Vendor</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Purchaser</label>
                                            <input type="text" name="purch" value="{{ $saringan['purch'] }}" placeholder="mis. DAVD"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1 mt-2">Vendor</label>
                                            <input type="text" name="vendor" value="{{ $saringan['vendor'] }}" placeholder="nama vendor"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['purch', 'vendor']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['mrp'] }}">MRP</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Data MRP / Stok Beda</label>
                                            <select name="mrp" class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">Semua</option>
                                                @foreach(\App\Http\Controllers\SparepartController::MRP_SARING as $nilai => $label)
                                                    <option value="{{ $nilai }}" @selected($saringan['mrp'] === $nilai)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['mrp']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold align-top">
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                                            <span title="{{ $tip['diperbarui'] }}">Diperbarui</span>
                                            <span class="text-[10px] text-teal-600 group-open:rotate-180">&#9660;</span>
                                        </summary>
                                        <div class="mt-2 min-w-[15rem] rounded-lg border border-slate-200 bg-white p-3 text-left font-normal normal-case tracking-normal text-slate-600 shadow-sm">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1">Dari tanggal</label>
                                            <input type="date" name="dari" value="{{ $saringan['dari'] }}"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <label class="block text-[11px] font-medium text-slate-500 mb-1 mt-2">Sampai tanggal</label>
                                            <input type="date" name="sampai" value="{{ $saringan['sampai'] }}"
                                                   class="w-full rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
                                                <a href="{{ $tautanFilter(['dari', 'sampai']) }}" class="text-[11px] text-slate-500 hover:text-slate-700">Bersihkan</a>
                                            </div>
                                        </div>
                                    </details>
                                </th>

                                <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold align-top">
                                    <a href="{{ route('sparepart.index') }}" class="text-[10px] font-normal normal-case tracking-normal text-slate-500 hover:text-slate-700">
                                        Reset semua
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($baris as $satu)
                                @php
                                    $kunciBaris = (string) $satu->kode_material;
                                    $riwayatPr  = json_decode((string) ($satu->m_pr_history ?? '[]'), true);
                                    $riwayatPr  = is_array($riwayatPr) ? $riwayatPr : [];
                                    $labelBeda  = $labelStokBeda($satu->m_mrp_cmp ?? null);
                                    $warnaBeda  = ($satu->m_mrp_cmp ?? null) === 'Beda'
                                        ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'
                                        : 'bg-teal-50 text-teal-700 ring-1 ring-teal-200';
                                    $statusLabel = $satu->m_stok_min === null
                                        ? 'Belum MRP'
                                        : ((bool) $satu->m_reorder ? 'REORDER' : 'Aman');
                                    $statusWarna = $satu->m_stok_min === null
                                        ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'
                                        : ((bool) $satu->m_reorder
                                            ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
                                            : 'bg-teal-50 text-teal-700 ring-1 ring-teal-200');
                                @endphp
                                <tr data-kode-material="{{ $kunciBaris }}" class="odd:bg-white even:bg-slate-50/40 hover:bg-teal-50/30 transition-colors">
                                    <td class="px-3 py-3 align-top">
                                        <p class="font-semibold text-slate-800">{{ $kunciBaris }}</p>
                                        <p class="text-slate-600">{{ $satu->m_deskripsi ?? $satu->deskripsi_pantau ?? '-' }}</p>
                                        @if(collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->isNotEmpty())
                                            <p class="text-xs text-slate-400">
                                                {{ collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->implode(' / ') }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-slate-600" title="{{ $tip['stok_min'] }}">
                                        @if($satu->m_stok_min === null)
                                            <span class="text-xs font-medium text-amber-600">Belum MRP</span>
                                        @else
                                            <p class="font-medium text-slate-700">{{ $angkaRapi($satu->m_stok_min) }}</p>
                                            <p class="text-[10px] text-slate-400">Safety Stk MRP</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums font-medium text-slate-800">{{ $angkaRapi($satu->m_stok_tersedia) }}</td>
                                    <td class="px-3 py-3 align-top">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusWarna }}" title="{{ $tip['status'] }}">
                                            {{ $statusLabel }}
                                        </span>
                                        @if(($satu->m_penanda ?? '') !== '')
                                            <p class="text-xs text-slate-400 mt-1">{{ $satu->m_penanda }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $warnaAksi[$satu->m_aksi] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }}"
                                              title="{{ \App\Models\SpMaterial::keteranganAksi($satu->m_aksi) }}">
                                            {{ \App\Models\SpMaterial::labelAksi($satu->m_aksi) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        @if((int) ($satu->m_pr_line ?? 0) > 0)
                                            <button type="button"
                                                    class="group w-full text-left"
                                                    title="{{ $tip['pr'] }}"
                                                    @click="buka = '{{ $kunciBaris }}'; tab = 'pr'">
                                                <span class="block font-medium text-slate-800 group-hover:text-teal-700">{{ $satu->m_pr_no ?? '-' }}</span>
                                                <span class="block text-xs text-slate-500">{{ $satu->m_pr_status_aksi ?? '-' }}</span>
                                                <span class="mt-1 block text-xs text-slate-400">
                                                    {{ (int) $satu->m_pr_line }} baris &middot;
                                                    <span class="{{ (int) $satu->m_pr_terbuka > 0 ? 'text-amber-700 font-medium' : '' }}">{{ (int) $satu->m_pr_terbuka }} terbuka</span>
                                                </span>
                                            </button>
                                        @else
                                            <span class="text-slate-400">Belum ada PR</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        @if((int) ($satu->m_po_uniq ?? 0) > 0)
                                            <button type="button"
                                                    class="group w-full text-left"
                                                    title="{{ $tip['po'] }}"
                                                    @click="buka = '{{ $kunciBaris }}'; tab = 'po'">
                                                <span class="block font-medium text-slate-800 group-hover:text-teal-700">{{ $satu->m_po_no ?? '-' }}</span>
                                                <span class="block text-xs text-slate-500">Sisa: {{ $angkaRapi($satu->m_po_sisa) }}</span>
                                                <span class="mt-1 block text-xs text-slate-400">
                                                    {{ (int) $satu->m_po_uniq }} PO &middot;
                                                    <span class="{{ (int) $satu->m_po_open > 0 ? 'text-amber-700 font-medium' : '' }}">{{ (int) $satu->m_po_open }} terbuka</span>
                                                </span>
                                            </button>
                                        @else
                                            <span class="text-slate-400">Belum ada PO</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top" title="{{ $tip['purch'] }}">
                                        <p class="font-medium text-slate-800">{{ $satu->m_purch ?? '-' }}</p>
                                        <p class="text-xs text-slate-500 break-words">{{ $satu->m_vendor ?? '-' }}</p>
                                    </td>
                                    <td class="px-3 py-3 align-top" title="{{ $tip['mrp'] }}">
                                        @if($adaMrp)
                                            @if($satu->m_mrp_safety !== null || $satu->m_mrp_stock !== null)
                                                <p class="text-xs text-slate-500">Stock by SAP</p>
                                                <p class="font-medium text-slate-800 tabular-nums">{{ $angkaRapi($satu->m_mrp_stock) }}</p>
                                                <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium {{ $warnaBeda }}" title="{{ $tip['stok_beda'] }}">
                                                    {{ $labelBeda }}
                                                </span>
                                            @else
                                                <span class="text-xs font-medium text-amber-600">Belum MRP</span>
                                            @endif
                                        @else
                                            <span class="text-slate-400">Belum diimpor</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 align-top text-xs text-slate-500 whitespace-nowrap" title="{{ $tip['diperbarui'] }}">
                                        {{ $satu->m_diubah_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_diubah_at)->format('d/m/Y H:i') : '-' }}
                                    </td>
                                    <td class="px-3 py-3 text-right align-top">
                                        <button type="button"
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-teal-700 hover:bg-teal-50"
                                                title="{{ $tip['detail'] }}"
                                                @click="buka = '{{ $kunciBaris }}'; tab = 'pr'">
                                            Detail
                                        </button>
                                    </td>
                                </tr>

                                <template x-if="buka === '{{ $kunciBaris }}'">
                                    <tr>
                                        <td colspan="11" class="border-0 p-0">
                                            <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-6"
                                                 role="dialog" aria-modal="true"
                                                 @keydown.escape.window="buka = null">
                                                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="buka = null"></div>

                                                <div class="relative z-10 my-6 w-full max-w-6xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                                                    {{-- Kepala popup --}}
                                                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                                                        <div class="min-w-0">
                                                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-400">Detail material</p>
                                                            <h2 class="truncate text-lg font-semibold text-slate-900">
                                                                {{ $kunciBaris }}
                                                                <span class="font-normal text-slate-500">· {{ $satu->m_deskripsi ?? $satu->deskripsi_pantau ?? '-' }}</span>
                                                            </h2>
                                                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusWarna }}" title="{{ $tip['status'] }}">
                                                                    {{ $statusLabel }}
                                                                </span>
                                                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $warnaAksi[$satu->m_aksi] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }}"
                                                                      title="{{ \App\Models\SpMaterial::keteranganAksi($satu->m_aksi) }}">
                                                                    {{ \App\Models\SpMaterial::labelAksi($satu->m_aksi) }}
                                                                </span>
                                                                @if($satu->m_mrp_safety !== null || $satu->m_mrp_stock !== null)
                                                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $warnaBeda }}" title="{{ $tip['stok_beda'] }}">
                                                                        {{ $labelBeda }}
                                                                    </span>
                                                                @endif
                                                                <span class="text-xs text-slate-400" title="{{ $tip['diperbarui'] }}">
                                                                    Diperbarui {{ $satu->m_diubah_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_diubah_at)->format('d/m/Y H:i') : '-' }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <button type="button"
                                                                class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                                                title="Tutup popup (Esc)"
                                                                @click="buka = null">
                                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                            </svg>
                                                        </button>
                                                    </div>

                                                    {{-- Tab popup --}}
                                                    <div class="border-b border-slate-200 px-5">
                                                        <nav class="flex gap-1 overflow-x-auto text-sm" aria-label="Bagian detail material">
                                                            <button type="button" @click="tab = 'pr'"
                                                                    :class="tab === 'pr' ? 'border-teal-500 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                                                    class="whitespace-nowrap border-b-2 px-3 py-3 font-medium">
                                                                PR <span class="ml-1 text-xs text-slate-400">{{ (int) ($satu->m_pr_line ?? 0) }}</span>
                                                            </button>
                                                            <button type="button" @click="tab = 'po'"
                                                                    :class="tab === 'po' ? 'border-teal-500 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                                                    class="whitespace-nowrap border-b-2 px-3 py-3 font-medium">
                                                                PO <span class="ml-1 text-xs text-slate-400">{{ (int) ($satu->m_po_uniq ?? 0) }}</span>
                                                            </button>
                                                            <button type="button" @click="tab = 'stok'"
                                                                    :class="tab === 'stok' ? 'border-teal-500 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                                                    class="whitespace-nowrap border-b-2 px-3 py-3 font-medium">
                                                                Stok G001
                                                            </button>
                                                            <button type="button" @click="tab = 'mrp'"
                                                                    :class="tab === 'mrp' ? 'border-teal-500 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                                                    class="whitespace-nowrap border-b-2 px-3 py-3 font-medium">
                                                                MRP
                                                            </button>
                                                        </nav>
                                                    </div>

                                                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4 text-sm text-slate-600">
                                                        {{-- Tab PR --}}
                                                        <div x-show="tab === 'pr'" class="space-y-4">
                                                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                                                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Ringkasan PR</p>
                                                                    <dl class="mt-3 space-y-3 text-xs">
                                                                        <div title="{{ $tip['pr_terpilih'] }}">
                                                                            <dt class="text-slate-400">PR terpilih</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_pr_no ?? '-' }} <span class="font-normal text-slate-500">({{ $satu->m_pr_status_aksi ?? '-' }})</span></dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Status seluruh PR</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_pr_status ?? '-' }}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Qty PR</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $angkaRapi($satu->m_pr_qty) }}</dd>
                                                                        </div>
                                                                        <div title="{{ $tip['pr_terbuka'] }}">
                                                                            <dt class="text-slate-400">Baris / terbuka</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">
                                                                                {{ (int) ($satu->m_pr_line ?? 0) }} baris
                                                                                <span class="{{ (int) ($satu->m_pr_terbuka ?? 0) > 0 ? 'text-amber-700' : 'text-slate-500' }}">
                                                                                    &middot; {{ (int) ($satu->m_pr_terbuka ?? 0) }} terbuka
                                                                                </span>
                                                                            </dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Pemohon</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_req_list ?? '-' }}</dd>
                                                                        </div>
                                                                        <div title="{{ $tip['diperbarui'] }}">
                                                                            <dt class="text-slate-400">Diperbarui</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">
                                                                                {{ $satu->m_pr_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_pr_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                                            </dd>
                                                                        </div>
                                                                    </dl>
                                                                </div>

                                                                <div class="rounded-xl border border-slate-200 p-4 lg:col-span-2">
                                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">History PR (EXPORT ME5A)</p>
                                                                        <span class="text-[11px] text-slate-400">{{ count($riwayatPr) }} dokumen</span>
                                                                    </div>
                                                                    @if($riwayatPr !== [])
                                                                        <div class="mt-3 overflow-x-auto">
                                                                            <table class="w-full text-[11px]">
                                                                                <thead class="text-slate-400">
                                                                                    <tr>
                                                                                        <th class="py-1 pr-2 text-left font-medium">No PR</th>
                                                                                        <th class="py-1 pr-2 text-left font-medium">Status</th>
                                                                                        <th class="py-1 pr-2 text-right font-medium">Qty</th>
                                                                                        <th class="py-1 pr-2 text-right font-medium">Baris</th>
                                                                                        <th class="py-1 pr-2 text-left font-medium">Tgl kirim</th>
                                                                                        <th class="py-1 text-left font-medium">No PO</th>
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody class="divide-y divide-slate-100">
                                                                                    @foreach($riwayatPr as $pr)
                                                                                        <tr>
                                                                                            <td class="py-1 pr-2 align-top">
                                                                                                <p class="font-medium text-slate-700">{{ $pr['no_pr'] ?? '-' }}</p>
                                                                                                @if(($pr['requisitioner'] ?? '') !== '')
                                                                                                    <p class="text-[10px] text-slate-400">{{ $pr['requisitioner'] }}</p>
                                                                                                @endif
                                                                                            </td>
                                                                                            <td class="py-1 pr-2 align-top">
                                                                                                <span class="inline-flex px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $warnaStatusPr($pr['status'] ?? null) }}">
                                                                                                    {{ $pr['status'] ?? '-' }}
                                                                                                </span>
                                                                                            </td>
                                                                                            <td class="py-1 pr-2 text-right align-top">{{ $angkaRapi($pr['qty'] ?? null) }}</td>
                                                                                            <td class="py-1 pr-2 text-right align-top">{{ (int) ($pr['baris'] ?? 0) }}</td>
                                                                                            <td class="py-1 pr-2 align-top">
                                                                                                {{ ! empty($pr['tanggal_kirim']) ? \Illuminate\Support\Carbon::parse($pr['tanggal_kirim'])->format('d/m/Y') : '-' }}
                                                                                            </td>
                                                                                            <td class="py-1 align-top break-all">{{ ($pr['no_po'] ?? '') !== '' ? $pr['no_po'] : '-' }}</td>
                                                                                        </tr>
                                                                                    @endforeach
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    @else
                                                                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                                                                            Riwayat rinci belum tersedia. Import ulang EXPORT PR (ME5A)
                                                                            untuk mengisi history PR.
                                                                        </div>
                                                                        @if(($satu->m_pr_nums ?? '') !== '')
                                                                            <p class="mt-3 text-[11px] text-slate-400">Nomor PR terbaca</p>
                                                                            <p class="mt-1 break-all text-xs text-slate-600">{{ $satu->m_pr_nums }}</p>
                                                                        @endif
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Tab PO --}}
                                                        <div x-show="tab === 'po'" style="display:none" class="space-y-4">
                                                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                                                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Ringkasan PO</p>
                                                                    <dl class="mt-3 space-y-3 text-xs">
                                                                        <div>
                                                                            <dt class="text-slate-400">PO terpilih</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_po_no ?? '-' }}</dd>
                                                                        </div>
                                                                        <div title="{{ $tip['sisa_kirim'] }}">
                                                                            <dt class="text-slate-400">Sisa kirim PO terpilih</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $angkaRapi($satu->m_po_sisa) }}</dd>
                                                                        </div>
                                                                        <div title="{{ $tip['po_terbuka'] }}">
                                                                            <dt class="text-slate-400">PO unik / terbuka</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">
                                                                                {{ (int) ($satu->m_po_uniq ?? 0) }} PO
                                                                                <span class="{{ (int) ($satu->m_po_open ?? 0) > 0 ? 'text-amber-700' : 'text-slate-500' }}">
                                                                                    &middot; {{ (int) ($satu->m_po_open ?? 0) }} terbuka
                                                                                </span>
                                                                            </dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Baris / tertutup</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">
                                                                                {{ (int) ($satu->m_po_line ?? 0) }} baris &middot; {{ (int) ($satu->m_po_closed ?? 0) }} tertutup
                                                                            </dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Sisa seluruh PO</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $angkaRapi($satu->m_po_sisa_total) }}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Tanggal PO</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_po_tanggal_teks ?? '-' }}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">Purchaser / vendor</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_purch ?? '-' }} / {{ $satu->m_vendor ?? '-' }}</dd>
                                                                        </div>
                                                                        <div title="{{ $tip['purch'] }}">
                                                                            <dt class="text-slate-400">Purchaser/vendor terakhir</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_purch_last ?? '-' }} / {{ $satu->m_vendor_last ?? '-' }}</dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt class="text-slate-400">PO sebelumnya</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">{{ $satu->m_po_prev ?? '-' }} <span class="font-normal text-slate-500">({{ $satu->m_vendor_prev ?? '-' }})</span></dd>
                                                                        </div>
                                                                        <div title="{{ $tip['diperbarui'] }}">
                                                                            <dt class="text-slate-400">Diperbarui</dt>
                                                                            <dd class="mt-0.5 font-medium text-slate-800">
                                                                                {{ $satu->m_po_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_po_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                                            </dd>
                                                                        </div>
                                                                    </dl>
                                                                </div>

                                                                <div class="rounded-xl border border-slate-200 p-4 lg:col-span-2">
                                                                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Semua nomor PO</p>
                                                                    <p class="mt-2 max-h-56 overflow-y-auto break-all rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ $satu->m_po_nums ?? '-' }}</p>
                                                                    @if(($satu->m_po_open_nums ?? '') !== '')
                                                                        <p class="mt-3 text-[11px] font-medium text-amber-700">PO masih terbuka</p>
                                                                        <p class="mt-1 break-all rounded-lg bg-amber-50 p-3 text-xs text-amber-800">{{ $satu->m_po_open_nums }}</p>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Tab Stok G001 --}}
                                                        <div x-show="tab === 'stok'" style="display:none" class="space-y-4">
                                                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['stok_gudang'] }}">
                                                                    <p class="text-[11px] text-slate-400">Stok awal</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_g_awal) }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3" title="Barang masuk pada laporan stok gudang G001.">
                                                                    <p class="text-[11px] text-slate-400">Masuk</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_g_masuk) }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3" title="Barang keluar pada laporan stok gudang G001.">
                                                                    <p class="text-[11px] text-slate-400">Keluar</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_g_keluar) }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['stok_gudang'] }}">
                                                                    <p class="text-[11px] text-slate-400">Sisa</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_g_sisa) }} {{ $satu->m_g_satuan ?? '' }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                    <p class="text-[11px] text-slate-400">Baris S.Loc</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ (int) ($satu->m_g_baris ?? 0) }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                    <p class="text-[11px] text-slate-400">Lokasi</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $satu->m_g_lokasi ?? '-' }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                    <p class="text-[11px] text-slate-400">Satuan</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $satu->m_g_satuan ?? '-' }}</p>
                                                                </div>
                                                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                    <p class="text-[11px] text-slate-400">Keterangan</p>
                                                                    <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $satu->m_ket ?? '-' }}</p>
                                                                </div>
                                                            </div>
                                                            <p class="text-xs text-slate-400" title="{{ $tip['diperbarui'] }}">
                                                                Sumber: Laporan Stok Gudang G001. Daftar pantau: {{ $satu->sumber === 'manual' ? 'Manual' : 'Master Data' }}.
                                                                Diperbarui {{ $satu->m_stok_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_stok_diupdate_at)->format('d/m/Y H:i') : '-' }}.
                                                            </p>
                                                        </div>

                                                        {{-- Tab MRP --}}
                                                        <div x-show="tab === 'mrp'" style="display:none" class="space-y-4">
                                                            @if($satu->m_mrp_safety !== null || $satu->m_mrp_stock !== null)
                                                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['stok_gudang'] }}">
                                                                        <p class="text-[11px] text-slate-400">Stok Gudang G001</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_stok_tersedia) }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['stok_beda'] }}">
                                                                        <p class="text-[11px] text-slate-400">Stock by SAP</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_mrp_stock) }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['safety'] }}">
                                                                        <p class="text-[11px] text-slate-400">Safety Stk (stok min)</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_mrp_safety) }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['reorder_pt'] }}">
                                                                        <p class="text-[11px] text-slate-400">Reorder Pt</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $angkaRapi($satu->m_mrp_reorder) }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                        <p class="text-[11px] text-slate-400">Satuan</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $satu->m_mrp_unit ?? '-' }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                        <p class="text-[11px] text-slate-400">Tipe MRP</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ $satu->m_mrp_type ?? '-' }}</p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3" title="{{ $tip['stok_beda'] }}">
                                                                        <p class="text-[11px] text-slate-400">Stok Beda</p>
                                                                        <p class="mt-0.5">
                                                                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-medium {{ $warnaBeda }}">{{ $labelBeda }}</span>
                                                                        </p>
                                                                    </div>
                                                                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                                                                        <p class="text-[11px] text-slate-400">Max Stock</p>
                                                                        <p class="mt-0.5 text-sm font-semibold text-slate-800">-</p>
                                                                    </div>
                                                                </div>
                                                                <p class="text-xs text-slate-400" title="{{ $tip['diperbarui'] }}">
                                                                    Sumber: EXPORT MRP (MD07/MD04). Diperbarui
                                                                    {{ $satu->m_mrp_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_mrp_diupdate_at)->format('d/m/Y H:i') : '-' }}.
                                                                </p>
                                                            @else
                                                                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                                                    <p class="font-medium">Belum MRP</p>
                                                                    <p class="mt-1 text-xs">
                                                                        Material ini tidak ada di berkas EXPORT MRP, sehingga belum
                                                                        punya stok minimum dan tidak dinilai REORDER.
                                                                    </p>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
                                                        <p class="text-[11px] text-slate-400">Klik tab untuk berpindah bagian. Tekan Esc untuk menutup.</p>
                                                        <button type="button"
                                                                class="rounded-lg border border-slate-200 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                                                @click="buka = null">
                                                            Tutup
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-4 py-8 text-center text-slate-400">
                                        Tidak ada material yang cocok. Bila daftar pantau masih kosong, unggah sheet
                                        MASTER DATA lewat halaman Daftar Pantau.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-slate-400">
                        Total {{ number_format($baris->total(), 0, ',', '.') }} material pada saringan ini.
                    </p>
                    {{ $baris->links() }}
                </div>
            </div>
        </form>
    </div>
@endsection
