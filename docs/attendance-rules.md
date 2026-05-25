# Reglas canónicas de asistencia, faltas, retardos y velada

Este documento define cómo el sistema convierte marcaciones crudas (ZKTeco) en `attendance_records`, cómo clasifica cada día, y cómo cada reporte interpreta esos datos. La regla que aplica para un caso ambiguo es siempre la PRIMERA que coincida, leyendo de arriba hacia abajo.

---

## 1. Asignación de fecha a una marcación cruda

Cada punch tiene un timestamp y se asigna a un `work_date`:

1. Si el empleado tiene horario diurno (entry_time < exit_time, ambos en mismo día): el work_date = la fecha local del punch.
2. Si el empleado tiene horario nocturno (entry_time > exit_time, ej. 22:00→07:30): el work_date "inicia" el día en que entró a su turno. Las marcaciones entre 00:00 y `exit_time + tolerancia` (default 6h) se asignan al `work_date = fecha del punch − 1 día` **solo si** ese día previo tiene al menos un punch que empieza después de `entry_time`.
3. Si la regla 2 no aplica (no hay punch previo cerca de entry_time), el punch se asigna al `work_date` de su propia fecha y la jornada se considera incompleta (regla 5.B más abajo).

---

## 2. Consolidación de múltiples punches del mismo work_date

Para cada `work_date` con N punches:

1. **Sin gaps significativos (1–4 punches, todos dentro de 12h continuas):**
   - `check_in` = primer punch.
   - `check_out` = último punch (si > check_in + 10 min).
   - Si hay 4 punches y los del medio están en horario de comida del schedule (`break_start` ± 30min, `break_end` ± 30min), se interpretan como `lunch_out` / `lunch_in` y se restan del worked_hours.

2. **Multi-turno (4+ punches con un gap ≥ 2 horas entre dos pares):**
   - Se separa en `shift_1` y `shift_2`.
   - `shift_1.check_in` = primer punch, `shift_1.check_out` = último punch antes del gap.
   - `shift_2.check_in` = primer punch después del gap, `shift_2.check_out` = último punch.
   - El `attendance_record` registra el turno principal (`shift_1` si encaja en el schedule, sino el de mayor duración) en `check_in`/`check_out`. El otro turno se guarda en `secondary_shift_check_in`/`secondary_shift_check_out` (campos nuevos).
   - `worked_hours` suma ambos turnos; el segundo se considera **overtime** o **velada** según en qué ventana caiga (ver sección 4).

3. **Punch único:** check_in se llena, check_out queda null. `requires_review = true`. Status según regla 5.B.

---

## 3. Marcaciones incoherentes (anti-ruido)

Antes de calcular métricas, el record se valida:

- **Jornada absurdamente corta** (`worked_hours < 1` y `check_in == check_out` ± 60 min): se marca `requires_review = true` y status = `incoherent_punches`.
- **Salida temprana absurda** (`check_out < check_in` interpretado como mismo día con `early_departure_minutes > 6h`): no se calcula `early_departure_minutes` — se setea a null y status = `incoherent_punches` con `requires_review = true`.
- **Punches todos antes de `entry_time − 6h`** (ej. solo punches de 00:11 a 01:00 cuando entry es 09:00): mismo trato — `incoherent_punches`.

Razón: estos casos son ruido del sincronizador (asignación de fecha mala, punches huérfanos de otro día). El sistema los señala para revisión humana en Anomalías y NO los infla en los reportes con números absurdos como "early_departure 2549 min".

---

## 4. Cálculo de horas (cuando los punches sí son coherentes)

Definiciones tomadas del schedule efectivo del empleado para ese día:
- `entry_time`, `exit_time`, `daily_work_hours`, `break_minutes`, `late_tolerance_minutes`.

### 4.A `late_minutes`
- Si `check_in <= entry_time + late_tolerance` → 0.
- Si `check_in > entry_time + late_tolerance` y `check_in < exit_time` (mismo día) → `check_in − entry_time − tolerance` (en minutos, mínimo 0).
- Si `check_in >= exit_time` → 0 (probablemente entró para overtime/turno distinto, no aplica retardo).
- Excepción: si tiene incidencia aprobada `Permiso de Entrada` (PEN) ese día → `late_minutes = 0`.

### 4.B `early_departure_minutes`
- Si no hay check_out → null (no se puede calcular).
- Si turno diurno: `expected_exit = work_date + exit_time`. Si `check_out < expected_exit` → diferencia en minutos.
- Si turno nocturno (entry > exit): `expected_exit = work_date + 1 día + exit_time`. Si `check_out < expected_exit` → diferencia.
- Excepción: si tiene incidencia aprobada `Permiso de Salida` (PSA) ese día → `early_departure_minutes = 0`.

### 4.C `worked_hours`
- `total = check_out − check_in` (manejando cruce de medianoche para velada).
- Si tiene `lunch_out` y `lunch_in`: restar la diferencia.
- Si tiene `actual_break_minutes` registrado: restarlo.
- Si nada de lo anterior y `total > 5h`: restar el `break_minutes` del schedule (o `default_break_minutes` del depto, default 60).
- En multi-turno (regla 2.2): suma de ambos turnos − breaks.

### 4.D `overtime_hours` y `velada_hours`
- `extra = worked_hours − daily_work_hours` (mínimo 0).
- Si `extra > 0` y la salida cae dentro de la ventana de velada del catálogo (ej. 22:00–05:00): la porción que caiga dentro de la ventana = `velada_hours`; el resto = `overtime_hours`.
- Si la ventana no aplica → todo es `overtime_hours`.
- Estas horas son **brutas**. Se pagan solo si hay autorización aprobada del tipo correspondiente (regla 6).

---

## 5. Status del día

Se evalúa en este orden estricto. La primera regla que aplique gana:

1. **`holiday`**: si `is_holiday = true` (DOF o Yom Tov). No cuenta como falta ni como retardo, no requiere check_in.
2. **`vacation` / `sick_leave` / `permission`**: si tiene incidencia aprobada que cubre la fecha. Estado se hereda del tipo de incidencia.
3. **`incoherent_punches`**: si pasa la validación de la regla 3. Cuenta como falta y `requires_review = true`.
4. **`absent`** (no se presentó): si no hay check_in y es día laboral (según `working_days` y overrides).
5. **`absent`** (no marcó salida): si hay check_in pero NO hay check_out **y el día ya pasó hace más de `incomplete_punch_grace_hours` (default 4h después del exit_time)**. Antes de eso queda `requires_review` (puede que aún no haya salido). Si tiene `Permiso de Salida` aprobado → status = `present`.
6. **`absent`** (umbral retardo): si `late_minutes >= max_late_minutes_before_absence` (default 60) y no tiene PEN aprobado.
7. **`absent`** (umbral salida temprana): si `early_departure_minutes >= early_departure_absence_threshold` (default 30) y no tiene PSA aprobado y `early_departure_is_absence = true`.
8. **`partial`**: si `worked_hours < 4` pero hay check_in y check_out válidos (sin caer en absent por umbrales).
9. **`late`**: si `late_minutes > 0` pero menor al umbral de absent. No tiene PEN aprobado.
10. **`present`**: cualquier otro caso con check_in válido en día laboral.

---

## 6. Cómo cada reporte clasifica los días

### 6.A Faltas (`AttendanceReportController::faltas`)

Cada día contado como falta tiene una de estas categorías y se muestra con su label específico:

| Categoría | Condición | Label |
|---|---|---|
| `no_show` | status=absent y check_in IS NULL (regla 5.4) | "No se presentó (Entrada esperada: {entry_time})" |
| `no_checkout` | status=absent por regla 5.5 | "No marcó salida (entró: {check_in}, esperaba salir: {exit_time})" |
| `incoherent` | status=incoherent_punches (regla 3) | "Marcación incompleta — punches: {first} a {last} (esperado: {entry_time}–{exit_time})" |
| `late_threshold` | status=absent por regla 5.6 | "Entrada esperada: {entry_time}, llegó: {check_in}" |
| `early_threshold` | status=absent por regla 5.7 | "Salida esperada: {exit_time}, salió: {check_out}" |
| `accumulated` | floor(retardos del mes / `late_to_absence_count`) | "{N} retardos en {mes} = {floor(N/6)} falta(s) acumulada(s)" |

Reglas anti-doble-conteo:
- Festivos (DOF + Yom Tov) NO cuentan en ningún caso.
- Vacaciones, incapacidad, permisos aprobados NO cuentan.
- Una misma fecha solo aparece UNA VEZ — si cae en `late_threshold` no se duplica como `accumulated`.

Las columnas del reporte son las sumas:
- "No se presentó" = no_show
- "Por umbral" = no_checkout + late_threshold + early_threshold + incoherent
- "Por retardos" = accumulated
- "Total" = suma de las tres

### 6.B Asistencia
Cuenta días con status ∈ {present, late, partial, holiday}. Mide presentismo bruto.

### 6.C Retardos
Solo `status = late` (sin contar los que se convirtieron en absent por regla 5.6).

### 6.D Salidas tempranas
Días con `early_departure_minutes > 0` (independiente del status). Lista granular para auditoría.

### 6.E Reportes de overtime/velada
Suman `overtime_hours` y `velada_hours` autorizadas (con autorización aprobada de los tipos correspondientes — `overtime`, `night_shift`, `holiday_worked`, `special`).

---

## 7. Datos que requieren intervención humana

El sistema **nunca adivina** estos casos — los marca `requires_review = true` y los muestra en Anomalías:

- Punch único (sin contraparte) que no se cierra dentro del grace de 4h después del exit.
- Multi-turno con gap > 8h (probablemente dos jornadas reales que necesitan cuadrarse manualmente).
- Punches que no encajan con ningún horario (ej. todos antes de entry_time − 6h).
- Schedule mal configurado: entry == exit, daily_work_hours == 0, working_days vacío.
- Empleado activo sin schedule asignado.
- Empleado con punches en días no laborales según su `working_days` (sale en Anomalías como "schedule_deviation").

---

## 8. Setting globales relevantes (`SystemSetting`)

| Key | Default | Descripción |
|---|---|---|
| `late_to_absence_count` | 6 | N retardos = 1 falta acumulada |
| `max_late_minutes_before_absence` | 60 | Llegar más de X min tarde = absent |
| `early_departure_absence_threshold` | 30 | Salir más de X min temprano = absent (si setting siguiente activo) |
| `early_departure_is_absence` | true | Si false, salir temprano nunca convierte a absent |
| `incomplete_punch_grace_hours` | 4 | Horas después del exit_time antes de marcar absent por no-checkout |
| `velada_window_start` | 22 | Hora de inicio de la ventana de velada |
| `velada_window_end` | 5 | Hora de fin de la ventana de velada |
| `punctuality_bonus_minutes` | 5 | Llegar X min antes = qualifies_for_punctuality_bonus |
| `multi_shift_gap_minutes` | 120 | Gap mínimo entre punches para considerar 2 turnos |
| `min_shift_minutes` | 10 | Mínimo entre check_in y check_out para considerarlo un turno (evita doble-marcado) |
| `max_continuous_shift_hours` | 18 | Más de X horas continuas = `requires_review` automático |

---

## 9. Edge cases — regla determinística para cada uno

### 9.A Punches anómalos
| Caso | Regla |
|---|---|
| Mismo timestamp, dos punches | Deduplicar exactos. Mantener uno (el de menor `device_id` por desempate). |
| 2 punches separados por menos de `min_shift_minutes` (10) | Tratar como **un solo punch** (probable doble-marcado). Conservar el primero. |
| `check_out < check_in` mismo día sin ser velada | Si la diferencia ≤ 24h y el horario es nocturno → asumir cruce de medianoche. Si es diurno → `incoherent_punches`, `requires_review = true`. |
| 10+ punches en un día | Buscar pares (in/out) por timestamp. Los huérfanos se ignoran y se reportan como `duplicate_punches` en Anomalías. |
| Punch con timestamp futuro o anterior a `hire_date` | Descartar y registrar warning en logs. No crea `attendance_record`. |
| Punch solo `out` sin previo `in` ese día y sin punch del día anterior | `incoherent_punches`, `check_out` queda guardado para auditoría pero `worked_hours = 0`. |
| Solo `in` y nunca `out` (ni en grace de 4h del exit_time) | Status = `absent` por regla 5.5. Label "No marcó salida". |
| Solo `in` y aún dentro del grace (turno actualmente en curso) | Status = `requires_review` pero NO falta. El reporte de Faltas lo excluye hasta que termine el grace. |
| Turno > `max_continuous_shift_hours` (18h) | `requires_review = true`. Worked_hours se calculan, pero status también marca `requires_review` aunque pase otras reglas. |
| Más de 1 turno con gap entre 30 min y 2h | NO es multi-turno (probablemente comida extendida). Se trata como 1 turno con `excessive_break` flag en Anomalías. |

### 9.B Schedule anómalo
| Caso | Regla |
|---|---|
| Empleado sin schedule | `requires_review = true`. Status = `present` si hay check_in, `absent` si no. NO se calculan late/early/overtime. Aparece en Anomalías como "missing_schedule". |
| Schedule con `entry_time == exit_time` | Se considera schedule inválido. `requires_review = true`. NO calcular metrics. |
| `daily_work_hours = 0` o `> 24` | Inválido. `requires_review = true`. |
| `working_days = []` | Empleado libre todos los días. Cualquier punch se cuenta como overtime puro. NO genera faltas. |
| Día NO está en `working_days` pero hay punches | Es trabajo voluntario / fin de semana. Status = `present`, `worked_hours` cuentan como `weekend_hours` y requieren auth `overtime`/`special`/`holiday_worked` para pagarse como prima. NO genera retardo ni falta. |
| Día EN `working_days` pero el empleado tiene `schedule_overrides.day_schedules.{dia}` que lo excluye | Override gana. NO es día laboral. |
| Cambio de schedule_id mid-period | Cada `attendance_record` usa el schedule del día (snapshot al momento del sync). Cambios futuros no recalculan retroactivamente — la columna `schedule_id` queda registrada en el AuditLog del cambio. |
| Schedule con `is_flexible = true` | NO se calculan `late_minutes` (siempre 0). Solo `early_departure_minutes` aplica si `daily_work_hours` no se cumplió. |

### 9.C Empleado / contrato
| Caso | Regla |
|---|---|
| `hire_date` futura o igual a hoy | Días anteriores a hire_date NO generan faltas ni metrics — se ignoran. Día de hire_date sí cuenta normalmente. |
| `termination_date` en mid-period | Días posteriores a termination_date NO generan faltas. Status = `terminated` para esos días. |
| Empleado `inactive` / `terminated` que aún marca | El punch se descarta en sync (ZktecoSyncService verifica status antes de crear el record). Aparece como warning en logs. |
| Empleado en `is_trial_period` | Se calcula igual que cualquier otro. El estado de prueba NO cambia reglas de asistencia. |
| Empleado sin `user_id` | Funciona normal — el user solo se necesita para login, no para cálculos. |
| Empleado con `supervisor_id` apuntando a empleado borrado/inactive | El cascade para reportes lo ignora (el comando `employees:resync-supervisors` lo limpia). |

### 9.D Día de calendario
| Caso | Regla |
|---|---|
| Día festivo DOF y empleado trabajó | Status = `holiday`. No falta. Las horas cuentan como `holiday_hours` y requieren auth `holiday_worked` o `special` para pagarse como prima. |
| Yom Tov | Mismo trato que DOF. |
| DOF y Yom Tov el mismo día | Es festivo único — no se duplica el premio. |
| Día festivo cae en domingo / día NO laboral del empleado | Status = `holiday`. Si trabajó, las horas cuentan tanto como holiday como weekend — pero el premio se aplica UNA SOLA VEZ (la categoría más alta: `holiday_worked` > `weekend`). |
| DST (cambio de horario) | Los timestamps se almacenan en zona Mexico City. El sync convierte a la TZ local antes de calcular. La hora "perdida" en marzo y "ganada" en octubre se reconcilian con `Carbon::createFromFormat` honrando DST. NO se ajusta `worked_hours` artificialmente — se calcula con timestamps reales. |
| Cambio de año en mid-período | Sin tratamiento especial. Reportes piden rango explícito (start_date / end_date). |

### 9.E Permisos e incidencias
| Caso | Regla |
|---|---|
| `Permiso de Salida` (PSA) aprobado por horas (no día completo) | Si el `check_out` cae después de la `start_time` del permiso → no penaliza. Si es antes → sí penaliza por la diferencia. |
| `Permiso de Entrada` (PEN) aprobado por horas | Si el `check_in` cae antes o en `end_time` del permiso → no penaliza. |
| Permiso aprobado retroactivo (status pasó a `approved` después del sync) | Recalcular metrics del día afectado se dispara automáticamente vía observer en `Incident::saved`. |
| Vacaciones medio día | Las incidencias actuales son por día completo. Permisos sí soportan horas. Si se requiere medio día → usar Permiso de Salida o Entrada. |
| Incapacidad varios días con un festivo en medio | El festivo NO se descuenta del balance de incapacidad — son días naturales. |
| Dos incidencias aprobadas el mismo día | La de mayor prioridad gana (vacación > permiso). Se valida en `Incident::saving` y se rechaza la segunda si choca. |

### 9.F Velada / multi-turno
| Caso | Regla |
|---|---|
| Velada cruza fin de semana (viernes 22:00 → sábado 07:00) | El `attendance_record` se queda en viernes (work_date). Las `velada_hours` cuentan en viernes para el cálculo. |
| Velada cruza un festivo | Las horas que caen DESPUÉS de medianoche del festivo cuentan como `holiday_hours` también. Premio se elige el mayor entre velada o festivo (no se duplica). |
| Empleado diurno que ocasionalmente trabaja velada | Si tiene autorización aprobada de `night_shift` → las horas en ventana de velada se pagan como velada. Si no → se pagan como `overtime` regular (sin premio velada). |
| Multi-turno: regular 09:00–18:00 + velada 20:00–03:00 | `shift_1`: 09–18 contra schedule normal (8h regulares). `shift_2`: 20–03 (7h, todas como velada/overtime con autorización). El gap 18–20 NO se cuenta. `worked_hours` total = 16h, distribuidas: 8 regulares + 5 overtime + 2 velada (asumiendo ventana 22–05). |
| Turno extendido sin gap (08:00 → 23:00) | Un solo turno. 8h regulares + 7h overtime; si la salida 23:00 cae dentro de ventana velada → la última hora se cuenta como velada. |
| Punches muy cercanos pero parecen 2 turnos (ej. 17:58, 18:00, 18:02, 03:00) | El gap real es 18:02–03:00 (~9h) → es multi-turno. Los 17:58/18:00/18:02 son ruido del primer turno → consolidar como check_out=18:02 del primer turno. |

### 9.G Sync / tecnológicos
| Caso | Regla |
|---|---|
| Resincronización: punch ya existía | UPSERT por (employee_id, timestamp, device_id) — no duplica. |
| Punch llegó tarde (días después) | `processEmployeeDayRecords` se llama para esa fecha. Recalcula metrics. Si el día ya estaba cerrado en una nómina pagada, se marca `requires_review`. |
| Múltiples dispositivos (entró por dev 1, salió por dev 2) | OK. raw_punches guarda `device_id` por punch. |
| Devices con clocks desincronizados | Asume confianza en el timestamp del device. Diferencias < 5 min entre devices se ignoran. > 5 min genera anomalía `device_clock_drift`. |
| Eliminar manualmente un attendance_record | Borra el record. El próximo sync lo recrea desde raw_punches si todavía existen, sino el día queda sin record (= absent si era laboral). AuditLog conserva la eliminación. |

### 9.H Reportes
| Caso | Regla |
|---|---|
| Rango incluye día actual incompleto | Día actual NO genera faltas hasta que termine el grace (4h después del exit). Aparece como `requires_review` o se excluye del cálculo. |
| Periodo donde el empleado no estaba contratado | Solo se cuentan días dentro de `[hire_date, termination_date ?? today]`. |
| Cambio de departamento mid-period | El reporte usa el departamento ACTUAL del empleado (no el histórico). Para histórico exacto, usar AuditLog. |
| Reporte exportado a Excel vs visual | DEBEN dar mismos números. La fuente es el mismo método del controller. |
| Supervisor sin reportes en cascade | Reporte muestra "Sin empleados en tu equipo". |
| Reporte para empleado con cambio de schedule mid-period | Cada día usa el schedule_id que tenía el record cuando se calculó. Reporte respeta historial. |

### 9.I Concurrencia
| Caso | Regla |
|---|---|
| Sync corriendo mientras se edita manualmente un attendance | DB transaction + lock por (employee_id, work_date). El último que commitea gana, el otro recibe `Lock wait timeout` y retry-able. |
| Dos approvers aprobando la misma autorización | Optimistic lock vía `updated_at`. El segundo recibe `409 Conflict`. |
| Empleado marca y simultáneamente sync corre | Sync solo lee; UPSERT al final con `INSERT ... ON DUPLICATE KEY UPDATE`. Sin race. |

### 9.J Datos históricos sucios
| Caso | Regla |
|---|---|
| Records previos sin `raw_punches` | Mantener metrics existentes; al editar manualmente el día se re-piden los punches al device si están disponibles. |
| Records con `check_out < check_in` y schedule diurno (data vieja) | Migración one-shot: si la diferencia < 1h → asumir error de captura, swap. Si > 1h → marcar `requires_review = true`. |

---

## 10. Garantía de no-falla

Toda regla de cálculo termina con uno de tres outcomes:

1. **Cálculo determinístico exitoso** — metrics se llenan, status se asigna.
2. **`requires_review = true`** — el caso es ambiguo o anómalo, NO se inventa un valor (los campos quedan en null o conservadores), el día aparece en Anomalías para revisión humana.
3. **Excepción que aborta el sync de ESE record** — se loguea con el `employee_id` y `work_date`, el sync continúa con los demás records.

Nunca se debe permitir un valor "sin sentido" como `early_departure_minutes = 2549`. Si el cálculo arroja algo así, el path correcto es ir a (2): `requires_review = true` con metrics en null.
