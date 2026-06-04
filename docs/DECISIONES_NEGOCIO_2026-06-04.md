# Decisiones de negocio — Corrección de coherencia (2026-06-04)

Decisiones tomadas por el dueño del producto para resolver las incoherencias de la auditoría
(`AUDITORIA_COHERENCIA_2026-06-04.md`). Estas reglas son la fuente de verdad: **todos los módulos
(sync, incidencias, autorizaciones, nómina, reportes) deben implementar exactamente esto.**

## 1. Retardos → Falta
- **Corte: MENSUAL al cierre.** Los retardos se acumulan por mes calendario; al terminar el mes se convierten en faltas. (Coincide con la propuesta comercial PROPUESTA_SISTEMA_PINKY.md:699-703.)
- **Proporcional:** `floor(retardos_del_mes / umbral)` faltas (12 retardos, umbral 6 → 2 faltas).
- **Auto-aprobada:** la falta generada (incidencia FRT) se crea aprobada, sin paso de supervisor. Se revierte después con una incidencia justificada si procede.
- **Cuándo se cobra:** en el **primer periodo de nómina calculado después del cierre del mes** (retardos de junio → 1ª nómina de julio). Idempotente por (empleado, mes).
- Implicación técnica: la fuente de verdad del conteo es `attendance_records` del mes (consulta directa), NO contadores incrementales — esto elimina de raíz el bug de re-incremento (`processLateAccumulation` no idempotente) y el flag frágil `absence_generated`.
- El mes en curso puede mostrarse en reportes como **proyección**, claramente etiquetada.

## 2. Velada
- **Bono de velada y vale de cena: por noche REAL trabajada + autorizada.** Requiere `velada_hours > 0` en el attendance del día Y autorización night_shift aprobada. **Máximo 1 bono + 1 cena por (empleado, fecha)** sin importar filas duplicadas.
- Con CompensationTypes (`useCompTypes=true`): se **suprime el `night_shift_bonus` legacy** (igual que ya se hace con la cena) — la velada se paga por hora vía VEL.

## 3. Prima vacacional
- **Se paga en nómina junto con cada día de vacación**: `días × sueldo_diario × (vacation_premium_percentage/100)` como **concepto separado** visible en el desglose y exportado a CONTPAQi.

## 4. Incapacidades (sick_leave)
- **Según `is_paid` del tipo:** con goce → se pagan esos días; sin goce → se descuentan. El flag por fin se respeta.

## 5. Faltas reales sin incidencia
- **Descuentan automáticamente:** todo día `absent` sin incidencia aprobada que lo cubra descuenta día completo.
- Regla anti-doble-conteo: un mismo día solo puede descontarse UNA vez (por status absent O por incidencia O por FRT — conciliación a nivel de día).

## 6. Días hábiles vs calendario
- **Configurable por tipo de incidencia** (nueva propiedad `count_mode`: working_days | calendar_days).
- Defaults: vacaciones/permisos → días hábiles; incapacidades → días calendario (estándar IMSS).
- El mismo modo aplica en captura, saldo de vacaciones y nómina — los tres deben coincidir siempre.

## 7. Recálculo de nómina al cambiar datos
- Periodos en **draft/calculating**: **recálculo automático** inmediato al aprobar/rechazar/editar/eliminar incidencias, autorizaciones o checadas del rango.
- Periodos **review/approved (no pagados)**: se marcan **"requiere recálculo"** con alerta; un admin recalcula explícitamente.
- Periodos **paid**: inmutables (como hoy); los cambios de datos quedan permitidos pero NO afectan ese periodo, y debe quedar rastro en auditoría.

## 8. Bonos de asistencia perfecta y puntualidad
- **Lo justificado no rompe el bono:** cualquier día cubierto por incidencia aprobada (vacación, FJU, permiso, incapacidad) no cuenta como falta/retardo para los bonos. Solo faltas/retardos SIN justificar los anulan.

## 9. Alcance de la corrección
- **Cuantificar histórico también:** además de corregir y recalcular los periodos no pagados con las reglas nuevas, generar un **reporte de diferencias de los periodos YA PAGADOS** (recálculo en sombra, sin modificarlos) para decidir ajustes.

## Decisiones derivadas (defaults técnicos, ajustables)
- Reportes de horas extra: mostrar **ambas columnas** (detectadas vs autorizadas/pagadas) con el mismo gate que nómina, para que los totales concilien con el recibo.
- Reporte `payroll`: filtrar periodos `approved/paid` (igual que `payrollTrends`).
- Umbral `late_to_absence_count` siempre desde `system_settings` (eliminar el 6 hardcodeado).
- El sync debe respetar `early_departure_is_absence` (igual que los reportes).
- `holidays:reapply`: solo convertir a `holiday` días donde el empleado tenía jornada, sin checada, y cuyo periodo no esté pagado.
- Edición manual de checadas: reusar el cálculo de métricas del sync (una sola fórmula) y recalcular horas autorizadas.

## 10. Redondeo de horas extra
- **La escalera de redondeo aplica también al PAGO** (<30min→0, 30-49min→0.5h, 50-59min→1h, por segmento de entrada anticipada y salida tardía). Nómina y reportes usan exactamente la misma fórmula redondeada (`OvertimeRoundingService` pasa a ser la única vía, consumida por `VeladaCalculatorService` antes de topar a lo autorizado).
