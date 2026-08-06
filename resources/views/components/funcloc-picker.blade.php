@props([
    'name' => 'funcloc_id',
    'label' => 'Functional Location',
    'selectedId' => null,
    'selectedLabel' => null,
    'required' => true,
    'placeholder' => 'Cari kode atau nama lokasi...',
    'level' => null,
])

{{--
    Komponen pemilihan node FunctionalLocation, dipakai di form web
    (mis. Create/Edit Asset) sebagai pengganti input teks bebas atau
    dropdown cascading Company/Department/Area/SubArea lama.

    Tampilan berupa panel collapsible: saat ditutup hanya menampilkan
    ringkasan node terpilih (atau tombol "Pilih Lokasi" jika kosong).
    Saat dibuka, panel menampilkan dua mode yang saling melengkapi:
    - Mode jelajah (tree): hierarki PT > Departemen > Area > Sub Area,
      tiap node bisa di-expand/collapse dan dimuat berjenjang (lazy-load)
      lewat endpoint func-locs.children agar ringan untuk data besar.
    - Mode cari (search): aktif otomatis begitu kotak pencarian diisi,
      menampilkan hasil pencocokan kode/nama lewat endpoint
      func-locs.search beserta jalur leluhurnya (ancestors) untuk
      konteks lokasi tanpa harus menelusuri tree secara manual.

    Props:
    - name          : nama field yang dikirim ke server (nilai berupa id FuncLoc)
    - label         : label yang ditampilkan di atas input
    - selectedId    : id FuncLoc yang sudah terpilih (mode edit)
    - selectedLabel : teks tampilan untuk selectedId (mis. "KODE — Nama")
    - required      : tampilkan tanda wajib dan atribut required pada input tersembunyi
    - placeholder   : placeholder pada kolom pencarian
    - level         : batasi pencarian ke level tertentu (opsional)
--}}
<div x-data="funcLocPicker({
        selectedId: {{ $selectedId !== null ? (int) $selectedId : 'null' }},
        selectedLabel: @js($selectedLabel),
        level: {{ $level !== null ? (int) $level : 'null' }},
        searchUrl: '{{ route('func-locs.search') }}',
        childrenUrl: '{{ route('func-locs.children') }}',
    })" x-on:click.outside="open = false" class="space-y-1.5">
    <label class="block text-sm font-medium text-slate-700">
        {{ $label }}
        @if($required)
            <span class="text-red-500">*</span>
        @endif
    </label>

    <input type="hidden" name="{{ $name }}" x-model="selectedId" {{ $required ? 'required' : '' }}>

    <div class="relative">
        {{-- Ringkasan node terpilih / tombol pembuka panel --}}
        <button type="button"
                x-on:click="toggleOpen()"
                class="w-full flex items-center justify-between gap-2 border border-slate-300 rounded-lg px-3 py-2 text-sm text-left hover:border-slate-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500">
            <span x-show="!selectedId" class="text-slate-400">Pilih lokasi...</span>
            <span x-show="selectedId" class="font-medium text-slate-700 truncate" x-text="selectedLabel"></span>
            <svg class="w-4 h-4 text-slate-400 flex-shrink-0 transition-transform" :class="open ? 'rotate-180' : ''"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" x-cloak
             class="absolute z-10 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-lg overflow-hidden">
            {{-- Kotak pencarian: mengetik sesuatu berpindah ke mode cari --}}
            <div class="p-2 border-b border-slate-100">
                <input type="text"
                       x-model="query"
                       x-on:input.debounce.300ms="search()"
                       autocomplete="off"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="{{ $placeholder }}">
            </div>

            <div class="max-h-72 overflow-y-auto">
                {{-- Mode cari — aktif selama kotak pencarian tidak kosong --}}
                <template x-if="query.trim().length > 0">
                    <div>
                        <template x-if="searchLoading">
                            <div class="px-3 py-2 text-sm text-slate-400">Mencari...</div>
                        </template>
                        <template x-if="!searchLoading && searchResults.length === 0">
                            <div class="px-3 py-2 text-sm text-slate-400">Tidak ditemukan.</div>
                        </template>
                        <template x-for="item in searchResults" :key="item.id">
                            <button type="button"
                                    x-on:click="selectSearchResult(item)"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 transition-colors border-b border-slate-50 last:border-b-0">
                                <span class="font-mono text-xs text-slate-700" x-text="item.code"></span>
                                <span class="block text-slate-500 text-xs" x-text="item.name + ' (' + item.level_label + ')'"></span>
                                <span class="block text-slate-400 text-[11px]" x-show="item.ancestors.length > 0" x-text="ancestorBreadcrumb(item)"></span>
                            </button>
                        </template>
                    </div>
                </template>

                {{-- Mode jelajah — tree hierarki collapsible, tampil saat kotak pencarian kosong --}}
                <template x-if="query.trim().length === 0">
                    <div>
                        <template x-if="treeLoading && flatNodes.length === 0">
                            <div class="px-3 py-2 text-sm text-slate-400">Memuat lokasi...</div>
                        </template>
                        <template x-if="!treeLoading && flatNodes.length === 0">
                            <div class="px-3 py-2 text-sm text-slate-400">Belum ada data lokasi.</div>
                        </template>
                        <template x-for="row in flatNodes" :key="row.node.id">
                            <div class="flex items-center gap-1 px-2 py-1.5 hover:bg-slate-50 border-b border-slate-50 last:border-b-0"
                                 :style="'padding-left: ' + (row.depth * 16 + 8) + 'px'">
                                <button type="button"
                                        x-on:click="toggleExpand(row.node)"
                                        class="w-5 h-5 flex-shrink-0 flex items-center justify-center text-slate-400 hover:text-slate-600"
                                        x-show="row.node.has_children">
                                    <svg class="w-3.5 h-3.5 transition-transform" :class="row.expanded ? 'rotate-90' : ''"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                                <span class="w-5 h-5 flex-shrink-0" x-show="!row.node.has_children"></span>

                                <button type="button"
                                        x-on:click="selectNode(row.node)"
                                        class="flex-1 min-w-0 text-left text-sm">
                                    <span class="font-mono text-xs text-slate-700" x-text="row.node.code"></span>
                                    <span class="text-slate-500 text-xs" x-text="' — ' + row.node.name"></span>
                                </button>

                                <span class="text-[11px] text-slate-400 flex-shrink-0" x-text="row.node.level_label"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <template x-if="selectedId">
        <p class="text-xs text-slate-500 flex items-center gap-1.5">
            <span>Terpilih:</span>
            <span class="font-medium text-slate-700" x-text="selectedLabel"></span>
            <button type="button" x-on:click="clear()" class="text-red-500 hover:text-red-600">Hapus</button>
        </p>
    </template>

    @error($name)
        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
    @enderror
</div>

@once
    @push('scripts')
    <script>
    function funcLocPicker(config) {
        return {
            selectedId: config.selectedId,
            selectedLabel: config.selectedLabel,
            level: config.level,
            searchUrl: config.searchUrl,
            childrenUrl: config.childrenUrl,
            open: false,
            query: '',

            // Mode cari
            searchResults: [],
            searchLoading: false,

            // Mode jelajah (tree collapsible, lazy-load per level)
            treeRoots: [],
            treeLoading: false,
            childrenCache: {},
            expandedIds: [],
            flatNodes: [],

            /**
             * Buka/tutup panel picker. Saat pertama kali dibuka, muat level
             * root (PT) jika belum pernah dimuat sebelumnya.
             */
            toggleOpen() {
                this.open = !this.open;

                if (this.open && this.treeRoots.length === 0) {
                    this.loadChildren(null);
                }
            },

            /**
             * Muat daftar anak aktif dari satu parent (null untuk root/PT)
             * lewat endpoint func-locs.children, lalu simpan ke cache agar
             * tidak fetch ulang saat node yang sama di-expand lagi.
             *
             * @param {number|null} parentId
             */
            loadChildren(parentId) {
                const cacheKey = parentId ?? 'root';

                if (this.childrenCache[cacheKey]) {
                    this.buildFlatList();
                    return;
                }

                this.treeLoading = true;

                let url = this.childrenUrl;
                if (parentId !== null) {
                    url += '?parent_id=' + parentId;
                }

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(res => res.json())
                    .then(data => {
                        const nodes = data.success ? data.results : [];
                        this.childrenCache[cacheKey] = nodes;

                        if (parentId === null) {
                            this.treeRoots = nodes;
                        }

                        this.buildFlatList();
                    })
                    .catch(() => {
                        this.childrenCache[cacheKey] = [];
                    })
                    .finally(() => {
                        this.treeLoading = false;
                    });
            },

            /**
             * Expand atau collapse satu node di tree. Expand memuat child
             * dari cache jika sudah ada, atau fetch baru jika belum.
             *
             * @param {object} node
             */
            toggleExpand(node) {
                if (!node.has_children) {
                    return;
                }

                const isExpanded = this.expandedIds.includes(node.id);

                if (isExpanded) {
                    this.expandedIds = this.expandedIds.filter(id => id !== node.id);
                    this.buildFlatList();
                    return;
                }

                this.expandedIds.push(node.id);

                if (this.childrenCache[node.id]) {
                    this.buildFlatList();
                    return;
                }

                this.loadChildren(node.id);
            },

            /**
             * Susun ulang flatNodes dari treeRoots + childrenCache mengikuti
             * expandedIds saat ini. Dipakai agar tree bisa dirender sebagai
             * daftar datar (lebih sederhana di Alpine daripada template
             * rekursif), dengan properti depth untuk indentasi tampilan.
             */
            buildFlatList() {
                const flat = [];
                const walk = (nodes, depth) => {
                    nodes.forEach(node => {
                        const expanded = this.expandedIds.includes(node.id);
                        flat.push({ node, depth, expanded });

                        if (expanded && this.childrenCache[node.id]) {
                            walk(this.childrenCache[node.id], depth + 1);
                        }
                    });
                };

                walk(this.treeRoots, 0);
                this.flatNodes = flat;
            },

            /**
             * Jalankan pencarian debounced ke endpoint func-locs.search.
             * Kosongkan hasil bila kotak pencarian dikosongkan kembali
             * (kembali otomatis ke mode jelajah lewat x-if di template).
             */
            search() {
                const term = this.query.trim();

                if (term === '') {
                    this.searchResults = [];
                    return;
                }

                this.searchLoading = true;

                let url = this.searchUrl + '?q=' + encodeURIComponent(term);
                if (this.level !== null) {
                    url += '&level=' + this.level;
                }

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(res => res.json())
                    .then(data => {
                        this.searchResults = data.success ? data.results : [];
                    })
                    .catch(() => {
                        this.searchResults = [];
                    })
                    .finally(() => {
                        this.searchLoading = false;
                    });
            },

            /**
             * Bangun teks breadcrumb "PT01 > DEPT01 > AREA01" dari ancestors
             * satu hasil pencarian, dipakai sebagai konteks jalur lokasi.
             *
             * @param {object} item
             * @return {string}
             */
            ancestorBreadcrumb(item) {
                return item.ancestors.map(a => a.code).join(' > ');
            },

            /**
             * Pilih satu node dari mode jelajah (tree).
             *
             * @param {object} node
             */
            selectNode(node) {
                this.selectedId = node.id;
                this.selectedLabel = node.code + ' — ' + node.name;
                this.closeAndReset();
            },

            /**
             * Pilih satu node dari hasil mode cari.
             *
             * @param {object} item
             */
            selectSearchResult(item) {
                this.selectedId = item.id;
                this.selectedLabel = item.label;
                this.closeAndReset();
            },

            /**
             * Tutup panel dan kosongkan kotak pencarian setelah node dipilih,
             * supaya panel kembali ke mode jelajah saat dibuka lagi.
             */
            closeAndReset() {
                this.query = '';
                this.searchResults = [];
                this.open = false;
            },

            clear() {
                this.selectedId = null;
                this.selectedLabel = null;
            },
        };
    }
    </script>
    @endpush
@endonce
