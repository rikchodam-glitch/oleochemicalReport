@php
    /** @var \App\Models\FunctionalLocation $node */
    $hasChildren = $node->child_nodes->isNotEmpty();
    $indent = $node->level * 24;
@endphp

<div x-data="{ open: true }" class="border-b border-slate-50 last:border-b-0">
    <div class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 transition-colors" style="padding-left: {{ 20 + $indent }}px">
        <button type="button"
                @click="open = !open"
                class="w-5 h-5 flex items-center justify-center text-slate-400 shrink-0 {{ $hasChildren ? '' : 'invisible' }}">
            <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>

        <div class="w-40 shrink-0">
            <span class="font-mono text-xs text-slate-700">{{ $node->code }}</span>
        </div>

        <div class="flex-1 min-w-0">
            <span class="text-sm text-slate-900 truncate block">{{ $node->name }}</span>
        </div>

        <div class="w-24 shrink-0">
            <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">{{ $node->level_label }}</span>
        </div>

        <div class="w-20 shrink-0 text-sm text-slate-600">
            {{ $node->assets_count }} asset
        </div>

        <div class="w-24 shrink-0">
            <x-status-badge :status="$node->is_active ? 'active' : 'inactive'" :label="$node->is_active ? 'Aktif' : 'Nonaktif'" />
        </div>

        <div class="w-44 shrink-0 flex items-center gap-3 justify-end">
            <a href="{{ route('func-locs.create', ['parent_id' => $node->id]) }}" class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                Tambah Anak
            </a>
            <a href="{{ route('func-locs.edit', $node) }}" class="text-slate-600 hover:text-slate-900 text-xs font-medium">
                Edit
            </a>
            @if($node->is_active)
                <form method="POST" action="{{ route('func-locs.destroy', $node) }}" class="inline"
                      onsubmit="return confirm('Nonaktifkan node {{ $node->code }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-amber-600 hover:text-amber-700 text-xs font-medium">Nonaktifkan</button>
                </form>
            @endif
        </div>
    </div>

    @if($hasChildren)
        <div x-show="open" x-cloak>
            @foreach($node->child_nodes as $child)
                @include('func-locs.partials._tree-node', ['node' => $child])
            @endforeach
        </div>
    @endif
</div>
