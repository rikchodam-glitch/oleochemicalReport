<div class="bg-white rounded-xl border border-slate-200 p-4">
    <p class="text-2xl font-bold text-slate-900">{{ number_format($totalRecords) }}</p>
    <p class="text-xs text-slate-500 mt-1">Total Records</p>
</div>
<div class="bg-white rounded-xl border border-green-200 p-4">
    <p class="text-2xl font-bold text-green-600">{{ number_format($goodCount) }}</p>
    <p class="text-xs text-slate-500 mt-1">Good ({{ $goodPct }}%)</p>
</div>
<div class="bg-white rounded-xl border border-amber-200 p-4">
    <p class="text-2xl font-bold text-amber-600">{{ number_format($alarmCount) }}</p>
    <p class="text-xs text-slate-500 mt-1">Alarm ({{ $alarmPct }}%)</p>
</div>
<div class="bg-white rounded-xl border border-red-200 p-4">
    <p class="text-2xl font-bold text-red-600">{{ number_format($dangerCount) }}</p>
    <p class="text-xs text-slate-500 mt-1">Danger ({{ $dangerPct }}%)</p>
</div>
<div class="bg-white rounded-xl border border-purple-200 p-4">
    <p class="text-2xl font-bold text-purple-600">{{ number_format($visualBadCount) }}</p>
    <p class="text-xs text-slate-500 mt-1">Visual Bad ({{ $visualBadPct }}%)</p>
</div>
