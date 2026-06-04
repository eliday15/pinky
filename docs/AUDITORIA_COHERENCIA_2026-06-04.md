# Auditoría de coherencia: Incidencias ↔ Autorizaciones ↔ Nómina ↔ Reportes

**Fecha:** 2026-06-04
**Método:** Auditoría multi-agente (199 agentes, 2,325 lecturas de código). 6 mapeos de módulo, 7 cruces de coherencia, verificación adversarial de cada hallazgo con 2 verificadores independientes, más ronda de completitud dirigida.
**Resultado:** 73 hallazgos confirmados (ambos verificadores los validaron contra el código), 17 probables (verificadores divididos), 0 descartados.

---

## Veredicto general

**El sistema NO es coherente entre módulos.** Los cuatro módulos funcionan razonablemente bien por separado, pero las reglas de negocio están **implementadas varias veces en lugares distintos, con fórmulas distintas**, y los datos precalculados de nómina **no se invalidan** cuando algo cambia después. El resultado práctico: lo que muestra un reporte casi nunca cuadra exactamente con lo que paga la nómina, y hay conceptos que se pagan doble o no se pagan/descuentan nunca.

### Las 3 causas raíz (todo lo demás deriva de aquí)

1. **Reglas duplicadas con fórmulas distintas.** La conversión "retardos → falta" existe en 3 lugares con 3 fórmulas; las horas extra se calculan de 3 formas; las horas trabajadas de 2 formas. Cada consumidor inventó su propia versión.
2. **Nómina precalculada sin invalidación.** `payroll_entries` se calcula una vez. Aprobar/rechazar/eliminar una incidencia o autorización, o editar una checada después, **no recalcula nada** — y un periodo `paid` es inmutable.
3. **Los reportes leen `attendance_records` crudo**, sin pasar por las mismas reglas de justificación (incidencias) y autorización que sí aplica la nómina.

---

## CRÍTICOS — dinero que se paga mal HOY

### C1. Faltas por retardos: 3 fórmulas distintas + flag compartido frágil
La regla "N retardos = 1 falta" está implementada en **tres lugares incompatibles**:

| Lugar | Granularidad | Fórmula | Evidencia |
|---|---|---|---|
| Sync ZKTeco | Semana ISO | 1 incidencia FRT de 1 día al cruzar umbral (aunque haya 12 retardos) | `app/Services/ZktecoSyncService.php:847-917` |
| Nómina | Semana ISO del `start_date` del periodo **solamente** | `floor(late_count/umbral)` faltas, sin crear incidencia | `app/Services/PayrollCalculatorService.php:358-385` |
| Reportes (web y CSV) | **Mes calendario** | `intdiv(retardos_del_mes, umbral)` | `app/Http/Controllers/AttendanceReportController.php:99-123`, `ReportExportController.php:325-342` |

Además, ambos caminos de descuento comparten el flag `late_accumulations.absence_generated`: **el que corre primero deshabilita al otro**. El número de faltas descontadas depende del orden de ejecución (sync vs cálculo de nómina), no de una regla determinista (`PayrollCalculatorService.php:375-381`, `LateAccumulation.php:46-51`).

Agravantes confirmados:
- `calculateLateAbsences` solo mira **una semana ISO** (la del `start_date`). Un periodo Mié–Mar o quincenal cruza 2+ semanas ISO: los retardos de las otras semanas **nunca se descuentan** (`PayrollCalculatorService.php:363-366`).
- Si el tipo FRT requiere aprobación, la incidencia queda `pending`, la nómina solo lee `approved`, y el flag ya quedó marcado: **el descuento se pierde en silencio** (`ZktecoSyncService.php:898-913` + `PayrollCalculatorService.php:74-85`).
- **Aprobar una autorización re-incrementa `late_count`** del mismo día: `approve()` → `recalculateAttendanceRecord()` → `processLateAccumulation()` → `incrementLate()` sin idempotencia por fecha. Retardos fantasma → faltas que el empleado nunca cometió (`AuthorizationController.php:722-734`, `ZktecoSyncService.php:835-866,922-925`).
- El tipo FRT está **desactivado por migración** pero el sync lo sigue usando, y la migración `000007` lo dejó `is_paid=true` (contradice su propósito).
- `ReportController::lateArrivals` usa umbral **hardcodeado en 6** en vez del setting `late_to_absence_count`.

### C2. Lo justificado no surte efecto: reportes y bonos castigan faltas ya justificadas
- **Los reportes de faltas/retardos/salidas tempranas no consultan `incidents` jamás.** Una Falta Justificada (FJU) aprobada sigue apareciendo como falta en el reporte que RR.HH. usa para sancionar (`AttendanceReportController.php:52-97`, `ReportExportController.php:301-393`). Solo PSA/PEN con `affects_attendance` modifican el `attendance_record`, y solo durante el sync.
- **Aprobar una incidencia NO recalcula el `attendance_record`** (a diferencia de aprobar una autorización, que sí lo hace). Un permiso aprobado tarde no revierte la falta ni suma `permission_hours`: la nómina sigue descontando el día (`IncidentController.php:517-548` vs `AuthorizationController.php:722-734`).
- **Los bonos de asistencia perfecta (semanal y mensual) solo miran `status` crudo** (`absent`/`late`): una vacación o falta justificada aprobada igual anula el bono (`PayrollCalculatorService.php:404-441`).
- El reporte de salidas tempranas cuenta "faltas" **sin verificar permiso PSA aprobado**, que el sync y el detector de anomalías sí respetan (`AttendanceReportController.php:368-387` vs `ZktecoSyncService.php:805-809`).

### C3. Horas extra: el reporte muestra una cosa, la nómina paga otra
- La nómina paga **solo horas autorizadas**: `min(detectadas, autorizadas aprobadas)` (`VeladaCalculatorService.php:126-133`, `PayrollCalculatorService.php:171-218`).
- El reporte de horas extra (pantalla y CSV) usa `overtime_hours` **crudo, sin gate de autorización** (`ReportController.php:276-313`, `ReportExportController.php:271-296`): muestra horas pendientes/rechazadas como si se fueran a pagar.
- El costo estimado del reporte usa **tarifa fija ×1.5**, mientras la nómina con CompensationTypes paga por escalones HE/HED resueltos por empleado→puesto→depto→global (`CompensationRateResolverService.php:158-211`).
- El reporte semanal suma `Authorization.hours` **sin topar a lo realmente trabajado** — la nómina sí lo topa (`WeeklyOvertimeReportService.php:216-240`).
- El reporte semanal además recalcula "detectadas" con la **escalera de redondeo** de `OvertimeRoundingService` (<30→0, 30-49→0.5h, 50-59→1h), que la nómina **no usa** para pagar (`OvertimeRoundingService.php:26-89` vs `VeladaCalculatorService.php:66-123`).
- Resultado: **tres cifras distintas de horas extra para el mismo periodo** (reporte CSV, reporte semanal, nómina).

### C4. Velada: doble pago confirmado
- **Bono de velada y vale de cena se pagan por CONTEO de filas** de autorización `night_shift` aprobadas — sin validar que exista velada real ni checada ese día, y `storeBulk` puede crear filas duplicadas por (empleado, fecha) sin deduplicar: cada fila duplicada = otro bono + otra cena (`PayrollCalculatorService.php:452-472`, `AuthorizationController.php:499-569`).
- **Con CompensationTypes (VEL) hay doble pago estructural:** la velada se paga por hora vía VEL y *además* se suma el `night_shift_bonus` legacy incondicionalmente. El código suprime la cena (`useCompTypes ? 0.0`) pero **olvidó suprimir el bono** (`PayrollCalculatorService.php:200-201, 220-228`).

### C5. Dinero que nunca llega (o nunca se descuenta)
- **Prima vacacional** (`vacation_premium_percentage`, default 25%): capturada, validada, importada, exportada en el perfil del empleado… y **jamás usada en el cálculo**. Vacaciones se pagan a sueldo simple. Tampoco existe el concepto en la exportación CONTPAQi (`PayrollCalculatorService.php:164`).
- **Incapacidades (sick_leave): efecto monetario nulo.** Se cuentan en `sick_leave_days` pero no entran en ningún bucket de pago ni de descuento; el flag `is_paid` del tipo INC se ignora por completo (`PayrollCalculatorService.php:645-647`).
- **Las faltas reales (status `absent` sin incidencia) NO se descuentan.** La deducción solo usa `unpaid_days` (incidencias) + `lateAbsences`; `days_absent` se persiste y se reporta, pero no genera deducción: el reporte de faltas no cuadra con lo descontado (`PayrollCalculatorService.php:133-134, 545`).

---

## ALTOS — inconsistencias estructurales

### A1. Datos precalculados que quedan obsoletos (sin invalidación)
Nada de lo siguiente dispara recálculo de nómina ni invalida `payroll_entries`:
- Aprobar/rechazar/editar/**eliminar** una incidencia (`IncidentController.php:497-512`).
- Rechazar una autorización previamente aprobada (probable: la nómina sigue pagando esas horas).
- Editar manualmente una checada (`AttendanceController.php:324-328` — deliberadamente no recalcula).
- Resolver o vincular una anomalía (`AnomalyResolutionController.php:289-291`).

Y un periodo `paid` es inmutable (`PayrollCalculatorService.php:37-39`): la inconsistencia se vuelve **permanente**.

### A2. La edición manual de checadas tiene su propia fórmula
`AttendanceController::update` recalcula `worked_hours`/`overtime_hours` con lógica propia (jornada fija de 8h, sin velada, sin permisos, sin late) y **no toca** `overtime_authorized_hours`/`velada_authorized_hours` — los campos que la nómina realmente paga. Corregir una checada para reducir horas extra **no reduce el pago** (`AttendanceController.php:343-383` vs `ZktecoSyncService.php:816-821`).

### A3. Días calendario vs días hábiles
La captura de incidencias y el saldo de vacaciones cuentan **días hábiles** (`IncidentController::calculateWorkingDays:256-295`); la nómina cuenta el solape en **días calendario** (`diffInDays+1`, `PayrollCalculatorService.php:626-660`). Vacaciones L–D: descuentan 5 días del saldo, la nómina paga 7.

### A4. Reportes con filtros de estado inconsistentes
- Reporte `payroll` muestra montos de periodos `draft`/`calculating` como nómina válida; `payrollTrends` del mismo controlador sí filtra `approved/paid` (`ReportController.php:236-262` vs `673-681`).
- Reporte de incidencias y su export **mezclan pendientes y rechazadas** en los mismos totales que la nómina restringe a aprobadas.
- Asistencia perfecta: la versión web excluye festivos del esperado, **el CSV no** — dos resultados para "el mismo reporte".
- `DIAS_AUSENCIA` exportado a CONTPAQi no coincide con la deducción realmente cobrada.
- El sync ignora el flag `early_departure_is_absence` que los reportes sí respetan: con el flag apagado, la nómina descuenta días que el negocio decidió no considerar falta (`ZktecoSyncService.php:805-809`).

### A5. Doble vía incidencias/autorizaciones sin validación cruzada
El control de solapamiento de incidencias solo mira otras incidencias; las autorizaciones no verifican incidencias. Un mismo día puede descontarse como falta **y** pagarse horas extra/velada autorizadas (`IncidentController.php:199-209`).

### A6. `holidays:reapply` puede borrar faltas reales (probable, severidad crítica)
Convierte cualquier `status='absent'` en `holiday` en fecha festiva, sin verificar si el empleado debía trabajar, si checó, ni si el periodo ya se pagó.

---

## Qué SÍ está coherente

Para ser justos, la auditoría también confirmó zonas sanas:
- El flujo **autorización aprobada → recálculo del attendance → horas autorizadas → pago** es internamente consistente (cuando no se edita nada después).
- El gate de estados `approved/paid` en nómina para autorizaciones se aplica de forma consistente en todos los caminos de cálculo.
- La política de permisos de autorizaciones (ver/crear/aprobar, no auto-aprobarse) está bien aplicada.
- El detector de anomalías respeta permisos PSA/PEN igual que el sync.

---

## Plan de corrección recomendado (por prioridad)

1. **Unificar "retardos → falta" en UN solo servicio** con una sola granularidad (decidir: ¿semana ISO o semana de nómina o mes?), llamado por sync, nómina y reportes. Eliminar la fórmula propia de cada consumidor y separar el flag `absence_generated` de los dos caminos (o eliminar uno de los dos caminos).
2. **Hacer idempotente `processLateAccumulation`** (clave por fecha, no incremento ciego) — esto está generando faltas fantasma hoy.
3. **Suprimir `night_shift_bonus` cuando `useCompTypes=true`** (1 línea, igual que ya se hace con la cena) y pagar bono/cena de velada solo si hubo velada real (`velada_hours > 0` en el attendance del día), deduplicando autorizaciones por (empleado, fecha, tipo).
4. **Aplicar la prima vacacional** en `vacationPay` y decidir el tratamiento monetario de `sick_leave` y de `days_absent` sin incidencia (descontar o documentar por qué no).
5. **Invalidación de nómina:** cualquier cambio en incidencias/autorizaciones/checadas de un periodo no-pagado debe marcar el periodo para recálculo (y bloquear cambios sobre periodos `paid`, en todos los módulos, no solo en nómina).
6. **Reportes sobre la misma fuente de verdad:** los reportes de faltas/retardos/HE deben consultar incidencias aprobadas y autorizaciones igual que la nómina (idealmente extraer las reglas a servicios compartidos y que reportes y nómina los llamen).
7. **Unificar el cálculo de horas al editar checadas** (reusar `calculateAttendanceMetrics` del sync en vez de la fórmula propia del controlador).
8. Revisión puntual: umbral hardcodeado en `lateArrivals`, filtro de status en reporte `payroll`, festivos en CSV de asistencia perfecta, `holidays:reapply`, operador `>` vs `>=` en salida temprana.

---

## Apéndice: listado completo de hallazgos (90)

Severidad: crítica > alta > media > baja. "CONFIRMADO" = ambos verificadores adversariales lo validaron leyendo el código; "PROBABLE" = verificadores divididos.

| # | Estado | Severidad | Hallazgo | Evidencia |
|---|--------|-----------|----------|-----------|
| 1 | CONFIRMADO | critica | Falta por retardos contada con TRES granularidades distintas (sync semanal, nómina semana-del-inicio, reporte mensual): el descuento de nómina no cuadra con el reporte | app/Services/ZktecoSyncService.php:847-917; app/Services/PayrollCalculatorService.php:358-385; app/Http/Controllers/AttendanceReportController.php:99-123 |
| 2 | CONFIRMADO | critica | El flag absence_generated es compartido por sync y nómina: el que corre primero deshabilita al otro, provocando subconteo o falta no descontada | app/Services/PayrollCalculatorService.php:375-381; app/Models/LateAccumulation.php:46-51; app/Services/ZktecoSyncService.php:910-914 |
| 3 | CONFIRMADO | critica | Reportes de faltas/retardos no consultan incidencias: una FALTA JUSTIFICADA (FJU) aprobada sigue contando como falta/retardo en los reportes | app/Services/ZktecoSyncService.php:766-783; app/Http/Controllers/AttendanceReportController.php:52-58, 91-97; database/seeders/IncidentTypesSeeder.php:61-68 |
| 4 | CONFIRMADO | critica | El reporte de horas extra (pantalla y CSV) ignora las autorizaciones: muestra horas que nomina NO paga | app/Http/Controllers/ReportController.php:276-313; app/Http/Controllers/ReportExportController.php:271-296; app/Services/PayrollCalculatorService.php:171-218 |
| 5 | CONFIRMADO | critica | Doble conteo / doble descuento de faltas por retardo: incidencia FRT (sync) vs calculateLateAbsences (nómina) con fórmulas distintas y flag compartido frágil | app/Services/PayrollCalculatorService.php:133-134; app/Services/PayrollCalculatorService.php:375-381; app/Services/PayrollCalculatorService.php:654-657 |
| 6 | CONFIRMADO | critica | Aprobar una incidencia (PSA/PEN u otra que afecta asistencia) NO recalcula el attendance_record; la falta/retardo ya marcada por el sync persiste | app/Http/Controllers/IncidentController.php:517-548; app/Http/Controllers/AuthorizationController.php:722-734; app/Services/ZktecoSyncService.php:766-809 |
| 7 | CONFIRMADO | critica | Bono de velada y vale de cena se pagan por CONTEO de autorizaciones night_shift, desacoplados de horas/checadas reales (doble conteo por fila duplicada) | app/Services/PayrollCalculatorService.php:452-472; app/Services/PayrollCalculatorService.php:481-498; app/Http/Controllers/AuthorizationController.php:499-569 |
| 8 | CONFIRMADO | critica | Con CompensationTypes (VEL) la velada se paga por hora Y ademas se suma el night_shift_bonus legacy (doble pago del mismo concepto) | app/Services/PayrollCalculatorService.php:220-228; app/Services/PayrollCalculatorService.php:200-201 |
| 9 | PROBABLE | critica | calculateLateAbsences solo mira la semana del start_date del periodo; pierde retardos de las demás semanas del periodo de nómina | app/Services/PayrollCalculatorService.php:360-366; app/Services/ZktecoSyncService.php:849-850 |
| 10 | PROBABLE | critica | Faltas por retardo calculadas en TRES caminos con periodicidad y fórmula distintas (semana ISO vs mes), con doble conteo posible en nómina | app/Services/ZktecoSyncService.php:847-917; app/Services/PayrollCalculatorService.php:358-385; app/Services/PayrollCalculatorService.php:132-134 |
| 11 | PROBABLE | critica | holidays:reapply borra faltas reales convirtiéndolas en 'holiday' sin verificar día laborable, check-in ni periodo pagado | app/Console/Commands/ReapplyHolidays.php:36-62; app/Services/PayrollCalculatorService.php:533-544; app/Services/ZktecoSyncService.php:800-802 |
| 12 | PROBABLE | critica | Doble conteo de la falta por acumulación de retardos (incident FRT + calculateLateAbsences) y fuente de verdad ambigua | app/Services/ZktecoSyncService.php:889-913; database/seeders/IncidentTypesSeeder.php:70-78; app/Services/PayrollCalculatorService.php:654-658 |
| 13 | CONFIRMADO | alta | Días de incidencia: nómina descuenta/paga días CALENDARIO pero el saldo de vacaciones y la captura usan días HÁBILES | app/Http/Controllers/IncidentController.php:256-295; app/Http/Controllers/IncidentController.php:237-241, 517-548; app/Services/PayrollCalculatorService.php:626-660 |
| 14 | CONFIRMADO | alta | El sync acumula retardos que terminaron como FALTA (absent), pero el reporte de retardos solo cuenta status='late': la base de faltas-por-retardo difiere | app/Services/ZktecoSyncService.php:834-837; app/Http/Controllers/AttendanceReportController.php:91-107; app/Http/Controllers/AttendanceReportController.php:73-85 |
| 15 | CONFIRMADO | alta | Cambios en incidencias después de calcular la nómina no la recalculan; un periodo pagado queda permanentemente inconsistente | app/Http/Controllers/IncidentController.php:497-512; app/Http/Controllers/AttendanceController.php:324-328; app/Services/PayrollCalculatorService.php:37-39 |
| 16 | CONFIRMADO | alta | Horas extra: el reporte de overtime exporta horas SIN gate de autorización, la nómina solo paga las autorizadas (tres cifras distintas para el mismo periodo) | app/Http/Controllers/ReportExportController.php:279-294; app/Services/Reports/WeeklyOvertimeReportService.php:216-218, 239-240; app/Services/VeladaCalculatorService.php:126-133 |
| 17 | CONFIRMADO | alta | Bono de velada y vale de cena se pagan por fila de autorización night_shift aprobada, desacoplados de las horas/checadas reales | app/Services/PayrollCalculatorService.php:452-472; app/Services/PayrollCalculatorService.php:139, 178, 200-201; app/Services/VeladaCalculatorService.php:110-133 |
| 18 | CONFIRMADO | alta | Editar manualmente una checada recalcula worked_hours/overtime_hours pero NO overtime_authorized_hours ni velada_authorized_hours: la nómina paga horas extra obsoletas | app/Http/Controllers/AttendanceController.php:343-377, 383; app/Services/ZktecoSyncService.php:816-821; app/Services/PayrollCalculatorService.php:171, 487-497 |
| 19 | CONFIRMADO | alta | Faltas por retardo recalculadas con granularidad distinta en cada módulo (semana en sync/nómina vs mes en reportes), produciendo conteos divergentes | app/Services/ZktecoSyncService.php:847-871; app/Http/Controllers/AttendanceReportController.php:105-123; app/Services/PayrollCalculatorService.php:363-381 |
| 20 | CONFIRMADO | alta | Reportes diario/semanal/mensual suman festivo y fin de semana sin distinguir autorizado vs no autorizado (nomina si lo distingue) | app/Services/PayrollCalculatorService.php:565-596; app/Http/Controllers/ReportController.php:108-152; app/Http/Controllers/ReportController.php:157-227 |
| 21 | CONFIRMADO | alta | Reporte semanal de overtime suma 'hours' de la autorizacion sin acotarlas a lo realmente trabajado; nomina si las acota al timecard | app/Services/Reports/WeeklyOvertimeReportService.php:213-240; app/Services/CompensationRateResolverService.php:260-292; app/Services/VeladaCalculatorService.php:126-133 |
| 22 | CONFIRMADO | alta | El reporte 'payroll' lee payroll_entries SIN filtrar por status del periodo: muestra montos de periodos en draft/calculating como si fueran nómina válida | app/Http/Controllers/ReportController.php:236-262; app/Http/Controllers/ReportController.php:673-681; app/Services/PayrollCalculatorService.php:41,52 |
| 23 | CONFIRMADO | alta | El reporte de Horas Extra (overtime) estima costo con overtime_hours crudo * x1.5, pero la nómina paga sobre horas AUTORIZADAS con tarifas CompensationType (HE/HED por escalón) | app/Http/Controllers/ReportController.php:286-298; app/Services/PayrollCalculatorService.php:171,177,213; app/Services/CompensationRateResolverService.php:158-211 |
| 24 | CONFIRMADO | alta | El reporte semanal/overtime de Reportes suma overtime_hours CRUDO, pero la nómina lo gating por autorización (festivo/fin de semana sin auth NO se paga) | app/Http/Controllers/ReportController.php:132-133,296,512-513; app/Services/PayrollCalculatorService.php:565-592; app/Services/PayrollCalculatorService.php:171,177 |
| 25 | CONFIRMADO | alta | Faltas por retardo: la nómina acumula por SEMANA (late_accumulations) y el reporte de faltas las recalcula por MES con otra fuente y otro corte temporal | app/Services/PayrollCalculatorService.php:358-385; app/Http/Controllers/AttendanceReportController.php:99-123; app/Http/Controllers/ReportExportController.php:328-342 |
| 26 | CONFIRMADO | alta | El reporte de faltas cuenta retardos->falta por MES con intdiv, distinto del sync (semanal, 1 incidencia) y de nómina (floor por semana del start_date) | app/Http/Controllers/AttendanceReportController.php:105-113; app/Services/PayrollCalculatorService.php:365-376; app/Services/ZktecoSyncService.php:849-850 |
| 27 | CONFIRMADO | alta | Las faltas por inasistencia (status='absent') se reportan pero NO se descuentan monetariamente en nómina | app/Services/PayrollCalculatorService.php:133-134; app/Services/PayrollCalculatorService.php:545,608,325; app/Http/Controllers/AttendanceReportController.php:52-86 |
| 28 | CONFIRMADO | alta | El reporte de salidas tempranas cuenta 'faltas' sin verificar permiso PSA aprobado, a diferencia del sync que sí lo exime | app/Http/Controllers/AttendanceReportController.php:368-387; app/Services/ZktecoSyncService.php:805-809; app/Services/AnomalyDetectorService.php:420-428 |
| 29 | CONFIRMADO | alta | Coexistencia de incidencia activa y autorización aprobada por el mismo evento (doble vía); la validación de solapamiento no cruza módulos | app/Http/Controllers/IncidentController.php:199-209; app/Services/PayrollCalculatorService.php:91-100,171-184; app/Services/VeladaCalculatorService.php:125-153 |
| 30 | CONFIRMADO | alta | Reporte CSV de horas extra muestra horas BRUTAS sin autorizar; nómina solo paga las autorizadas | app/Http/Controllers/ReportExportController.php:279,293; app/Services/PayrollCalculatorService.php:171,177,316; app/Services/Reports/WeeklyOvertimeReportService.php:27-29,84-89 |
| 31 | CONFIRMADO | alta | Salida temprana excesiva: el sync ignora el flag early_departure_is_absence que SÍ respetan los reportes | app/Services/ZktecoSyncService.php:805-809; app/Http/Controllers/AttendanceReportController.php:45,77,371-373; app/Http/Controllers/ReportExportController.php:309 |
| 32 | CONFIRMADO | alta | Incapacidad (sick_leave) nunca se paga ni se deduce: el flag is_paid se ignora | app/Services/PayrollCalculatorService.php:645-647; app/Services/PayrollCalculatorService.php:231-233; database/seeders/IncidentTypesSeeder.php:26-33 |
| 33 | CONFIRMADO | alta | Periodo de nómina (rango libre / Mié-Mar) desalineado con la acumulación de retardos por semana ISO | app/Services/PayrollCalculatorService.php:363-366; app/Http/Controllers/PayrollController.php:90-92; app/Services/ZktecoSyncService.php:849-850 |
| 34 | CONFIRMADO | alta | Prima vacacional (vacation_premium_percentage) se captura por empleado pero NUNCA se aplica en la nómina ni se exporta a CONTPAQi | app/Services/PayrollCalculatorService.php:154,164; app/Services/PayrollCalculatorService.php:232,297,336; app/Models/Employee.php:84,97 |
| 35 | CONFIRMADO | alta | Bonos de asistencia perfecta (semanal y mensual) ignoran incidencias aprobadas: una falta/retardo justificado igual anula el bono | app/Services/PayrollCalculatorService.php:404-415; app/Services/PayrollCalculatorService.php:434-441; app/Services/PayrollCalculatorService.php:168-169 |
| 36 | CONFIRMADO | alta | Bono de puntualidad se congela en el sync y no respeta permisos/incidencias aprobadas despues | app/Services/PayrollCalculatorService.php:167; app/Services/PayrollCalculatorService.php:554-557; app/Services/ZktecoSyncService.php:693-694,793,808,827 |
| 37 | CONFIRMADO | alta | Divergencia de clave de semana entre sync (late_accumulations) y nomina, con riesgo de doble descuento o descuento perdido por retardos | app/Services/ZktecoSyncService.php:850; app/Services/PayrollCalculatorService.php:363-366; app/Services/PayrollCalculatorService.php:132-134 |
| 38 | CONFIRMADO | alta | La cena (dinner_allowance) y el bono de velada nunca llegan como columna propia a CONTPAQi; solo via el agregado BONOS | app/Exports/ContpaqiPrenominaExport.php:82-90; app/Services/PayrollCalculatorService.php:226-228 |
| 39 | CONFIRMADO | alta | El reporte semanal de horas extra suma horas de autorizacion SIN capar al timecard, mientras nomina si capa; los numeros divergen | app/Services/Reports/WeeklyOvertimeReportService.php:215-221; app/Services/VeladaCalculatorService.php:129-134; app/Services/CompensationRateResolverService.php:265-267 |
| 40 | CONFIRMADO | alta | Editar checadas o eliminar/editar autorizaciones tras calcular la nomina no recalcula los payroll_entries: quedan obsoletos | app/Http/Controllers/AuthorizationController.php:676-688; app/Http/Controllers/AuthorizationController.php:720-734; app/Services/PayrollCalculatorService.php:33-53 |
| 41 | CONFIRMADO | alta | Retardos→falta se cuenta con ventanas distintas en nómina (semana) vs reportes (mes / rango): los números nunca cuadran | app/Services/ZktecoSyncService.php:847-872; app/Services/PayrollCalculatorService.php:358-385; app/Http/Controllers/AttendanceReportController.php:105-113 |
| 42 | CONFIRMADO | alta | calculateLateAbsences usa solo UNA semana ISO del inicio del periodo: pierde o duplica faltas-por-retardos según las fechas del periodo de nómina | app/Services/PayrollCalculatorService.php:360-366; app/Services/PayrollCalculatorService.php:375-381; app/Services/ZktecoSyncService.php:849-850 |
| 43 | CONFIRMADO | alta | Falta-por-retardos (FRT) generada como incidencia 'pending' cuando el tipo requiere aprobación: el descuento se pierde silenciosamente | app/Services/ZktecoSyncService.php:898-913; app/Services/PayrollCalculatorService.php:74-85; app/Services/PayrollCalculatorService.php:654-658 |
| 44 | CONFIRMADO | alta | Editar una checada manualmente NO recalcula late_minutes, qualifies_for_punctuality_bonus, velada ni overtime autorizado: la nómina paga con métricas obsoletas | app/Http/Controllers/AttendanceController.php:344-394; app/Services/PayrollCalculatorService.php:554-556; app/Services/PayrollCalculatorService.php:487-490 |
| 45 | CONFIRMADO | alta | Aprobar una autorización reincrementa late_count del mismo día (recalculateAttendanceRecord vuelve a llamar processLateAccumulation) | app/Services/ZktecoSyncService.php:835-866; app/Services/ZktecoSyncService.php:922-925; app/Http/Controllers/AuthorizationController.php:722-734 |
| 46 | CONFIRMADO | alta | Días de incidencia: el módulo cuenta días hábiles (sin fin de semana/festivo) pero nómina deduce días calendario | app/Http/Controllers/IncidentController.php:256-294; app/Services/PayrollCalculatorService.php:630-658 |
| 47 | CONFIRMADO | alta | El reporte de horas extra recalcula con escalera de redondeo distinta a la fórmula de pago de nómina | app/Services/OvertimeRoundingService.php:26-89; app/Services/Reports/WeeklyOvertimeReportService.php:235-249; app/Services/VeladaCalculatorService.php:66-123 |
| 48 | CONFIRMADO | alta | Edición manual de checada no recalcula velada/late/early ni revierte la acumulación de retardos | app/Http/Controllers/AttendanceController.php:343-394; app/Services/PayrollCalculatorService.php:487-490 |
| 49 | PROBABLE | alta | Riesgo de doble penalización: la incidencia FRT (categoría late_accumulation) ya genera unpaid_days y calculateLateAbsences puede sumar faltas extra sobre la misma acumulación | database/seeders/IncidentTypesSeeder.php:70-78; app/Services/PayrollCalculatorService.php:654-658; app/Services/PayrollCalculatorService.php:132-134 |
| 50 | PROBABLE | alta | Rechazar una autorización previamente aprobada no recalcula el attendance: la nómina sigue pagando horas de una autorización ya rechazada | app/Http/Controllers/AuthorizationController.php:722-734; app/Http/Controllers/AuthorizationController.php:792-808, 864-893; app/Services/PayrollCalculatorService.php:487-497 |
| 51 | PROBABLE | alta | Doble conteo de faltas por retardo en el reporte de ausencias: el mismo evento se cuenta como registro 'absent' Y como incidencia FRT | app/Http/Controllers/ReportController.php:349-372; app/Services/ZktecoSyncService.php:898-908; app/Services/PayrollCalculatorService.php:654-657 |
| 52 | PROBABLE | alta | Salida temprana convierte el día en falta completa en nómina aunque el empleado trabajó casi toda la jornada | app/Services/PayrollCalculatorService.php:533-548; app/Services/PayrollCalculatorService.php:132-134; app/Services/ZktecoSyncService.php:805-809 |
| 53 | PROBABLE | alta | Periodo de nómina biweekly/semanal lee solo UNA semana de retardos (weekOfYear del start) — faltas por retardo no deducidas | app/Services/PayrollCalculatorService.php:360-366; app/Services/PayrollCalculatorService.php:112-113; app/Http/Controllers/PayrollController.php:87-94 |
| 54 | CONFIRMADO | media | El reporte de retardos marca 'genera falta' por periodo arbitrario, ignorando el corte semanal y el flag absence_generated que sí respeta la nómina | app/Http/Controllers/AttendanceReportController.php:299-320; app/Services/PayrollCalculatorService.php:363-381 |
| 55 | CONFIRMADO | media | DIAS_AUSENCIA exportado a CONTPAQi no concuerda con la deducción cobrada (días absent vs unpaid_days+lateAbsences) | app/Exports/ContpaqiPrenominaExport.php:79; app/Services/PayrollCalculatorService.php:325; app/Services/PayrollCalculatorService.php:133-134, 654-657 |
| 56 | CONFIRMADO | media | Incidencias de incapacidad (sick_leave) no se pagan ni se descuentan: efecto monetario nulo aunque el módulo las trata como justificadas | app/Services/PayrollCalculatorService.php:645-647; app/Services/PayrollCalculatorService.php:133, 160-169; app/Models/IncidentType.php:17-42 |
| 57 | CONFIRMADO | media | Marcar la nómina como pagada no marca las autorizaciones como paid: una autorización approved sigue siendo modificable por un admin tras pagarse su nómina | app/Http/Controllers/PayrollController.php:202-218; app/Http/Controllers/AuthorizationController.php:703-708, 720-734; app/Services/PayrollCalculatorService.php:37-39 |
| 58 | CONFIRMADO | media | El reporte de incidencias y su export no filtran por estado: mezclan pendientes y rechazadas en los mismos totales que la nómina solo considera aprobadas | app/Http/Controllers/ReportController.php:545-549, 580-585; app/Http/Controllers/ReportExportController.php:246-265; app/Services/PayrollCalculatorService.php:74-85 |
| 59 | CONFIRMADO | media | Rango de fechas inconsistente entre reportes de incidencias y la nómina: solo solapamiento parcial vs cobertura total del periodo | app/Services/PayrollCalculatorService.php:76-83; app/Http/Controllers/ReportController.php:547; app/Http/Controllers/ReportController.php:355 |
| 60 | CONFIRMADO | media | Reporte de retardos web (lateArrivals) usa umbral '6' hardcodeado en vez del SystemSetting, desincronizado con incidencias/nómina si cambia la configuración | app/Http/Controllers/ReportController.php:415-416; app/Models/LateAccumulation.php:46-51; app/Http/Controllers/AttendanceReportController.php:274, 308 |
| 61 | CONFIRMADO | media | Reporte semanal exige compensation_type.code (HE/HED/HET/VEL); nomina paga overtime/velada aunque la autorizacion no tenga compensation_type | app/Services/Reports/WeeklyOvertimeReportService.php:213-225; app/Services/VeladaCalculatorService.php:145-152; app/Services/CompensationRateResolverService.php:294-318 |
| 62 | CONFIRMADO | media | Umbral de retardo-a-falta hardcodeado en 6 en ReportController::lateArrivals, mientras nómina y los demás reportes leen system_setting late_to_absence_count | app/Http/Controllers/ReportController.php:416; app/Services/PayrollCalculatorService.php:373; app/Http/Controllers/AttendanceReportController.php:274,308 |
| 63 | CONFIRMADO | media | Asistencia perfecta: la versión web excluye festivos del esperado, el export CSV no — dos resultados distintos para 'el mismo reporte' | app/Http/Controllers/AttendanceReportController.php:212-213,226-227; app/Http/Controllers/ReportExportController.php:420-435; app/Services/PayrollCalculatorService.php:404-413,434-438 |
| 64 | CONFIRMADO | media | Regla de alcance base/extras por tipo de periodo DUPLICADA entre el servicio de nómina y el export CONTPAQi: drift latente al cambiar tipos | app/Services/PayrollCalculatorService.php:112-113; app/Exports/ContpaqiPrenominaExport.php:41-52,74-90 |
| 65 | CONFIRMADO | media | Vincular una anomalía a autorización/incidencia o resolverla manualmente no recalcula asistencia ni nómina | app/Http/Controllers/AnomalyResolutionController.php:289-291; app/Models/AttendanceAnomaly.php:166-226; app/Http/Controllers/AuthorizationController.php:722-737 |
| 66 | CONFIRMADO | media | Editar una checada o eliminar/rechazar una incidencia o autorización no invalida la nómina ya calculada | app/Services/PayrollCalculatorService.php:304-348; app/Http/Controllers/PayrollController.php:163-166; app/Http/Controllers/IncidentController.php:497-512 |
| 67 | CONFIRMADO | media | FRT desactivada por migración pero el sync la sigue creando, y nómina la consume como unpaid | database/migrations/2026_03_30_000007_fix_incident_type_rules.php:18; app/Services/ZktecoSyncService.php:887,898-908; app/Services/PayrollCalculatorService.php:654-657 |
| 68 | CONFIRMADO | media | Edición manual de checada recalcula horas con lógica propia divergente (default 8h, sin velada/permiso/late) | app/Http/Controllers/AttendanceController.php:328-398; app/Services/PayrollCalculatorService.php:481-498,559-596; app/Services/ZktecoSyncService.php:712-832 |
| 69 | CONFIRMADO | media | Agrupacion por weekOfYear ISO parte mal las semanas del periodo en el bono semanal (granularidad incoherente con el periodo de nomina) | app/Services/PayrollCalculatorService.php:404; app/Services/PayrollCalculatorService.php:406-415 |
| 70 | CONFIRMADO | media | La incidencia FRT (falta por retardos) se sigue generando pese a estar marcada inactiva, y quedo como con goce (is_paid=true) | database/migrations/2026_03_30_000007_fix_incident_type_rules.php:17-29; app/Services/ZktecoSyncService.php:887; app/Services/PayrollCalculatorService.php:654-658 |
| 71 | CONFIRMADO | media | Reportes de asistencia/puntualidad recalculan en vivo con formulas y filtros distintos a los de nomina | app/Http/Controllers/AttendanceReportController.php:226-241; app/Services/PayrollCalculatorService.php:407-408,434-435; app/Http/Controllers/ReportController.php:516,632-635 |
| 72 | CONFIRMADO | media | Datos de nomina precalculados (PayrollEntry) no se invalidan al editar checadas o aprobar/borrar incidencias y autorizaciones | app/Services/PayrollCalculatorService.php:33-53; app/Services/PayrollCalculatorService.php:305-348; app/Http/Controllers/AttendanceController.php:338 |
| 73 | CONFIRMADO | media | El reporte reclasifica horas extra como velada usando el flag is_night_shift del attendance, criterio distinto al de la nomina | app/Services/Reports/WeeklyOvertimeReportService.php:227-231; app/Services/VeladaCalculatorService.php:86-123; app/Services/ZktecoSyncService.php:662 |
| 74 | CONFIRMADO | media | Edición manual de checada usa fórmula de horas DISTINTA a la del sync (descuento de break y horas extra) | app/Http/Controllers/AttendanceController.php:362-376; app/Services/ZktecoSyncService.php:729-745 |
| 75 | CONFIRMADO | media | Reporte de horas extra (ReportController::overtime) estima costo con multiplicador fijo 1.5 y usa horas SIN autorizar; la nómina paga horas autorizadas con tabla de compensación | app/Http/Controllers/ReportController.php:283-297; app/Services/VeladaCalculatorService.php:126-133; app/Services/PayrollCalculatorService.php:171-216 |
| 76 | CONFIRMADO | media | El default de punctuality_bonus_minutes es 5 pero la spec comercial promete 10; el flag se recalcula con el setting pero el valor por defecto contradice lo prometido | app/Services/ZktecoSyncService.php:693-694; app/Services/ZktecoSyncService.php:922-925; app/Console/Commands/RecalculateAttendanceMetrics.php:76-85 |
| 77 | CONFIRMADO | media | El reporte de faltas agrupa retardos->falta por mes calendario, contradiciendo la regla semanal del sync y la nómina | app/Http/Controllers/AttendanceReportController.php:105-123; app/Services/ZktecoSyncService.php:849-857; app/Services/PayrollCalculatorService.php:363-376 |
| 78 | PROBABLE | media | La auto-aprobación de autorizaciones no firma al jefe de departamento, a diferencia de la aprobación normal | app/Models/Authorization.php:207-210; app/Http/Controllers/AuthorizationController.php:1534-1539 |
| 79 | PROBABLE | media | Umbral de salida temprana usa operador distinto en sync (>) y en reportes (>=): un registro en el límite es falta en el reporte pero no para el sistema | app/Services/ZktecoSyncService.php:805-806; app/Http/Controllers/AttendanceReportController.php:77, 371-372; app/Http/Controllers/ReportExportController.php:610 |
| 80 | PROBABLE | media | FRT auto-aprobada (requires_approval=false) afecta nómina y ausencias sin que el supervisor la revise, pero es invisible en formularios por estar el tipo desactivado | database/seeders/IncidentTypesSeeder.php:70-78; app/Services/ZktecoSyncService.php:905-907; database/migrations/2026_03_30_000007_fix_incident_type_rules.php:16-19 |
| 81 | PROBABLE | media | Reporte semanal de overtime usa startOfWeek (lunes ISO) mientras la deteccion de absentismo por retardos en nomina usa weekOfYear; los periodos de nomina pueden no coincidir con la semana del reporte | app/Services/Reports/WeeklyOvertimeReportService.php:59-89; app/Services/PayrollCalculatorService.php:65-97; app/Services/PayrollCalculatorService.php:363-366 |
| 82 | PROBABLE | media | Reporte de overtime semanal RECALCULA horas extra detectadas (OvertimeRoundingService) en vez de leer overtime_hours/overtime_authorized_hours que usa la nómina | app/Services/Reports/WeeklyOvertimeReportService.php:233-240; app/Services/OvertimeRoundingService.php:26-89; app/Services/PayrollCalculatorService.php:481-498 |
| 83 | PROBABLE | media | Saldo de vacaciones del reporte ignora vacation_days_reserved que sí descuenta el modelo Employee | app/Http/Controllers/ReportController.php:451-453; app/Http/Controllers/ReportExportController.php:232-234; app/Models/Employee.php:390-401 |
| 84 | PROBABLE | media | El reporte usa startOfWeek/endOfWeek dependiente de locale, mientras la nomina usa start_date/end_date del periodo: rangos de fecha potencialmente desalineados | app/Services/Reports/WeeklyOvertimeReportService.php:59-69; app/Services/PayrollCalculatorService.php:65-71; app/Services/PayrollCalculatorService.php:360-366 |
| 85 | CONFIRMADO | baja | Asistencia perfecta diverge entre pantalla y export por tratamiento distinto de festivos, contradiciendo lo justificado por incidencias | app/Http/Controllers/AttendanceReportController.php:211-235; app/Http/Controllers/ReportExportController.php:420-446 |
| 86 | CONFIRMADO | baja | Reporte mensual mezcla incidencias aprobadas con el campo crudo days_count, distinto del prorrateo por solapamiento que aplica nomina | app/Http/Controllers/ReportController.php:171-209; app/Services/PayrollCalculatorService.php:617-660 |
| 87 | CONFIRMADO | baja | Sueldo diario en nómina fijo a hourly_rate*8, ignorando daily_work_hours; reportes de productividad/ausencias usan la jornada efectiva del horario | app/Services/PayrollCalculatorService.php:117,134,164; app/Models/Employee.php:409-418; app/Http/Controllers/ReportController.php:623-628 |
| 88 | CONFIRMADO | baja | La auto-aprobación de autorizaciones detectadas no firma al jefe de departamento ni cierra anomalías que su aprobación manual sí cerraría | app/Http/Controllers/AuthorizationController.php:1479-1542; app/Models/Authorization.php:194-213; app/Http/Controllers/AuthorizationController.php:736-737 |
| 89 | CONFIRMADO | baja | qualifies_for_night_shift_bonus se calcula y persiste en el sync pero la nómina lo ignora: el bono nocturno se paga por autorización, no por noche trabajada | app/Services/ZktecoSyncService.php:813-828; app/Services/PayrollCalculatorService.php:452-471; app/Models/AttendanceRecord.php:47,74 |
| 90 | PROBABLE | baja | PayrollEntry::calculateGrossPay() omite velada_pay y other_compensation_pay; difiere del gross_pay persistido por la nómina | app/Models/PayrollEntry.php:119-127; app/Services/PayrollCalculatorService.php:231-232 |