<div class="ap-tile">
    <p class="ap-micro">Total records</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($totalRecords) }}</p>
    <p class="ap-micro" style="margin-top:4px;">Seluruh reading periode ini</p>
</div>
<div class="ap-tile">
    <p class="ap-micro"><span class="ap-dot ap-dot--ok"></span> Good</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($goodCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $goodPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $goodPct }}%;background:#30d158;"></span></div>
</div>
<div class="ap-tile">
    <p class="ap-micro"><span class="ap-dot ap-dot--warn"></span> Alarm</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($alarmCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $alarmPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $alarmPct }}%;background:#ff9f0a;"></span></div>
</div>
<div class="ap-tile">
    <p class="ap-micro"><span class="ap-dot ap-dot--bad"></span> Danger</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($dangerCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $dangerPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $dangerPct }}%;background:#ff453a;"></span></div>
</div>
<div class="ap-tile">
    <p class="ap-micro"><span class="ap-dot" style="background:#bf5af2;"></span> Visual bad</p>
    <p class="ap-num" style="margin-top:8px;">{{ number_format($visualBadCount) }}</p>
    <p class="ap-micro" style="margin-top:4px;">{{ $visualBadPct }}% dari total</p>
    <div class="ap-bar" style="margin-top:12px;"><span style="width:{{ $visualBadPct }}%;background:#bf5af2;"></span></div>
</div>
