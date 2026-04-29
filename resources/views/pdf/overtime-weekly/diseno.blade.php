@extends('pdf.overtime-weekly._layout')

@php
    $fmt = function ($v) {
        if ($v <= 0) return '0';
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    };
@endphp

@section('content')
<table>
    <thead>
        <tr>
            <th rowspan="2">NOMBRE</th>
            @foreach ($report['dates'] as $date)
                <th colspan="2">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</th>
            @endforeach
            <th rowspan="2">TOTAL HORAS</th>
            <th rowspan="2">FIN DE SEMANA</th>
            <th rowspan="2">COMIDA</th>
            <th rowspan="2">VELADA</th>
            <th rowspan="2">CENA</th>
            <th rowspan="2">OBSERVACIONES</th>
        </tr>
        <tr>
            @foreach ($report['dates'] as $date)
                <th>M</th>
                <th>V</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($report['rows'] as $row)
            <tr>
                <td>{{ $row['employee']['full_name'] }}</td>
                @foreach ($report['dates'] as $date)
                    @php $day = $row['days'][$date]; @endphp
                    <td class="num {{ $day['m_hours'] <= 0 ? 'zero' : '' }}">{{ $fmt($day['m_hours']) }}</td>
                    <td class="num {{ $day['v_hours'] <= 0 ? 'zero' : '' }}">{{ $fmt($day['v_hours']) }}</td>
                @endforeach
                <td class="num">{{ $fmt($row['totals']['total_hours']) }}</td>
                <td class="num">{{ $fmt($row['totals']['weekend_hours']) }}</td>
                <td class="center {{ $row['totals']['comida_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['comida_count'] }}</td>
                <td class="center {{ $row['totals']['velada_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['velada_count'] }}</td>
                <td class="center {{ $row['totals']['cena_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['cena_count'] }}</td>
                <td class="obs">{{ $row['observations'] }}</td>
            </tr>
        @endforeach
        <tr class="totals">
            <td>TOTAL</td>
            @foreach ($report['dates'] as $date)
                @php
                    $mSum = 0; $vSum = 0;
                    foreach ($report['rows'] as $r) {
                        $mSum += $r['days'][$date]['m_hours'];
                        $vSum += $r['days'][$date]['v_hours'];
                    }
                @endphp
                <td class="num">{{ $fmt($mSum) }}</td>
                <td class="num">{{ $fmt($vSum) }}</td>
            @endforeach
            <td class="num">{{ $fmt($report['totals']['total_hours']) }}</td>
            <td class="num">{{ $fmt($report['totals']['weekend_hours']) }}</td>
            <td class="center">{{ $report['totals']['comida_count'] }}</td>
            <td class="center">{{ $report['totals']['velada_count'] }}</td>
            <td class="center">{{ $report['totals']['cena_count'] }}</td>
            <td></td>
        </tr>
    </tbody>
</table>
@endsection
