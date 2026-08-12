<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Formato Individual de Vacaciones</title>
    <style>
        /* Media carta (8.5 × 5.5 in): tipografía compacta para que quepa todo. */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; padding: 18px 24px; }
        .brand { font-size: 13px; font-weight: bold; color: #db2777; }
        .title { text-align: center; font-size: 11px; font-weight: bold; margin: 6px 0 10px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.meta td { padding: 2px 4px; vertical-align: top; }
        .lbl { font-weight: bold; }
        .box { border: 1px solid #999; padding: 5px 8px; margin-bottom: 8px; }
        .dates span { display: inline-block; min-width: 70px; border-bottom: 1px dotted #666; margin: 2px 8px 2px 0; text-align: center; }
        table.nums { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.nums td { padding: 2px 4px; }
        table.sign { width: 100%; border-collapse: collapse; margin-top: 26px; }
        table.sign td { width: 33%; text-align: center; padding: 0 12px; }
        .line { border-top: 1px solid #111; padding-top: 3px; font-weight: bold; font-size: 8px; }
    </style>
</head>
<body>
    <table class="meta">
        <tr>
            <td class="brand">Vestidos Pinky</td>
            <td style="text-align: right;"><span class="lbl">Fecha de Solicitud:</span> {{ $solicitud }}</td>
        </tr>
    </table>

    <div class="title">FORMATO INDIVIDUAL DE VACACIONES</div>

    <table class="meta">
        <tr>
            <td><span class="lbl">Nombre del Empleado:</span> {{ $employee->full_name }}</td>
            <td style="text-align: right;"><span class="lbl">No. Empleado:</span> {{ $employee->employee_number }}</td>
        </tr>
        <tr>
            <td><span class="lbl">Departamento:</span> {{ strtoupper($employee->department?->name ?? '—') }}</td>
            <td style="text-align: right;"><span class="lbl">Fecha de Ingreso:</span> {{ $ingreso }}</td>
        </tr>
    </table>

    <div class="box dates">
        <span class="lbl">FECHA DE LAS VACACIONES:</span><br>
        @foreach ($dates as $d)
            <span>{{ $d->format('d/m/Y') }}</span>
        @endforeach
    </div>

    <table class="nums">
        <tr>
            <td><span class="lbl">Fecha Inicio Vacaciones:</span> {{ $inicio }}</td>
            <td><span class="lbl">Fecha Fin Vacaciones:</span> {{ $fin }}</td>
        </tr>
        <tr>
            <td><span class="lbl">Días que corresponden:</span> {{ $corresponden }}</td>
            <td><span class="lbl">Días que toma:</span> {{ $toma }}</td>
        </tr>
        <tr>
            <td><span class="lbl">Días tomados anteriores:</span> {{ $anteriores }}</td>
            <td><span class="lbl">Días Pendientes:</span> {{ $pendientes }}</td>
        </tr>
    </table>

    <table class="sign">
        <tr>
            <td><div class="line">EMPLEADO</div></td>
            <td><div class="line">JEFE DE DEPARTAMENTO</div></td>
            <td><div class="line">AUTORIZACIÓN</div></td>
        </tr>
    </table>
</body>
</html>
