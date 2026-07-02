{{-- Otros conceptos aprobados sin columna fija: "Nombre (conteo)" por línea. --}}
@php($items = $items ?? [])
@if (count($items))
@foreach ($items as $c){{ $c['name'] }} ({{ $c['count'] }})@if (! $loop->last)<br>@endif
@endforeach
@else
—
@endif
