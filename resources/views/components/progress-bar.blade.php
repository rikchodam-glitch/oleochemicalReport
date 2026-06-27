@props([
    'percent' => 0,
    'color' => 'green',
    'size' => 'default',
    'showLabel' => true,
    'label' => '',
    'value' => '',
])

@php
    $sizeClass = $size === 'sm' ? 'h-1' : 'h-1.5';
    $colors = [
        'green' => 'bg-green-500',
        'amber' => 'bg-amber-500',
        'red' => 'bg-red-500',
        'blue' => 'bg-blue-500',
        'violet' => 'bg-violet-500',
    ];
    $barColor = $colors[$color] ?? 'bg-green-500';
@endphp

<div class="space-y-1">
    @if($showLabel)
        <div class="flex justify-between text-xs text-slate-500">
            @if($label)
                <span>{{ $label }}</span>
            @endif
            @if($value)
                <span class="font-medium text-slate-700">{{ $value }}</span>
            @endif
        </div>
    @endif
    <div class="{{ $sizeClass }} bg-slate-100 rounded-full overflow-hidden">
        <div class="h-full {{ $barColor }} rounded-full transition-all duration-300"
             style="width: {{ min(100, max(0, $percent)) }}%"></div>
    </div>
</div>
