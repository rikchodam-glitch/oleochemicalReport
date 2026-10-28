@foreach($harian as $hari)
    @include('technicians.partials._baris-manhour', [
        'hari'             => $hari,
        'parameterLaporan' => $parameterLaporan,
    ])
@endforeach
