@props([
    'status' => '',
    'label' => null,
])

@php
    $colors = [
        'active' => 'bg-green-100 text-green-700',
        'inactive' => 'bg-slate-100 text-slate-600',
        'needs_review' => 'bg-amber-100 text-amber-700',
        'healthy' => 'bg-green-100 text-green-700',
        'exhausted' => 'bg-red-100 text-red-700',
        'error' => 'bg-red-100 text-red-700',
        'disabled' => 'bg-slate-100 text-slate-600',
        'pending' => 'bg-amber-100 text-amber-700',
        'approved' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
        'suspended' => 'bg-red-100 text-red-700',
        'draft' => 'bg-slate-100 text-slate-600',
        'completed' => 'bg-green-100 text-green-700',
        'confirmed' => 'bg-green-100 text-green-700',
        'replace' => 'bg-blue-100 text-blue-700',
        'keep_flag' => 'bg-amber-100 text-amber-700',
        'skip' => 'bg-slate-100 text-slate-600',
        'cancel' => 'bg-red-100 text-red-700',
        'equipment_repair' => 'bg-blue-100 text-blue-700',
        'area_work' => 'bg-violet-100 text-violet-700',
        'general' => 'bg-slate-100 text-slate-600',
        'collaboration' => 'bg-violet-100 text-violet-700',
        'edited' => 'bg-amber-100 text-amber-700',
    ];

    $dotColors = [
        'active' => 'bg-green-500',
        'inactive' => 'bg-slate-400',
        'needs_review' => 'bg-amber-500',
        'healthy' => 'bg-green-500',
        'exhausted' => 'bg-red-500',
        'error' => 'bg-red-500',
        'disabled' => 'bg-slate-400',
        'pending' => 'bg-amber-500',
        'approved' => 'bg-green-500',
        'rejected' => 'bg-red-500',
        'suspended' => 'bg-red-500',
        'draft' => 'bg-slate-400',
        'completed' => 'bg-green-500',
        'confirmed' => 'bg-green-500',
        'replace' => 'bg-blue-500',
        'keep_flag' => 'bg-amber-500',
        'skip' => 'bg-slate-400',
        'cancel' => 'bg-red-500',
        'collaboration' => 'bg-violet-500',
        'edited' => 'bg-amber-500',
    ];

    $displayLabel = $label ?? ucfirst($status);
    $color = $colors[$status] ?? 'bg-slate-100 text-slate-600';
    $dotColor = $dotColors[$status] ?? 'bg-slate-400';
@endphp

<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $color }}">
    <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
    {{ $displayLabel }}
</span>
