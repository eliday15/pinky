@extends('pdf.overtime-weekly._layout')

@php
    $fmt = function ($v) {
        if ($v <= 0) return '0';
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    };

    $dayLabels = [
        'Monday' => 'LUNES',
        'Tuesday' => 'MARTES',
        'Wednesday' => 'MIERCOLES',
        'Thursday' => 'JUEVES',
        'Friday' => 'VIERNES',
        'Saturday' => 'SABADO',
        'Sunday' => 'DOMINGO',
    ];

    $weekStartLabel = \Carbon\Carbon::parse($report['week_start'])->format('d/m/Y');
@endphp

@section('content')
<table>
    <thead>
        <tr>
            <th>CONCEPTO</th>
            @foreach ($report['rows'] as $row)
                <th>{{ $row['employee']['full_name'] }}</th>
            @endforeach
            <th>TOTAL</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($report['dates'] as $date)
            @php
                $label = $dayLabels[\Carbon\Carbon::parse($date)->format('l')] ?? strtoupper($date);
                $rowSum = 0;
                $cells = [];
                foreach ($report['rows'] as $r) {
                    $value = $r['days'][$date]['overtime_hours'] + $r['days'][$date]['velada_hours'];
                    $cells[] = $value;
                    $rowSum += $value;
                }
            @endphp
            <tr>
                <td><strong>{{ $label }}</strong> <small>({{ \Carbon\Carbon::parse($date)->format('d/m') }})</small></td>
                @foreach ($cells as $value)
                    <td class="num {{ $value <= 0 ? 'zero' : '' }}">{{ $fmt($value) }}</td>
                @endforeach
                <td class="num">{{ $fmt($rowSum) }}</td>
            </tr>
        @endforeach

        @php
            $rowsForTotals = [
                ['TOTAL', fn ($r) => $r['totals']['total_hours'], 'hours'],
                ['CENA', fn ($r) => $r['totals']['cena_count'], 'count'],
                ['VELADA ' . $weekStartLabel, fn ($r) => $r['totals']['velada_count'], 'count'],
                ['CENA ' . $weekStartLabel, fn ($r) => $r['totals']['cena_count'], 'count'],
                ['FIN DE SEMANA', fn ($r) => $r['totals']['weekend_hours'], 'hours'],
                ['COMIDA', fn ($r) => $r['totals']['comida_count'], 'count'],
            ];
        @endphp

        @foreach ($rowsForTotals as [$label, $extractor, $kind])
            @php
                $sum = 0;
                $cells = [];
                foreach ($report['rows'] as $r) {
                    $value = $extractor($r);
                    $cells[] = $value;
                    $sum += $value;
                }
            @endphp
            <tr class="totals">
                <td>{{ $label }}</td>
                @foreach ($cells as $value)
                    <td class="{{ $kind === 'hours' ? 'num' : 'center' }} {{ ($value <= 0) ? 'zero' : '' }}">
                        {{ $kind === 'hours' ? $fmt($value) : $value }}
                    </td>
                @endforeach
                <td class="{{ $kind === 'hours' ? 'num' : 'center' }}">
                    {{ $kind === 'hours' ? $fmt($sum) : $sum }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

@php
    $obsRows = collect($report['rows'])->filter(fn ($r) => trim($r['observations'] ?? '') !== '')->values();
@endphp
@if ($obsRows->isNotEmpty())
    <h2 style="font-size: 11px; margin-top: 12px;">OBSERVACIONES</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 30%;">EMPLEADO</th>
                <th>NOTA</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($obsRows as $row)
                <tr>
                    <td>{{ $row['employee']['full_name'] }}</td>
                    <td class="obs">{{ $row['observations'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
