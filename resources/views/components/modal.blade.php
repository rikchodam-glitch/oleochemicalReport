@props([
    'name' => 'modal',
    'title' => '',
    'maxWidth' => 'md',
])

@php
    $modalId = 'modal-' . $name;
    $widths = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ];
    $width = $widths[$maxWidth] ?? 'max-w-md';
@endphp

<div id="{{ $modalId }}"
     style="display:none"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal('{{ $name }}')"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full {{ $width }} p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
            <button onclick="closeModal('{{ $name }}')" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
                {{ $slot }}
    </div>
</div>

@push('scripts')
<script>
function openModal(name) {
    var el = document.getElementById('modal-' + name);
    if (el) el.style.display = 'flex';
}
function closeModal(name) {
    var el = document.getElementById('modal-' + name);
    if (el) el.style.display = 'none';
}
</script>
@endpush

