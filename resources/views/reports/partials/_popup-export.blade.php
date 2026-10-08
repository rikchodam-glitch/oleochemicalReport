{{--
    Popup Export Laporan.

    Dipisah sebagai partial supaya index.blade.php (839 baris) tidak perlu
    ditulis ulang. Berisi pengaturan rentang tanggal, jenis pekerjaan (boleh
    banyak pilihan), dan filter lain; nilainya diisi awal dari filter halaman
    yang sedang aktif supaya berkas yang diunduh sesuai yang sedang dilihat.
--}}
@push('modals')
    <div id="modal-export-laporan"
         class="hidden fixed inset-0 z-50 items-start justify-center bg-slate-900/50 p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl my-4">
            <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-slate-100">
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Export Laporan</h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Atur rentang tanggal dan jenis pekerjaan. Berkas memuat kolom lokasi &amp; alat yang lengkap,
                        foto (tautan selalu, gambar bila dicentang), ditambah lembar Ringkasan dan Petunjuk pemakaian.
                    </p>
                </div>
                <button type="button" onclick="tutupExport()" title="Tutup"
                        class="text-slate-400 hover:text-slate-600 shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form id="form-export-laporan" method="GET" action="{{ route('reports.export-excel') }}"
                  class="px-5 py-4 space-y-5 max-h-[70vh] overflow-y-auto">

                <input type="hidden" name="tanggal" value="{{ $tanggalAktif->toDateString() }}">
                @if(request('search'))
                    <input type="hidden" name="search" value="{{ request('search') }}">
                @endif
                @if(request('report_code'))
                    <input type="hidden" name="report_code" value="{{ request('report_code') }}">
                @endif
                @if(request('asset_code'))
                    <input type="hidden" name="asset_code" value="{{ request('asset_code') }}">
                @endif

                {{-- Format berkas --}}
                <div>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Format Berkas</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex items-start gap-2.5 rounded-lg border-2 border-teal-300 bg-teal-50/60 px-3 py-2.5 cursor-pointer">
                            <input type="radio" name="format" value="excel" checked
                                   class="mt-0.5 text-teal-600 focus:ring-teal-500" data-export-format>
                            <span>
                                <span class="block text-sm font-medium text-slate-800">Excel (.xlsx)</span>
                                <span class="block text-[11px] text-slate-500">
                                    Daftar Laporan + Ringkasan + Petunjuk, siap cetak
                                </span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2.5 rounded-lg border-2 border-slate-200 px-3 py-2.5 cursor-pointer">
                            <input type="radio" name="format" value="csv"
                                   class="mt-0.5 text-teal-600 focus:ring-teal-500" data-export-format>
                            <span>
                                <span class="block text-sm font-medium text-slate-800">CSV</span>
                                <span class="block text-[11px] text-slate-500">
                                    Satu tabel teks ber-BOM UTF-8, untuk diolah ulang
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                {{-- Isi berkas: foto disematkan (khusus Excel) --}}
                <div id="export-baris-sertakan-foto">
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Isi Berkas</p>
                    <label class="flex items-start gap-2.5 rounded-lg border border-slate-200 px-3 py-2.5 cursor-pointer hover:bg-slate-50">
                        <input type="checkbox" name="sertakan_foto" value="1" id="export-sertakan-foto"
                               class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Sertakan foto dokumentasi &amp; hygiene</span>
                            <span class="block text-[11px] text-slate-500">
                                Foto pertama tiap jenis ditempel sebagai gambar pada kolom "... (gambar)", maksimal 300 gambar.
                                Tautan seluruh foto selalu ikut pada kolom "... (tautan)". Pilihan ini hanya berlaku untuk Excel.
                            </span>
                        </span>
                    </label>
                    <p id="export-catatan-foto" class="text-[11px] text-slate-400 mt-1"></p>
                </div>

                {{-- Rentang tanggal --}}
                <div>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Rentang Tanggal</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="export-date-from" class="block text-xs text-slate-500 mb-1">Tanggal Dari</label>
                            <input type="date" name="date_from" id="export-date-from" value="{{ request('date_from') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label for="export-date-to" class="block text-xs text-slate-500 mb-1">Tanggal Sampai</label>
                            <input type="date" name="date_to" id="export-date-to" value="{{ request('date_to') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        <span class="text-xs text-slate-400">Pilih cepat:</span>
                        @foreach([
                            'hari-ini'   => 'Hari ini',
                            '7-hari'     => '7 hari terakhir',
                            'bulan-ini'  => 'Bulan ini',
                            'bulan-lalu' => 'Bulan lalu',
                            'semua'      => 'Seluruh data',
                        ] as $kunciCepat => $labelCepat)
                            <button type="button" data-export-cepat="{{ $kunciCepat }}"
                                    class="px-2.5 py-1 rounded-lg border border-slate-200 text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors">
                                {{ $labelCepat }}
                            </button>
                        @endforeach
                    </div>

                    <label class="inline-flex items-center gap-2 mt-2 text-sm text-slate-600">
                        <input type="checkbox" name="tanpa_batas" value="1" id="export-tanpa-batas"
                               class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Seluruh data (abaikan rentang tanggal)
                    </label>
                    <p id="export-catatan-periode" class="text-[11px] text-slate-400 mt-1"></p>
                </div>

                {{-- Jenis pekerjaan (boleh lebih dari satu) --}}
                <div>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">
                        Jenis Pekerjaan
                        <span class="font-normal normal-case text-slate-400">(boleh pilih lebih dari satu)</span>
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach(['EI', 'Mekanik', 'CM/PM', 'CM', 'dCM', 'PM', 'Fabrikasi', 'Sipil', 'IT', 'Project', 'UMUM'] as $jenisOpsi)
                            <label class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer text-sm text-slate-700">
                                <input type="checkbox" name="jenis_pekerjaan_multi[]" value="{{ $jenisOpsi }}"
                                       class="rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                       {{ request('jenis_pekerjaan') === $jenisOpsi ? 'checked' : '' }}>
                                {{ $jenisOpsi }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1">Tidak dicentang berarti semua jenis pekerjaan ikut diekspor.</p>
                </div>

                {{-- Filter lain (sama dengan filter lanjutan halaman Laporan) --}}
                <div>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Filter Lain</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div>
                            <label for="export-tampilan" class="block text-xs text-slate-500 mb-1">Tampilan</label>
                            <select name="tampilan" id="export-tampilan"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="reguler" {{ $tampilan === 'reguler' ? 'selected' : '' }}>Reguler</option>
                                <option value="shift" {{ $tampilan === 'shift' ? 'selected' : '' }}>Shift</option>
                                <option value="semua" {{ request('tampilan') === 'semua' ? 'selected' : '' }}>Keduanya (Reguler + Shift)</option>
                            </select>
                        </div>
                        <div id="export-baris-grub" class="{{ $tampilan === 'shift' ? '' : 'hidden' }}">
                            <label for="export-grub" class="block text-xs text-slate-500 mb-1">Grub</label>
                            <select name="grub" id="export-grub"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Grub</option>
                                @foreach($grubOptions as $kunciGrub => $labelGrub)
                                    <option value="{{ $kunciGrub }}" {{ $grubAktif === $kunciGrub ? 'selected' : '' }}>
                                        {{ $labelGrub }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Status, tipe, klasifikasi, dan wilayah kerja --}}
                <div>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Status &amp; Wilayah Kerja</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div>
                            <label for="export-status" class="block text-xs text-slate-500 mb-1">Status Pekerjaan</label>
                            <select name="status" id="export-status"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Status</option>
                                <option value="continue" {{ request('status') === 'continue' ? 'selected' : '' }}>Continue (belum selesai)</option>
                                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Done (selesai)</option>
                                <option value="needs_review" {{ request('status') === 'needs_review' ? 'selected' : '' }}>Perlu Diperiksa</option>
                            </select>
                        </div>
                        <div>
                            <label for="export-tipe" class="block text-xs text-slate-500 mb-1">Tipe Laporan</label>
                            <select name="report_type" id="export-tipe"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Tipe</option>
                                <option value="equipment_repair" {{ request('report_type') === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                                <option value="area_work" {{ request('report_type') === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                                <option value="general" {{ request('report_type') === 'general' ? 'selected' : '' }}>Umum</option>
                            </select>
                        </div>

                        <div>
                            <label for="export-klasifikasi" class="block text-xs text-slate-500 mb-1">Klasifikasi (Wizard)</label>
                            <select name="work_classification" id="export-klasifikasi"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Klasifikasi</option>
                                <option value="zpm1" {{ request('work_classification') === 'zpm1' ? 'selected' : '' }}>ZPM 1 - Corrective</option>
                                <option value="zpm3" {{ request('work_classification') === 'zpm3' ? 'selected' : '' }}>ZPM 3 - Preventive</option>
                                <option value="zpm6" {{ request('work_classification') === 'zpm6' ? 'selected' : '' }}>ZPM 6 - Planned</option>
                                <option value="zpm5" {{ request('work_classification') === 'zpm5' ? 'selected' : '' }}>ZPM 5 - Fabrikasi</option>
                                <option value="zpm7" {{ request('work_classification') === 'zpm7' ? 'selected' : '' }}>ZPM 7 - Sipil</option>
                                <option value="zpm8" {{ request('work_classification') === 'zpm8' ? 'selected' : '' }}>ZPM 8 - Project</option>
                            </select>
                        </div>
                        <div>
                            <label for="export-area" class="block text-xs text-slate-500 mb-1">Area</label>
                            <select name="area_id" id="export-area"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Area</option>
                                @foreach($areas as $area)
                                    <option value="{{ $area->id }}" {{ (string) request('area_id') === (string) $area->id ? 'selected' : '' }}>
                                        {{ $area->code }} - {{ $area->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="export-teknisi" class="block text-xs text-slate-500 mb-1">Teknisi</label>
                            <select name="technician_id" id="export-teknisi"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Semua Teknisi</option>
                                @foreach($technicians as $technician)
                                    <option value="{{ $technician->id }}" {{ (string) request('technician_id') === (string) $technician->id ? 'selected' : '' }}>
                                        {{ $technician->name }}{{ $technician->nik ? ' (' . $technician->nik . ')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Tombol aksi --}}
                <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
                    <p class="text-[11px] text-slate-400 max-w-md leading-snug">
                        Berkas mengikuti aturan tabel Laporan: satu laporan = satu baris dan nama kolaborator
                        dicantumkan pada kolomnya (tanpa baris tambahan).
                    </p>
                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" onclick="tutupExport()"
                                class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            Batal
                        </button>
                        <button type="submit" id="export-tombol-unduh"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Unduh Berkas
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
@endpush
