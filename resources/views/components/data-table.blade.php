@props([
    'id' => 'data-table',
    'headers' => [],
    'rows' => [],
    'searchable' => true,
    'searchPlaceholder' => 'Cari...',
    'emptyMessage' => 'Tidak ada data.',
])

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    @if($searchable)
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            {{ $headerLeft ?? '' }}
        </div>
        <input type="text"
               class="text-sm border border-slate-200 rounded-lg px-3 py-1.5 w-48 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 placeholder:text-slate-400"
               placeholder="{{ $searchPlaceholder }}"
               x-data
               x-on:input.debounce.300ms="$dispatch('search', { value: $el.value })">
    </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    @foreach($headers as $header)
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 {{ $header['class'] ?? '' }}">
                            {{ $header['label'] ?? $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($rows as $row)
                    <tr class="hover:bg-slate-50 transition-colors">
                        {{ $row }}
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($headers) }}" class="px-5 py-8 text-center text-sm text-slate-400">
                            {{ $emptyMessage }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(isset($pagination))
        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between text-sm text-slate-500">
            {{ $pagination }}
        </div>
    @endif
</div>
