<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Formato de Tiempo Extra - {{ $report['department']['name'] }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 13px; margin: 0 0 4px; text-align: center; }
        .subtitle { text-align: center; font-size: 10px; margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: middle; }
        thead th { background: #f3f4f6; font-weight: bold; text-align: center; }
        td.num { text-align: right; }
        td.center { text-align: center; }
        td.zero { color: #9ca3af; }
        tr.totals td { background: #fafafa; font-weight: bold; }
        .obs { font-size: 8px; max-width: 180px; }
        .footer { margin-top: 12px; font-size: 8px; color: #555; display: flex; justify-content: space-between; }
        .signature { margin-top: 20px; display: flex; justify-content: space-around; }
        .signature .box { text-align: center; width: 30%; }
        .signature .line { border-top: 1px solid #000; margin-top: 30px; padding-top: 4px; font-size: 8px; }
    </style>
</head>
<body>
    <h1>FORMATO DE TIEMPO EXTRA - {{ strtoupper($report['department']['name']) }}</h1>
    <div class="subtitle">
        SEMANA DEL: {{ \Carbon\Carbon::parse($report['week_start'])->format('d/m/Y') }}
        AL {{ \Carbon\Carbon::parse($report['week_end'])->format('d/m/Y') }}
    </div>

    @yield('content')

    <div class="signature">
        <div class="box"><div class="line">Elabora</div></div>
        <div class="box"><div class="line">Jefe de Departamento</div></div>
        <div class="box"><div class="line">RRHH</div></div>
    </div>

    <div class="footer">
        <span>Generado: {{ now()->format('d/m/Y H:i') }}</span>
        <span>Pinky ERP</span>
    </div>
</body>
</html>
