<div class="ap-tile ap-tile--neutral">
    <p class="ap-micro">Total records</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($totalRecords) }}</p>
    <p class="ap-micro" style="margin-top:4px;">Seluruh reading periode ini</p>
</div>
<div class="ap-tile ap-tile--ok">
    <p class="ap-micro"><span class="ap-dot ap-dot--ok"></span> Good</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($goodCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $goodPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $goodPct }}%;"></span></div>
</div>
<div class="ap-tile ap-tile--warn">
    <p class="ap-micro"><span class="ap-dot ap-dot--warn"></span> Alarm</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($alarmCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $alarmPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $alarmPct }}%;"></span></div>
</div>
<div class="ap-tile ap-tile--bad">
    <p class="ap-micro"><span class="ap-dot ap-dot--bad"></span> Danger</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($dangerCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $dangerPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $dangerPct }}%;"></span></div>
</div>
<div class="ap-tile ap-tile--info">
    <p class="ap-micro"><span class="ap-dot"></span> Visual bad</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($visualBadCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $visualBadPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $visualBadPct }}%;"></span></div>
</div>
