# Sistema de Gestión de Nómina y Asistencia - PINKY

**Versión:** 1.0
**Fecha:** Enero 2026
**Cliente:** [Nombre del Cliente]
**Proveedor:** Overcloud

---

## 1. Resumen Ejecutivo

El presente documento describe el alcance, funcionalidades y especificaciones técnicas del Sistema de Gestión de Nómina y Asistencia **PINKY**, diseñado para automatizar y optimizar los procesos de control de asistencia, gestión de empleados, cálculo de nómina y generación de reportes para integración con CONTPAQi.

---

## 2. Objetivos del Sistema

- Centralizar la gestión de información de empleados
- Automatizar el registro y control de asistencia mediante integración con dispositivos ZKTeco
- Calcular automáticamente conceptos de nómina (puntualidad, horas extra, veladas, días festivos)
- Gestionar vacaciones, incapacidades y autorizaciones con evidencia documental
- Generar reportes y exportaciones compatibles con CONTPAQi
- Proporcionar trazabilidad completa de todas las operaciones mediante logs de auditoría

---

## 3. Módulos del Sistema

### 3.1 Módulo de Empleados

#### 3.1.1 Información del Empleado

| Campo | Descripción | Tipo |
|-------|-------------|------|
| Número de empleado | Identificador único interno | Texto |
| Código CONTPAQi | Código para integración con sistema de nómina | Texto |
| ID ZKTeco | Identificador en el reloj checador | Entero |
| Nombre completo | Nombre y apellidos | Texto |
| Email | Correo electrónico corporativo | Texto |
| Teléfono | Número de contacto | Texto |
| Fecha de ingreso | Fecha de alta en la empresa | Fecha |
| Fecha de baja | Fecha de terminación (si aplica) | Fecha |
| Departamento | Área organizacional | Catálogo |
| Puesto | Posición laboral | Catálogo |
| Tipo de posición | Clasificación del puesto | Catálogo |
| **Jefe superior** | Supervisor directo del empleado | Relación |
| Horario asignado | Horario de trabajo | Catálogo |
| Tipo de horario | Clasificación del horario (fijo/flexible) | Catálogo |
| Días de vacaciones correspondientes | Días acumulados por ley/política | Entero |
| Días de vacaciones tomados | Días ya disfrutados | Entero |
| Días de vacaciones disponibles | Cálculo automático | Calculado |
| Tarifa por hora | Sueldo por hora regular | Decimal |
| Multiplicador hora extra | Factor para cálculo de horas extra | Decimal |
| Multiplicador día festivo | Factor para días festivos trabajados | Decimal |
| Estatus | Activo / Inactivo / Terminado | Catálogo |

#### 3.1.2 Funcionalidades del Módulo

- **Edición masiva**: Capacidad de filtrar empleados por múltiples criterios y modificar campos en lote
- **Filtros avanzados**: Por departamento, puesto, horario, estatus, jefe superior
- **Historial de cambios**: Registro de todas las modificaciones realizadas a cada empleado

---

### 3.2 Módulo de Asistencia y Puntualidad

#### 3.2.1 Registro de Asistencia

| Campo | Descripción |
|-------|-------------|
| Fecha de trabajo | Día del registro |
| Hora de entrada | Check-in registrado |
| Hora de salida | Check-out registrado |
| Horas trabajadas | Cálculo automático |
| Horas extra autorizadas | Tiempo adicional al horario (solo si está autorizado) |
| Minutos de retardo | Diferencia con hora de entrada |
| Minutos de salida anticipada | Diferencia con hora de salida |
| Estatus | Presente / Retardo / Falta / Parcial / Vacaciones / Incapacidad / Permiso |
| Día festivo | Indicador de día festivo trabajado |
| Fin de semana | Indicador de trabajo en día de descanso |
| Marcajes originales | JSON con todos los registros del reloj (sin modificar) |
| **Tiene permiso** | Indicador si el día tiene permiso autorizado |
| **Autorización vinculada** | Referencia al permiso/autorización que afecta este día |
| **Horas reales trabajadas** | Horas físicamente trabajadas (check-in a check-out) |
| **Horas cubiertas por permiso** | Horas del permiso que cuentan como trabajadas |
| **Horas totales para nómina** | Suma de horas reales + horas de permiso = jornada completa |
| **Requiere revisión** | Indicador de anomalías que necesitan atención |

---

#### 3.2.2 Visualización de Horas por Rol

**IMPORTANTE:** La información de horas se muestra de forma diferente según el rol del usuario.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│              DOS VISTAS DEL REGISTRO DE ASISTENCIA                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  VISTA COMPLETA (RAW)                    VISTA CALCULADA (FILTRADA)         │
│  ─────────────────────                   ──────────────────────────         │
│  • Horas TAL CUAL ZKTeco                 • Horas de jornada normal          │
│  • Todas las horas extras                • Horas extras SOLO si autorizadas │
│  • Todas las veladas                     • Veladas SOLO si autorizadas      │
│  • Sin filtrar nada                      • Filtrado automático              │
│                                                                             │
│  Roles con acceso:                       Roles con acceso:                  │
│  ✓ Administrador                         ✓ Supervisor (su equipo)           │
│  ✓ Recursos Humanos                      ✓ Empleado (solo su registro)      │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

##### Ejemplo Práctico:

**Empleado trabajó:** 9:00 entrada, 22:00 salida (13 horas registradas en ZKTeco)
**Horario normal:** 9:00 - 18:00 (8 horas)
**Horas extra:** 4 horas (18:00 - 22:00) - **NO AUTORIZADAS**

| Rol | ¿Qué ve? | Horas mostradas |
|-----|----------|-----------------|
| **Administrador** | Todo tal cual ZKTeco | 13 horas (9:00 - 22:00) |
| **Recursos Humanos** | Todo tal cual ZKTeco | 13 horas (9:00 - 22:00) |
| **Supervisor** | Solo jornada normal (extras no autorizadas) | 8 horas (9:00 - 18:00) |
| **Empleado** | Solo jornada normal (extras no autorizadas) | 8 horas (9:00 - 18:00) |

**Si las horas extra SE AUTORIZAN:**

| Rol | ¿Qué ve? | Horas mostradas |
|-----|----------|-----------------|
| **Administrador** | Todo tal cual ZKTeco | 13 horas (9:00 - 22:00) |
| **Recursos Humanos** | Todo tal cual ZKTeco | 13 horas (9:00 - 22:00) |
| **Supervisor** | Jornada normal + extras autorizadas | 12 horas (8 normal + 4 extra) |
| **Empleado** | Jornada normal + extras autorizadas | 12 horas (8 normal + 4 extra) |

##### Resumen de Visibilidad:

| Concepto | Admin | RH | Supervisor | Empleado |
|----------|-------|-----|------------|----------|
| Marcajes originales ZKTeco | ✓ | ✓ | ✗ | ✗ |
| Horas jornada normal | ✓ | ✓ | ✓ | ✓ |
| Horas extra NO autorizadas | ✓ | ✓ | ✗ | ✗ |
| Horas extra AUTORIZADAS | ✓ | ✓ | ✓ | ✓ |
| Veladas NO autorizadas | ✓ | ✓ | ✗ | ✗ |
| Veladas AUTORIZADAS | ✓ | ✓ | ✓ | ✓ |

---

#### 3.2.3 Reglas de Asistencia

| Concepto | Regla de Negocio |
|----------|------------------|
| **Retardo** | Llegada después de la hora de entrada + tolerancia configurable |
| **Falta por retardo** | Si llega después del tiempo máximo de retardo configurado |
| **Salida anticipada** | Salida antes de la hora de salida = **FALTA** (incluso 1 minuto antes) |
| **Retardos acumulados** | X retardos = 1 falta (configurable por empresa) |
| **Tolerancia de retardo** | Minutos de gracia configurables por horario |
| **Tiempo máximo de retardo** | Límite para considerar falta en lugar de retardo |

**EXCEPCIÓN IMPORTANTE - Permisos Autorizados:**

La regla de "salida anticipada = falta" **NO aplica** cuando existe un permiso autorizado.

**Las horas del permiso SE CUENTAN COMO TRABAJADAS:**

| Situación | Resultado |
|-----------|-----------|
| Sale a las 12:00 SIN permiso (horario 9:00-18:00) | **FALTA** - 0 horas |
| Sale a las 12:00 CON permiso autorizado (horario 9:00-18:00) | **DÍA COMPLETO** - 8 horas trabajadas |

```
┌────────────────────────────────────────────────────────────────────────┐
│          PERMISO AUTORIZADO = HORAS COMPLETAS                          │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  Ejemplo: Horario 9:00 - 18:00 (8 horas de trabajo)                   │
│           Empleado tiene permiso para salir a las 12:00                │
│                                                                        │
│  9:00 ─────────── 12:00 ─────────────────────── 18:00                 │
│  │     TRABAJÓ     │      PERMISO AUTORIZADO      │                   │
│  │     3 horas     │         5 horas              │                   │
│  │                 │    (CUENTA COMO TRABAJADAS)  │                   │
│  └─────────────────┴──────────────────────────────┘                   │
│                                                                        │
│  RESULTADO: 8 horas trabajadas (3 reales + 5 del permiso)             │
│  El día se considera COMPLETO para efectos de nómina                  │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

El sistema calcula automáticamente:
- Horas realmente trabajadas (entrada hasta salida)
- Horas cubiertas por el permiso (salida hasta fin de horario)
- **Total = Jornada completa** para efectos de pago

#### 3.2.4 Puntualidad y Desayuno

- **Bono de puntualidad**: Se otorga a empleados que llegan **10 minutos antes** de su hora de entrada
- **Registro de desayuno**: Lectura de base de datos de quienes asistieron al desayuno
- **Integración**: Los desayunos se registran como concepto en nómina (aparece en CONTPAQi como cheque, no se paga directo al empleado)

---

### 3.3 Módulo de Vacaciones e Incapacidades

#### 3.3.1 Gestión de Vacaciones

| Funcionalidad | Descripción |
|---------------|-------------|
| Solicitud de vacaciones | Empleado o supervisor puede crear solicitud |
| Período solicitado | Fecha inicio y fecha fin |
| Días a utilizar | Cálculo automático de días hábiles |
| Aprobación | Flujo de autorización por jefe superior |
| Saldo disponible | Validación automática de días disponibles |
| Historial | Registro de todas las vacaciones tomadas |

#### 3.3.2 Gestión de Incapacidades

| Funcionalidad | Descripción |
|---------------|-------------|
| Registro de incapacidad | Tipo, período, días |
| **Evidencia obligatoria** | Carga de documento/archivo de respaldo |
| Aprobación | Autorización por RH con registro de quién aprobó |
| Afectación a nómina | Indicador de si se pagan días o no |

---

### 3.4 Módulo de Autorizaciones

#### 3.4.1 Tipos de Autorizaciones

| Tipo | Descripción | Requiere Evidencia |
|------|-------------|-------------------|
| Cambio de horario | Modificación temporal o permanente del horario | Sí |
| Horas extra | Autorización para laborar tiempo adicional | Sí |
| Veladas | Autorización para trabajo nocturno extendido | Sí |
| Permisos de salida | Autorización para salir antes de la hora | Sí |
| Permisos de entrada | Autorización para llegar después de la hora | Sí |
| Permisos especiales | Otras ausencias autorizadas | Sí |

#### 3.4.2 Momento de la Autorización

**IMPORTANTE:** Las autorizaciones pueden realizarse en dos momentos:

| Momento | Descripción | Ejemplo |
|---------|-------------|---------|
| **Pre-autorización** | Se solicita y aprueba ANTES de que ocurra el evento | Empleado solicita permiso para salir temprano mañana |
| **Post-autorización** | Se solicita y aprueba DESPUÉS de que ocurrió el evento | Empleado trabajó horas extra ayer y hoy solicita autorización |

Ambos flujos son válidos y el sistema debe soportarlos completamente.

#### 3.4.3 Flujo de Autorización

1. **Solicitud**: Creación con motivo, período y tipo (pre o post)
2. **Evidencia**: Carga obligatoria de documento de respaldo
3. **Revisión**: Por jefe superior o RH
4. **Decisión**: Aprobado / Rechazado con registro de:
   - Quién autorizó
   - Quién rechazó (si aplica)
   - Fecha y hora de la decisión
   - Motivo del rechazo (si aplica)
5. **Recálculo automático**: Al aprobar, el sistema recalcula los registros de asistencia afectados

#### 3.4.4 Recálculo de Asistencia por Permisos

Cuando se aprueba un permiso, el sistema **recalcula automáticamente** el registro de asistencia.

**REGLA CLAVE: Las horas del permiso se cuentan como horas trabajadas.**

**Ejemplo - Permiso de salida anticipada:**
- Empleado tiene horario de 9:00 a 18:00 (8 horas de jornada)
- Solicita permiso para salir a las 12:00
- Sin permiso: Salir a las 12:00 = **FALTA** (0 horas)
- Con permiso aprobado: Salir a las 12:00 = **DÍA COMPLETO** (8 horas)

**Reglas de recálculo:**
| Situación | Sin Permiso | Con Permiso Aprobado |
|-----------|-------------|---------------------|
| Salida a las 12:00 (horario hasta 18:00) | Falta (0 horas) | **8 horas trabajadas** (día completo) |
| Entrada a las 11:00 (horario desde 9:00) | Falta o retardo grave | **8 horas trabajadas** (día completo) |
| No asistir un día | Falta | **8 horas trabajadas** (permiso día completo) |

**Cálculo de horas con permiso:**
```
Horas totales = Horas reales trabajadas + Horas cubiertas por permiso
             = Jornada completa (para efectos de nómina)
```

El sistema debe:
- Guardar los marcajes originales del ZKTeco (sin modificar)
- Registrar horas reales trabajadas
- Registrar horas cubiertas por el permiso
- **Sumar ambas como horas trabajadas para nómina**
- Mostrar el estatus correcto (Permiso, no Falta)
- Incluir referencia a la autorización aprobada

---

### 3.5 Módulo de Horas Extra y Veladas

#### 3.5.1 Registro de Horas Extra

| Campo | Descripción |
|-------|-------------|
| Empleado | Persona que laboró tiempo extra |
| Fecha | Día del tiempo extra |
| Hora inicio extra | Hora en que comenzó el tiempo extra |
| Hora fin extra | Hora en que terminó el tiempo extra |
| Horas laboradas | Tiempo real trabajado (calculado) |
| **Evidencia/Justificación** | Documento o firma de por qué se quedó más tiempo |
| Solicitado por | Quién creó la solicitud |
| Autorizado por | Supervisor que aprobó (si aplica) |
| Estatus | **Pendiente** / Aprobado / Rechazado / Pagado |

#### 3.5.2 Flujo de Horas Extra y Veladas

**IMPORTANTE:** Las horas extra y veladas pueden ser pre o post autorizadas.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        FLUJO DE HORAS EXTRA / VELADAS                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. DETECCIÓN AUTOMÁTICA                                                    │
│     ├── ZKTeco registra que empleado salió después de su hora              │
│     ├── Sistema detecta tiempo extra trabajado                              │
│     └── Se crea registro con estatus "PENDIENTE DE AUTORIZACIÓN"           │
│                                                                             │
│  2. VISTA DEL ADMINISTRADOR                                                 │
│     ├── Horas extra PENDIENTES: aparecen en sección separada               │
│     ├── No se muestran en registros de asistencia normales                 │
│     └── Requieren acción de autorización                                   │
│                                                                             │
│  3. PROCESO DE AUTORIZACIÓN                                                 │
│     ├── Supervisor/RH revisa la solicitud                                   │
│     ├── Empleado adjunta evidencia (firma, justificación)                  │
│     └── Se aprueba o rechaza con registro de quién decidió                 │
│                                                                             │
│  4. POST-AUTORIZACIÓN (cuando se aprueba)                                   │
│     ├── Horas extra APARECEN en registros de asistencia                    │
│     ├── Se recalculan las horas del día                                    │
│     ├── Se reflejan en el reporte del administrador                        │
│     └── Se incluyen en el cálculo de nómina                                │
│                                                                             │
│  5. SI SE RECHAZA                                                           │
│     ├── Horas extra NO aparecen en registros de asistencia                 │
│     ├── El día queda con horario normal                                    │
│     └── Se guarda registro del rechazo con motivo                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### 3.5.3 Visualización por Estatus de Autorización

| Estatus | Vista Administrador - Asistencia | Vista Nómina | Acción Requerida |
|---------|----------------------------------|--------------|------------------|
| **Pendiente** | NO aparece en registros normales | NO se calcula | Requiere aprobación |
| **Aprobado** | SÍ aparece en registros de asistencia | SÍ se calcula y paga | Ninguna |
| **Rechazado** | NO aparece | NO se calcula | Ninguna (registro histórico) |
| **Pagado** | SÍ aparece (marcado como pagado) | Ya procesado | Ninguna |

#### 3.5.4 Funcionalidades

- **Detección automática**: El sistema detecta cuando alguien trabaja más allá de su horario
- **Cola de pendientes**: Vista específica de horas extra/veladas por autorizar
- **Pre-autorización**: Posibilidad de autorizar ANTES de que ocurra
- **Post-autorización**: Posibilidad de autorizar DESPUÉS de que ocurrió
- **Registro de evidencia**: Firma o documento obligatorio que justifique la permanencia
- **Modificación de horas**: Capacidad de ajustar las horas registradas con justificación
- **Reporte de horas extra**: Vista consolidada con toda la evidencia y estatus
- **Integración con asistencia**: Al autorizar, las horas aparecen automáticamente en registros

---

### 3.6 Módulo de Nómina

**IMPORTANTE:** El sistema de nómina funciona como **DOS SISTEMAS SEPARADOS** con diferentes niveles de acceso y conceptos.

---

#### 3.6.1 SISTEMA 1: Nómina Básica

**Descripción:** Sistema de control de asistencia y puntualidad. Visible para TODOS los roles autorizados.

##### Conceptos incluidos en Nómina Básica:

| Concepto | Descripción | Período de Cálculo |
|----------|-------------|-------------------|
| **Puntualidad (Desayuno)** | Bono por llegar 10 min antes de la hora | Semanal |
| **Asistencias** | Días trabajados correctamente | Semanal |
| **Retardos** | Llegadas tarde registradas | Mensual |
| **Faltas** | Días no trabajados sin justificación | Mensual |
| **Faltas por retardos** | Conversión de retardos acumulados a faltas | Mensual |
| **Vacaciones** | Días de vacaciones tomados/disponibles | Anual |
| **Incapacidades** | Días por incapacidad médica | Por evento |

##### Regla de Conversión de Retardos:

```
┌────────────────────────────────────────────────────────────────┐
│                    RETARDOS → FALTAS                           │
├────────────────────────────────────────────────────────────────┤
│  Configuración: X retardos = 1 falta (ej: 3 retardos = 1 falta)│
│                                                                │
│  Ejemplo con 3 retardos = 1 falta:                             │
│  ├── Empleado tiene 7 retardos en el mes                       │
│  ├── 7 ÷ 3 = 2 faltas + 1 retardo restante                    │
│  └── Al cierre del mes: 2 faltas adicionales                   │
│                                                                │
│  El cálculo se realiza al CIERRE MENSUAL                       │
└────────────────────────────────────────────────────────────────┘
```

##### Períodos de Cálculo - Nómina Básica:

| Concepto | Período | Día de Corte |
|----------|---------|--------------|
| Asistencia/Puntualidad | **Semanal** | Miércoles a Martes |
| Retardos y Faltas | **Mensual** | Último día del mes |
| Vacaciones | **Anual** | Fecha de aniversario |

---

#### 3.6.2 SISTEMA 2: Nómina Completa

**Descripción:** Sistema completo que incluye TODO lo de la nómina básica MÁS los conceptos de compensación adicional. Visible SOLO para roles autorizados (Admin, RH).

##### Conceptos incluidos en Nómina Completa:

| Concepto | Descripción | Período de Cálculo |
|----------|-------------|-------------------|
| *Todo lo de Nómina Básica* | Puntualidad, faltas, retardos, vacaciones | Según corresponda |
| **Horas Extra** | Tiempo adicional a la jornada (autorizado) | Semanal |
| **Veladas** | Trabajo nocturno extendido (autorizado) | Semanal |
| **Cenas** | Concepto de alimentación nocturna | Semanal |
| **Día Extra** | Día adicional trabajado | Semanal |
| **Día Festivo Trabajado** | Con multiplicador especial | Por evento |
| **Fin de Semana Trabajado** | Con multiplicador especial | Por evento |
| **Bono Semanal** | Bonificación semanal | Semanal |
| **Bono Mensual** | Bonificación mensual | Mensual |

---

#### 3.6.3 Comparativa de Sistemas

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    SISTEMA 1 vs SISTEMA 2                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────┐    ┌─────────────────────────────────────────┐│
│  │   NÓMINA BÁSICA         │    │         NÓMINA COMPLETA                 ││
│  │   (Sistema 1)           │    │         (Sistema 2)                     ││
│  ├─────────────────────────┤    ├─────────────────────────────────────────┤│
│  │ ✓ Puntualidad/Desayuno  │    │ ✓ TODO lo de Nómina Básica              ││
│  │ ✓ Asistencias           │    │ ────────────────────────────            ││
│  │ ✓ Retardos              │    │ ✓ Horas Extra (autorizadas)             ││
│  │ ✓ Faltas                │    │ ✓ Veladas (autorizadas)                 ││
│  │ ✓ Vacaciones            │    │ ✓ Cenas                                 ││
│  │ ✓ Incapacidades         │    │ ✓ Días Extra                            ││
│  │                         │    │ ✓ Días Festivos                         ││
│  │                         │    │ ✓ Fines de Semana                       ││
│  │                         │    │ ✓ Bonos Semanales                       ││
│  │                         │    │ ✓ Bonos Mensuales                       ││
│  ├─────────────────────────┤    ├─────────────────────────────────────────┤│
│  │ Visible para:           │    │ Visible para:                           ││
│  │ • Todos los roles       │    │ • Administrador                         ││
│  │ • Supervisores          │    │ • Recursos Humanos                      ││
│  │ • Empleados (su info)   │    │                                         ││
│  └─────────────────────────┘    └─────────────────────────────────────────┘│
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

#### 3.6.4 Períodos y Cortes de Nómina

| Tipo de Cálculo | Período | Días | Ejemplo |
|-----------------|---------|------|---------|
| **Nómina Semanal** | Miércoles a Martes | 7 días | Mié 8 Ene - Mar 14 Ene |
| **Retardos/Faltas** | Mensual | ~30 días | 1 Ene - 31 Ene |
| **Vacaciones** | Anual | 365 días | Fecha ingreso a fecha ingreso |

##### Proceso de Cierre Semanal (Miércoles a Martes):

1. Se calculan días trabajados de la semana
2. Se registra puntualidad (desayunos)
3. Se suman horas extra y veladas **autorizadas** (solo nómina completa)
4. Se genera reporte/exportación

##### Proceso de Cierre Mensual:

1. Se cuentan retardos del mes
2. Se aplica conversión: X retardos = 1 falta
3. Se suman faltas totales (directas + por retardos)
4. Se actualiza registro del empleado

##### Proceso de Cierre Anual (Vacaciones):

1. Se calculan días de vacaciones correspondientes por antigüedad
2. Se restan días ya tomados
3. Se actualiza saldo disponible

---

### 3.7 Módulo de Reportes

#### 3.7.1 Reportes Disponibles

| Reporte | Descripción |
|---------|-------------|
| Asistencia diaria | Resumen de checadas del día |
| Asistencia por período | Consolidado por rango de fechas |
| Retardos y faltas | Incidencias de asistencia |
| Horas extra | Tiempo adicional con evidencia |
| Prenómina CONTPAQi | Exportación para sistema de nómina |
| Vacaciones | Saldos y días tomados |
| Incapacidades | Registro con documentos |
| Autorización de cambios | Historial de aprobaciones/rechazos |

---

### 3.8 Módulo de Logs y Auditoría

#### 3.8.1 Registro de Acciones

Todas las operaciones del sistema quedan registradas con:

| Campo | Descripción |
|-------|-------------|
| Fecha y hora | Timestamp de la acción |
| Usuario | Quién realizó la acción |
| Módulo | En qué parte del sistema |
| Acción | Crear / Modificar / Eliminar / Aprobar / Rechazar |
| Registro afectado | ID del registro modificado |
| Valores anteriores | Estado previo al cambio |
| Valores nuevos | Estado posterior al cambio |
| IP de origen | Dirección desde donde se realizó |

---

## 4. Integraciones

### 4.1 Dispositivos ZKTeco

- Sincronización automática de marcajes
- Lectura de empleados registrados
- Registro de huellas y tarjetas

### 4.2 CONTPAQi Nóminas

- Exportación de prenómina en formato compatible
- Mapeo de conceptos de nómina
- Códigos de empleado configurables
- **Desayunos**: Se registran como cheque en CONTPAQi (aparece pero no se paga directo)

### 4.3 BBVA (Investigación Pendiente)

- Evaluar disponibilidad de API para pagos automáticos de nómina
- Integración para dispersión de pagos (a confirmar viabilidad)

---

## 5. Configuraciones Generales

### 5.1 Parámetros del Sistema

| Parámetro | Descripción | Nivel |
|-----------|-------------|-------|
| Día de corte de nómina | Día del período de pago | General |
| Retardos para falta | Cantidad de retardos = 1 falta | General |
| Tiempo máximo de retardo | Minutos antes de considerar falta | Por horario |
| Tolerancia de entrada | Minutos de gracia | Por horario |
| Días de vacaciones iniciales | Días base por antigüedad | Por empresa |

---

## 6. Roles y Permisos

### 6.1 Roles del Sistema

| Rol | Descripción |
|-----|-------------|
| Administrador | Acceso total al sistema |
| Recursos Humanos | Gestión de empleados, nómina completa, autorizaciones |
| Supervisor | Autorizaciones de su equipo, nómina básica de su área |
| Empleado | Consulta de su información, solicitudes |

### 6.2 Permisos por Módulo

| Módulo | Admin | RH | Supervisor | Empleado |
|--------|-------|-----|------------|----------|
| Empleados (edición) | Total | Total | Solo su equipo | Solo lectura propia |
| Asistencia | Total | Total | Su equipo | Solo propia |
| Vacaciones | Total | Total | Aprobar su equipo | Solicitar |
| Horas extra | Total | Total | Aprobar su equipo | Ver propias |
| **Nómina Básica (Sistema 1)** | Sí | Sí | Sí (su equipo) | Sí (solo propia) |
| **Nómina Completa (Sistema 2)** | Sí | Sí | **NO** | **NO** |
| Reportes | Todos | Todos | Su área | Propios |
| Configuración | Sí | Parcial | No | No |
| Logs | Sí | Lectura | No | No |

### 6.3 Acceso a los Dos Sistemas de Nómina

```
┌────────────────────────────────────────────────────────────────────────┐
│                     ACCESO A SISTEMAS DE NÓMINA                        │
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  SISTEMA 1 - NÓMINA BÁSICA                                             │
│  (Puntualidad, Faltas, Retardos, Vacaciones)                          │
│  ├── Administrador ────────── ✓ Acceso total                          │
│  ├── Recursos Humanos ─────── ✓ Acceso total                          │
│  ├── Supervisor ───────────── ✓ Solo su equipo                        │
│  └── Empleado ─────────────── ✓ Solo su información                   │
│                                                                        │
│  SISTEMA 2 - NÓMINA COMPLETA                                           │
│  (Horas extra, Veladas, Cenas, Bonos + todo lo básico)                │
│  ├── Administrador ────────── ✓ Acceso total                          │
│  ├── Recursos Humanos ─────── ✓ Acceso total                          │
│  ├── Supervisor ───────────── ✗ SIN ACCESO                            │
│  └── Empleado ─────────────── ✗ SIN ACCESO                            │
│                                                                        │
└────────────────────────────────────────────────────────────────────────┘
```

**Nota:** Esta separación permite que supervisores y empleados vean información de asistencia sin exponer datos sensibles de compensación adicional.

---

## 7. Tecnología

### 7.1 Stack Tecnológico

| Componente | Tecnología |
|------------|------------|
| Backend | Laravel 11 (PHP 8.2+) |
| Frontend | Vue.js con Inertia.js |
| Base de datos | MySQL 8.0 |
| Estilos | Tailwind CSS |
| Autenticación | Laravel Breeze + Spatie Permissions |
| Servidor | Linux con Nginx |

### 7.2 Seguridad

- Autenticación con sesiones seguras
- Control de acceso basado en roles (RBAC)
- Cifrado de datos sensibles
- Logs de auditoría completos
- Validación de datos en servidor
- Protección contra CSRF, XSS, SQL Injection

---

## 8. Entregables

### 8.1 Software

1. Sistema web PINKY completamente funcional
2. Código fuente documentado
3. Base de datos con estructura y catálogos iniciales
4. Scripts de sincronización con ZKTeco
5. Exportaciones para CONTPAQi

### 8.2 Documentación

1. Manual de usuario por rol
2. Manual de administrador
3. Documentación técnica de API (si aplica)
4. Guía de instalación y configuración

### 8.3 Capacitación

1. Capacitación a usuarios finales
2. Capacitación a administradores del sistema
3. Transferencia de conocimiento técnico

---

## 9. Consideraciones Especiales

### 9.1 Reglas de Negocio Críticas

**Asistencia y Puntualidad:**
1. **Salida anticipada = Falta**: Cualquier salida antes de la hora programada (incluso 1 minuto) se considera falta completa **EXCEPTO si tiene permiso autorizado**
2. **Retardos acumulados**: La cantidad configurable de retardos equivale a una falta (ej: 3 retardos = 1 falta)
3. **Puntualidad para desayuno**: Llegar 10 minutos antes de la hora de entrada
4. **Desayunos en CONTPAQi**: Se registran como concepto tipo cheque, no se pagan directo

**Autorizaciones:**
5. **Evidencia obligatoria**: Todas las autorizaciones requieren documento de respaldo
6. **Jefe superior obligatorio**: Cada empleado debe tener asignado su supervisor directo
7. **Autorizaciones pre y post**: Los permisos, horas extra y veladas pueden autorizarse ANTES o DESPUÉS del suceso
8. **Permisos = Horas completas**: Cuando se autoriza un permiso, las horas del permiso SE CUENTAN COMO TRABAJADAS (día completo para nómina)
9. **Horas extra pendientes**: Las horas extra detectadas NO aparecen en registros de asistencia hasta ser autorizadas
10. **Visibilidad post-autorización**: Una vez autorizadas las horas extra/veladas, aparecen automáticamente en los registros del administrador

**Períodos de Cálculo (CRÍTICO):**
11. **Nómina semanal**: Se calcula de **MIÉRCOLES a MARTES**
12. **Retardos y faltas**: Se calculan **MENSUALMENTE** (no semanal)
13. **Vacaciones**: Se calculan **ANUALMENTE** por fecha de ingreso
14. **Conversión retardos→faltas**: Se aplica al **CIERRE DEL MES**, no antes

**Dos Sistemas de Nómina:**
15. **Sistema 1 (Básico)**: Solo puntualidad, faltas, retardos, vacaciones - visible para todos
16. **Sistema 2 (Completo)**: Incluye horas extra, veladas, cenas, bonos - solo Admin y RH

**Visualización de Horas por Rol:**
17. **Admin y RH ven TODO**: Horas tal cual están en ZKTeco, incluyendo extras/veladas no autorizadas
18. **Supervisor y Empleado ven FILTRADO**: Solo jornada normal + extras/veladas YA AUTORIZADAS
19. **Extras no autorizadas son invisibles**: Para roles que no son Admin/RH, las horas extra y veladas pendientes no aparecen en el registro diario

### 9.2 Comportamiento del Sistema con Autorizaciones

**IMPORTANTE:** Con permiso autorizado, las horas del permiso se cuentan como trabajadas.

| Escenario | Sin Autorización | Con Autorización Aprobada |
|-----------|------------------|---------------------------|
| Empleado sale a las 12:00 (horario 9-18) | FALTA (0 horas) | **8 horas trabajadas** (día completo) |
| Empleado llega a las 11:00 (horario desde 9:00) | Falta o retardo grave | **8 horas trabajadas** (día completo) |
| Empleado no asiste un día | FALTA | **8 horas trabajadas** (permiso día completo) |
| Empleado se queda hasta las 21:00 (horario 9-18) | Horas extra PENDIENTES (no visibles) | Horas extra VISIBLES en registros y nómina |
| Empleado trabaja velada hasta las 2am | Velada PENDIENTE (no visible) | Velada VISIBLE en registros y nómina |

### 9.3 Pendientes por Definir

1. Integración con API de BBVA para pagos automáticos (investigar viabilidad)
2. Detalles específicos de conexión con CONTPAQi
3. Flujos de aprobación multinivel (si se requieren)

---

## 10. Cotización

### 10.1 Inversión Total

| Concepto | Monto |
|----------|-------|
| **Desarrollo del Sistema PINKY** | **$40,000.00 MXN** |

*Precio en Pesos Mexicanos. IVA no incluido.*

### 10.2 Lo que incluye esta cotización

- Desarrollo completo del Sistema PINKY con todos los módulos descritos en este documento
- Sistema 1: Nómina Básica (puntualidad, faltas, retardos, vacaciones)
- Sistema 2: Nómina Completa (horas extra, veladas, cenas, bonos)
- Integración con dispositivos ZKTeco (sincronización de marcajes)
- Exportación de datos en formato compatible para CONTPAQi (archivos de exportación)
- Módulo de autorizaciones con evidencia
- Sistema de roles y permisos
- Logs de auditoría
- Capacitación inicial
- Documentación del sistema

### 10.3 EXCLUSIONES IMPORTANTES

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ⚠️  NO INCLUIDO EN ESTA COTIZACIÓN                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. INTEGRACIÓN DIRECTA CON CONTPAQi                                        │
│     ├── Esta fase NO incluye conexión directa API con CONTPAQi             │
│     ├── Se entrega: Exportación de archivos en formato compatible          │
│     ├── NO se entrega: Sincronización automática con CONTPAQi              │
│     └── Requiere: FASE 2 a negociar por separado                           │
│                                                                             │
│  2. INTEGRACIÓN CON BBVA PARA PAGOS AUTOMÁTICOS                             │
│     ├── Esta fase NO incluye dispersión automática de nómina               │
│     ├── Requiere investigación de viabilidad con API de BBVA               │
│     └── Requiere: FASE 2 a negociar por separado                           │
│                                                                             │
│  NOTA: Ambas integraciones pueden desarrollarse en una siguiente fase      │
│        con alcance y cotización a definir una vez completada la Fase 1.    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 11. Términos Contractuales

### 11.1 Seguimiento del Proyecto

| Tipo de Reunión | Frecuencia | Modalidad | Vigencia |
|-----------------|------------|-----------|----------|
| **Junta semanal de seguimiento** | 1 vez por semana | **PRESENCIAL** | Hasta fin de contrato |
| **Juntas de aclaración/urgencias** | Las necesarias | Zoom / Google Meet | Hasta fin de contrato |

### 11.2 Detalle de Reuniones

**Juntas Semanales Presenciales:**
- Una (1) junta presencial por semana durante toda la vigencia del contrato
- Revisión de avances del proyecto
- Demostración de funcionalidades desarrolladas
- Aclaración de dudas y ajustes de requerimientos
- Ubicación a acordar con el cliente

**Juntas Virtuales (Zoom/Meet):**
- Disponibles según sea necesario durante el proyecto
- Para aclaraciones urgentes o temas puntuales
- Sin límite de cantidad mientras dure el contrato
- Coordinación previa por mensaje/correo

### 11.3 Forma de Pago

| Concepto | Porcentaje | Monto | Momento |
|----------|------------|-------|---------|
| Anticipo | 50% | $20,000.00 MXN | Al firmar contrato |
| Finiquito | 50% | $20,000.00 MXN | Al entregar sistema |

### 11.4 Garantía

- **Período de garantía:** 30 días después de la entrega final
- **Cobertura:** Corrección de bugs y errores del sistema
- **No incluye:** Nuevas funcionalidades o cambios de alcance

### 11.5 Fases Futuras

Las siguientes funcionalidades quedan fuera del alcance actual y podrán negociarse como fases adicionales:

| Fase | Descripción | Estatus |
|------|-------------|---------|
| **Fase 2A** | Integración directa con CONTPAQi (API/conexión automática) | A negociar |
| **Fase 2B** | Integración con BBVA para dispersión automática de nómina | A negociar (sujeto a viabilidad) |

---

## 12. Términos y Condiciones Generales

### 12.1 Propiedad Intelectual
- El código fuente desarrollado será propiedad del cliente una vez liquidado el proyecto
- Las librerías de código abierto utilizadas mantienen sus licencias originales

### 12.2 Confidencialidad
- Ambas partes se comprometen a mantener confidencial la información del proyecto
- No se divulgará información sensible a terceros sin autorización

### 12.3 Modificaciones al Alcance
- Cualquier cambio al alcance definido en este documento deberá ser acordado por escrito
- Los cambios de alcance pueden afectar el costo y tiempo de entrega

---

## 13. Firmas de Aceptación

| Rol | Nombre | Firma | Fecha |
|-----|--------|-------|-------|
| Cliente - Representante | | | |
| Cliente - Responsable Técnico | | | |
| Proveedor - Gerente de Proyecto | | | |
| Proveedor - Líder Técnico | | | |

---

**Documento preparado por:** Overcloud
**Versión del documento:** 1.0
**Última actualización:** Enero 2026
