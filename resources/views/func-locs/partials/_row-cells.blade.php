@php
    /** @var \App\Models\FunctionalLocation $item */
@endphp
<td class="px-5 py-3.5 font-mono text-xs text-slate-700">{{ $item->code }}</td>
<td class="px-5 py-3.5 text-slate-900">{{ $item->name }}</td>
<td class="px-5 py-3.5">
    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">{{ $item->level_label }}</span>
</td>
<td class="px-5 py-3.5 text-slate-600">{{ $item->assets_count }} asset</td>
<td class="px-5 py-3.5">
    <x-status-badge :status="$item->is_active ? 'active' : 'inactive'" :label="$item->is_active ? 'Aktif' : 'Nonaktif'" />
</td>
<td class="px-5 py-3.5">
    <div class="flex items-center gap-3">
        <a href="{{ route('func-locs.create', ['parent_id' => $item->id]) }}" class="text-blue-600 hover:text-blue-700 text-xs font-medium">
            Tambah Anak
        </a>
        <a href="{{ route('func-locs.edit', $item) }}" class="text-slate-600 hover:text-slate-900 text-xs font-medium">
            Edit
        </a>
        @if($item->is_active)
            <form method="POST" action="{{ route('func-locs.destroy', $item) }}" class="inline"
                  onsubmit="return confirm('Nonaktifkan node {{ $item->code }}?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-amber-600 hover:text-amber-700 text-xs font-medium">Nonaktifkan</button>
            </form>
        @endif
    </div>
</td>
