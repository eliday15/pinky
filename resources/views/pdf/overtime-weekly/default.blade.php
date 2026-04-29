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
            <th>NOMBRE</th>
            @foreach ($report['dates'] as $date)
                <th>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</th>
            @endforeach
            <th>TOTAL HORAS</th>
            <th>FIN DE SEMANA</th>
            <th>COMIDA</th>
            <th>VELADA</th>
            <th>CENA</th>
            <th>OBSERVACIONES</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['employee']['full_name'] }}</td>
                @foreach ($report['dates'] as $date)
                    @php $extra = $row['days'][$date]['overtime_hours'] + $row['days'][$date]['velada_hours']; @endphp
                    <td class="num {{ $extra <= 0 ? 'zero' : '' }}">{{ $fmt($extra) }}</td>
                @endforeach
                <td class="num">{{ $fmt($row['totals']['total_hours']) }}</td>
                <td class="num">{{ $fmt($row['totals']['weekend_hours']) }}</td>
                <td class="center {{ $row['totals']['comida_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['comida_count'] }}</td>
                <td class="center {{ $row['totals']['velada_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['velada_count'] }}</td>
                <td class="center {{ $row['totals']['cena_count'] === 0 ? 'zero' : '' }}">{{ $row['totals']['cena_count'] }}</td>
                <td class="obs">{{ $row['observations'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($report['dates']) + 7 }}" class="center">Sin empleados activos en este departamento.</td>
            </tr>
        @endforelse

        @if (count($report['rows']) > 0)
            <tr class="totals">
                <td>TOTAL</td>
                @foreach ($report['dates'] as $date)
                    @php
                        $colSum = 0;
                        foreach ($report['rows'] as $r) {
                            $colSum += $r['days'][$date]['overtime_hours'] + $r['days'][$date]['velada_hours'];
                        }
                    @endphp
                    <td class="num">{{ $fmt($colSum) }}</td>
                @endforeach
                <td class="num">{{ $fmt($report['totals']['total_hours']) }}</td>
                <td class="num">{{ $fmt($report['totals']['weekend_hours']) }}</td>
                <td class="center">{{ $report['totals']['comida_count'] }}</td>
                <td class="center">{{ $report['totals']['velada_count'] }}</td>
                <td class="center">{{ $report['totals']['cena_count'] }}</td>
                <td></td>
            </tr>
        @endif
    </tbody>
</table>
@endsection
