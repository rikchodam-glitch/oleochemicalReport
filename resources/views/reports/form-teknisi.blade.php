<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Laporan — {{ $teknisi?->name ?? 'Teknisi' }}</title>
    @vite('resources/css/app.css')
    <style>body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen py-5 px-3">
<div class="max-w-5xl mx-auto space-y-4">
    <div class="text-center">
        <h1 class="text-xl font-bold text-slate-900">Form Laporan Teknisi</h1>
        <p class="text-xs text-slate-500 mt-1">{{ $teknisi?->name }} · NIK {{ $teknisi?->nik ?? '-' }} · {{ \App\Models\Technician::GROUPS[$teknisi?->group] ?? ($teknisi?->group ?? '-') }}</p>
        <p class="text-[11px] text-slate-400 mt-1">Laporan dibuat atas nama akun ini. Tautan berlaku sampai {{ $tokenModel->expires_at->format('d/m/Y H:i') }}.</p>
    </div>

    @if($errors->any())
        <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('laporan.simpan', ['token' => $token]) }}" enctype="multipart/form-data" id="form-laporan" class="space-y-4">
        @csrf
        <div id="daftar-laporan" class="space-y-4"></div>

        <div class="flex flex-wrap items-center gap-3 bg-white rounded-xl border border-slate-200 p-4">
            <button type="button" id="tambah-laporan" class="px-4 py-2 rounded-lg border border-teal-300 text-teal-700 text-sm font-semibold hover:bg-teal-50">+ Tambah laporan</button>
            <button type="submit" class="px-5 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:#0E9E8E;">Kirim semua laporan</button>
            <span class="text-xs text-slate-400">Maksimal 5 laporan per sesi. Semua laporan disimpan sekaligus.</span>
        </div>
    </form>
</div>

<template id="template-laporan">
    <div class="report-card bg-white rounded-xl border border-slate-200 shadow-sm p-4 space-y-4" data-report="__R__">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Laporan</p>
                <p class="text-sm font-semibold text-slate-900">Laporan <span class="nomor-laporan">1</span></p>
            </div>
            <button type="button" class="hapus-laporan text-xs font-semibold text-rose-600 hover:text-rose-700 hidden">Hapus laporan</button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal laporan</label>
                <input type="date" name="reports[__R__][report_date]" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Tanggal kejadian (opsional)</label>
                <input type="date" name="reports[__R__][tanggal_kejadian]" class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Klasifikasi pekerjaan</label>
                <select name="reports[__R__][work_classification]" class="klasifikasi w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" required>
                    <option value="">- Pilih klasifikasi -</option>
                    <option value="zpm1">ZPM 1 - Corrective Maintenance</option>
                    <option value="zpm3">ZPM 3 - Preventive Maintenance</option>
                    <option value="zpm6">ZPM 6 - Planned Maintenance</option>
                    <option value="zpm5">ZPM 5 - Fabrikasi</option>
                    <option value="zpm7">ZPM 7 - Sipil</option>
                    <option value="zpm8">ZPM 8 - Project</option>
                    <option value="umum">UMUM</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Jenis pekerjaan</label>
                <select name="reports[__R__][jenis_pekerjaan]" class="jenis w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" required disabled>
                    <option value="">Pilih klasifikasi dulu</option>
                </select>
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between gap-2 mb-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Alat / Area</label>
                <button type="button" class="tambah-item text-xs font-semibold text-teal-700 hover:text-teal-800">[+ Tambah]</button>
            </div>
            <div class="item-list space-y-3"></div>
            <p class="text-[11px] text-slate-400 mt-1">Kosongkan alat untuk pekerjaan area. Alat tambahan dapat ditambahkan dengan [+ Tambah].</p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Deskripsi pekerjaan</label>
            <textarea name="reports[__R__][work_description]" rows="4" required class="deskripsi w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"></textarea>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Jam mulai</label>
                <input type="time" name="reports[__R__][start_time]" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Jam selesai</label>
                <input type="time" name="reports[__R__][end_time]" required class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Root cause</label>
                <textarea name="reports[__R__][root_cause]" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Temuan</label>
                <textarea name="reports[__R__][finding]" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"></textarea>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Foto dokumentasi</label>
                <div class="foto-dokumentasi-list space-y-2" data-name="reports[__R__][photos_documentation][]"></div>
                <button type="button" class="tambah-foto-dokumentasi mt-2 text-xs font-semibold text-teal-700 hover:text-teal-800">+ Tambah foto dokumentasi</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Foto hygiene clearance</label>
                <div class="foto-hygiene-list space-y-2" data-name="reports[__R__][photos_hygiene][]"></div>
                <button type="button" class="tambah-foto-hygiene mt-2 text-xs font-semibold text-teal-700 hover:text-teal-800">+ Tambah foto hygiene</button>
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between gap-2 mb-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Kolaborator</label>
                <input type="text" class="cari-kolaborator rounded-lg border-slate-200 text-xs px-2 py-1 w-48" placeholder="Cari nama / NIK">
            </div>
            <div class="kolaborator-list max-h-56 overflow-y-auto rounded-lg border border-slate-200 divide-y divide-slate-100">
                @foreach(collect($kolaborator)->groupBy('section') as $section => $anggota)
                    <details class="kolaborator-group" open>
                        <summary class="px-3 py-2 cursor-pointer text-xs font-semibold text-slate-600 bg-slate-50">{{ \App\Models\Technician::SECTIONS[$section] ?? ($section ?: 'Lainnya') }}</summary>
                        @foreach($anggota as $rekan)
                            <label class="kolaborator-row flex items-center gap-2 px-3 py-2 text-xs hover:bg-slate-50 cursor-pointer">
                                <input type="checkbox" name="reports[__R__][collaborator_ids][]" value="{{ $rekan['id'] }}" data-auto="{{ in_array($rekan['id'], $autoIds, true) ? '1' : '0' }}" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="font-medium text-slate-700">{{ $rekan['name'] }}</span>
                                <span class="text-slate-400">{{ $rekan['nik'] }}</span>
                            </label>
                        @endforeach
                    </details>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 border border-slate-100 px-3 py-2">
            <div>
                <p class="text-xs font-medium text-slate-700">Status pekerjaan</p>
                <p class="text-[10px] text-slate-400">On = selesai, Off = lanjut</p>
            </div>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <span class="text-xs text-slate-500">Lanjut</span>
                <input type="checkbox" class="status-toggle sr-only peer" checked>
                <input type="hidden" name="reports[__R__][final_status]" class="status-value" value="completed">
                <span class="w-10 h-5 rounded-full bg-slate-300 peer-checked:bg-teal-500 relative transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-5"></span>
                <span class="text-xs text-slate-500">Selesai</span>
            </label>
        </div>
    </div>
</template>

<template id="template-item">
    <div class="item bg-slate-50 rounded-xl border border-slate-200 p-3 space-y-2" data-item="__I__">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <select class="pilih-pt w-full rounded-lg border-slate-300 text-xs"></select>
            <select class="pilih-dept w-full rounded-lg border-slate-300 text-xs" disabled></select>
            <select class="pilih-area w-full rounded-lg border-slate-300 text-xs" disabled></select>
            <select class="pilih-section w-full rounded-lg border-slate-300 text-xs" disabled></select>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <select class="pilih-tipe w-full rounded-lg border-slate-300 text-xs" disabled></select>
            <input type="text" class="cari-alat w-full rounded-lg border-slate-300 text-xs sm:col-span-2" placeholder="Cari kode / deskripsi alat" disabled>
            <button type="button" class="hapus-item text-xs font-semibold text-rose-600 hover:text-rose-700">Hapus item</button>
        </div>
        <input type="hidden" name="reports[__R__][items][__I__][funcloc_id]" class="funcloc-id">
        <input type="hidden" name="reports[__R__][items][__I__][asset_id]" class="asset-id">
        <input type="hidden" name="reports[__R__][items][__I__][equipment_type]" class="equipment-type">
        <p class="alat-terpilih text-[11px] text-slate-500">Belum ada alat dipilih (pekerjaan area).</p>
        <div class="hasil-alat hidden max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white divide-y divide-slate-100"></div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const nodes = @json($nodes);
    const token = @json($token);
    let deskripsiAwal = @json($deskripsiAwal);
    const jenisMap = {
        zpm1: [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
        zpm6: [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
        zpm3: [['CM/PM', 'CM/PM']],
        zpm5: [['Fabrikasi', 'Fabrikasi']],
        zpm7: [['Sipil', 'Sipil']],
        zpm8: [['Project', 'Project']],
        umum: [['UMUM', 'UMUM']],
    };
    const wadah = document.getElementById('daftar-laporan');
    const tmplLaporan = document.getElementById('template-laporan');
    const tmplItem = document.getElementById('template-item');
    let nextReport = 0;

    function nodeAnak(parentId, level) {
        return nodes.filter(n => (n.parent_id === parentId || (parentId === null && !n.parent_id)) && n.level === level);
    }

    function isiSelect(select, daftar, placeholder, selectedId) {
        select.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        select.appendChild(opt0);
        daftar.forEach(n => {
            const opt = document.createElement('option');
            opt.value = n.id;
            opt.textContent = n.code + (n.name && n.name !== n.code ? ' — ' + n.name : '');
            if (String(n.id) === String(selectedId || '')) opt.selected = true;
            select.appendChild(opt);
        });
        select.disabled = daftar.length === 0;
    }

    function renderPreview(input, preview) {
        const files = Array.from(input.files || []);
        preview.innerHTML = '';

        files.forEach(function (file) {
            const wrap = document.createElement('div');
            wrap.className = 'relative w-16 h-16 rounded-lg overflow-hidden border border-slate-200 bg-slate-100';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.className = 'w-full h-full object-cover';
            img.onload = () => URL.revokeObjectURL(img.src);
            wrap.appendChild(img);
            preview.appendChild(wrap);
        });

        if (files.length > 0) {
            const info = document.createElement('p');
            info.className = 'w-full text-[10px] text-emerald-700';
            info.textContent = files.length + ' foto dipilih.';
            preview.appendChild(info);
        }
    }

    function kompresGambar(file) {
        return new Promise(function (resolve) {
            if (!file.type.startsWith('image/') || file.size <= 1.5 * 1024 * 1024) {
                resolve(file);
                return;
            }

            const img = new Image();
            img.onload = function () {
                const maks = 1600;
                let w = img.width;
                let h = img.height;

                if (w > maks || h > maks) {
                    const rasio = Math.min(maks / w, maks / h);
                    w = Math.round(w * rasio);
                    h = Math.round(h * rasio);
                }

                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);

                canvas.toBlob(function (blob) {
                    if (!blob) {
                        resolve(file);
                        return;
                    }

                    const nama = file.name.replace(/\.(jpe?g|png|webp)$/i, '') + '.jpg';
                    resolve(new File([blob], nama, { type: 'image/jpeg' }));
                }, 'image/jpeg', 0.82);
            };
            img.onerror = function () { resolve(file); };
            img.src = URL.createObjectURL(file);
        });
    }

    async function kompresInput(input, preview) {
        const files = Array.from(input.files || []);

        if (input.dataset.compressing === '1' || !files.some(f => f.size > 1.5 * 1024 * 1024)) {
            return;
        }

        input.dataset.compressing = '1';

        try {
            const hasil = [];

            for (const file of files) {
                hasil.push(await kompresGambar(file));
            }

            if (window.DataTransfer) {
                const dt = new DataTransfer();
                hasil.forEach(f => dt.items.add(f));
                input.files = dt.files;
                renderPreview(input, preview);
            }
        } catch (e) {
            // Bila browser menolak penggantian file, file asli tetap dipakai.
        } finally {
            delete input.dataset.compressing;
        }
    }

    function bindFoto(input, preview) {
        input.addEventListener('change', function () {
            renderPreview(input, preview);
            kompresInput(input, preview);
        });
    }

    function tambahBarisFoto(container) {
        const row = document.createElement('div');
        row.className = 'foto-row flex items-start gap-2';

        const input = document.createElement('input');
        input.type = 'file';
        input.name = container.dataset.name;
        input.accept = 'image/*';
        input.multiple = true;
        input.className = 'foto-input w-full text-xs text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-100 file:font-medium';

        const preview = document.createElement('div');
        preview.className = 'foto-preview flex flex-wrap gap-2';

        const hapus = document.createElement('button');
        hapus.type = 'button';
        hapus.textContent = '×';
        hapus.className = 'shrink-0 w-6 h-6 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-xs font-bold';
        hapus.addEventListener('click', function () {
            if (container.querySelectorAll('.foto-row').length > 1) row.remove();
            else {
                input.value = '';
                preview.innerHTML = '';
            }
        });

        row.append(input, preview, hapus);
        container.appendChild(row);
        bindFoto(input, preview);
    }

    function setupFotoList(card, listSelector, buttonSelector) {
        const container = card.querySelector(listSelector);
        const tombol = card.querySelector(buttonSelector);

        tombol.addEventListener('click', function () {
            tambahBarisFoto(container);
        });

        tambahBarisFoto(container);
    }

    function setupItem(item, reportIndex, itemIndex) {
        const pt = item.querySelector('.pilih-pt');
        const dept = item.querySelector('.pilih-dept');
        const area = item.querySelector('.pilih-area');
        const section = item.querySelector('.pilih-section');
        const tipe = item.querySelector('.pilih-tipe');
        const cari = item.querySelector('.cari-alat');
        const hasil = item.querySelector('.hasil-alat');
        const funclocId = item.querySelector('.funcloc-id');
        const assetId = item.querySelector('.asset-id');
        const equipmentType = item.querySelector('.equipment-type');
        const terpilih = item.querySelector('.alat-terpilih');
        let timer = null;

        function kosongkanAlat() {
            assetId.value = '';
            equipmentType.value = '';
            terpilih.textContent = 'Belum ada alat dipilih (pekerjaan area).';
            hasil.innerHTML = '';
            hasil.classList.add('hidden');
        }

        function lokasiTerpilih() {
            return section.value || area.value || dept.value || pt.value || '';
        }

        function isiTipe() {
            tipe.innerHTML = '';
            tipe.disabled = true;
            cari.disabled = true;
            kosongkanAlat();
            const lokasi = lokasiTerpilih();
            funclocId.value = lokasi;
            if (!lokasi) return;
            fetch('{{ url('/laporan') }}/' + token + '/tipe-alat?funcloc_id=' + encodeURIComponent(lokasi), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (res) {
                    const data = res.data || [];
                    const opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = data.length ? '- Pilih tipe alat -' : '- Tidak ada alat -';
                    tipe.appendChild(opt0);
                    data.forEach(function (t) {
                        const opt = document.createElement('option');
                        opt.value = t;
                        opt.textContent = t;
                        tipe.appendChild(opt);
                    });
                    tipe.disabled = data.length === 0;
                    cari.disabled = data.length === 0;
                })
                .catch(function () {});
        }

        function cariAlat() {
            const q = cari.value.trim();
            const lokasi = lokasiTerpilih();
            if (!lokasi) return;
            if (q.length > 0 && q.length < 2) return;
            fetch('{{ url('/laporan') }}/' + token + '/cari-alat?funcloc_id=' + encodeURIComponent(lokasi) + '&type=' + encodeURIComponent(tipe.value) + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (res) {
                    const data = res.data || [];
                    hasil.innerHTML = '';
                    if (!data.length) {
                        hasil.classList.remove('hidden');
                        const p = document.createElement('p');
                        p.className = 'px-3 py-2 text-xs text-slate-400';
                        p.textContent = 'Alat tidak ditemukan.';
                        hasil.appendChild(p);
                        return;
                    }
                    data.forEach(function (a) {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'w-full text-left px-3 py-2 text-xs hover:bg-teal-50';
                        b.textContent = (a.tech_ident_no || '-') + ' — ' + (a.description || '') + (a.equipment_type ? ' · ' + a.equipment_type : '');
                        b.addEventListener('click', function () {
                            assetId.value = a.id;
                            funclocId.value = a.funcloc_id || section.value;
                            equipmentType.value = a.equipment_type || tipe.value;
                            terpilih.textContent = 'Dipilih: ' + (a.tech_ident_no || '-') + ' — ' + (a.description || '');
                            hasil.innerHTML = '';
                            hasil.classList.add('hidden');
                        });
                        hasil.appendChild(b);
                    });
                    hasil.classList.remove('hidden');
                })
                .catch(function () {});
        }

        isiSelect(pt, nodeAnak(null, 0), '- Pilih PT -');
        pt.addEventListener('change', function () {
            isiSelect(dept, nodeAnak(Number(pt.value), 1), '- Pilih Dept -');
            isiSelect(area, [], '- Pilih Area -');
            isiSelect(section, [], '- Pilih Section -');
            funclocId.value = pt.value;
            isiTipe();
        });
        dept.addEventListener('change', function () {
            isiSelect(area, nodeAnak(Number(dept.value), 2), '- Pilih Area -');
            isiSelect(section, [], '- Pilih Section -');
            funclocId.value = dept.value || pt.value;
            isiTipe();
        });
        area.addEventListener('change', function () {
            isiSelect(section, nodeAnak(Number(area.value), 3), '- Pilih Section -');
            funclocId.value = area.value || dept.value || pt.value;
            isiTipe();
        });
        section.addEventListener('change', function () {
            if (section.value) {
                funclocId.value = section.value;
            }
            isiTipe();
        });
        tipe.addEventListener('change', function () {
            kosongkanAlat();
            cari.disabled = tipe.value === '';
            cari.value = '';
            if (tipe.value) {
                // Langsung tampilkan daftar alat untuk tipe terpilih, tanpa
                // menunggu teknisi mengetik.
                cariAlat();
            }
        });
        cari.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(cariAlat, 250);
        });
        item.querySelector('.hapus-item').addEventListener('click', function () {
            const list = item.parentElement;
            if (list.querySelectorAll('.item').length > 1) item.remove();
        });
    }

    function setupReport(card, reportIndex) {
        card.querySelector('.nomor-laporan').textContent = reportIndex + 1;
        const klasifikasi = card.querySelector('.klasifikasi');
        const jenis = card.querySelector('.jenis');
        const itemList = card.querySelector('.item-list');

        function isiJenis() {
            const daftar = jenisMap[klasifikasi.value] || [];
            jenis.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = daftar.length ? '- Pilih jenis -' : 'Pilih klasifikasi dulu';
            jenis.appendChild(opt0);
            daftar.forEach(function (pair) {
                const opt = document.createElement('option');
                opt.value = pair[0];
                opt.textContent = pair[1];
                jenis.appendChild(opt);
            });
            jenis.disabled = daftar.length === 0;
        }

        klasifikasi.addEventListener('change', isiJenis);
        isiJenis();

        function tambahItem() {
            const html = tmplItem.innerHTML.replace(/__R__/g, reportIndex).replace(/__I__/g, itemList.querySelectorAll('.item').length);
            const wrap = document.createElement('div');
            wrap.innerHTML = html;
            const item = wrap.firstElementChild;
            itemList.appendChild(item);
            setupItem(item, reportIndex, itemList.querySelectorAll('.item').length - 1);
        }
        tambahItem();
        card.querySelector('.tambah-item').addEventListener('click', function () {
            if (itemList.querySelectorAll('.item').length >= {{ \App\Services\ReportFormService::MAKS_ITEM }}) return;
            tambahItem();
        });

        const status = card.querySelector('.status-toggle');
        const statusValue = card.querySelector('.status-value');
        status.addEventListener('change', function () {
            statusValue.value = status.checked ? 'completed' : 'continue';
        });

        setupFotoList(card, '.foto-dokumentasi-list', '.tambah-foto-dokumentasi');
        setupFotoList(card, '.foto-hygiene-list', '.tambah-foto-hygiene');

        card.querySelectorAll('input[data-auto="1"]').forEach(function (cb) { cb.checked = true; });

        const cariKolab = card.querySelector('.cari-kolaborator');
        cariKolab.addEventListener('input', function () {
            const q = cariKolab.value.toLowerCase();
            card.querySelectorAll('.kolaborator-row').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        card.querySelector('.hapus-laporan').addEventListener('click', function () {
            if (wadah.querySelectorAll('.report-card').length > 1) card.remove();
        });

        if (deskripsiAwal && reportIndex === 0) {
            card.querySelector('.deskripsi').value = deskripsiAwal;
            deskripsiAwal = '';
        }
    }

    function tambahLaporan() {
        if (wadah.querySelectorAll('.report-card').length >= {{ \App\Services\ReportFormService::MAKS_LAPORAN }}) return;
        const index = nextReport++;
        const html = tmplLaporan.innerHTML.replace(/__R__/g, index);
        const wrap = document.createElement('div');
        wrap.innerHTML = html;
        const card = wrap.firstElementChild;
        wadah.appendChild(card);
        setupReport(card, index);
        card.querySelectorAll('.hapus-laporan').forEach(el => el.classList.remove('hidden'));
    }

    document.getElementById('tambah-laporan').addEventListener('click', tambahLaporan);
    tambahLaporan();

    document.getElementById('form-laporan').addEventListener('submit', function (event) {
        if (document.querySelector('input[data-compressing="1"]')) {
            event.preventDefault();
            alert('Foto sedang dikompres. Tunggu sebentar lalu kirim lagi.');
            return;
        }

        const tombol = this.querySelector('button[type="submit"]');
        if (tombol) {
            tombol.disabled = true;
            tombol.textContent = 'Mengirim...';
        }
    });
});
</script>
</body>
</html>
