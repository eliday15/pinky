# PROPUESTA TÉCNICA COMPLETA
# Sistema de Gestión de Nómina y Asistencia - PINKY

---

| | |
|---|---|
| **Cliente:** | Vestidos Pinky |
| **Proveedor:** | Overcloud |
| **Fecha:** | Enero 2026 |
| **Versión:** | 1.0 |

---

## 1. RESUMEN EJECUTIVO

Sistema integral para automatizar el control de asistencia, gestión de empleados, cálculo de nómina y generación de reportes, con integración a dispositivos ZKTeco y exportación compatible con CONTPAQi.

---

## 2. MÓDULOS DEL SISTEMA

---

### 2.1 MÓDULO DE EMPLEADOS

#### Información del Empleado

| Campo | Descripción |
|-------|-------------|
| Número de empleado | Identificador único interno |
| Código CONTPAQi | Código para integración con nómina |
| ID ZKTeco | Identificador en reloj checador |
| Nombre completo | Nombre y apellidos |
| Email / Teléfono | Datos de contacto |
| Fecha de ingreso | Alta en la empresa |
| Fecha de baja | Terminación (si aplica) |
| Departamento | Área organizacional |
| Puesto | Posición laboral |
| Tipo de posición | Clasificación del puesto |
| **Jefe superior** | Supervisor directo (obligatorio) |
| Horario asignado | Horario de trabajo |
| Tipo de horario | Fijo / Flexible |
| Días de vacaciones | Correspondientes / Tomados / Disponibles |
| Tarifas | Hora normal / Hora extra / Día festivo |
| Estatus | Activo / Inactivo / Terminado |

#### Funcionalidades

- **Edición masiva:** Filtrar y modificar múltiples empleados simultáneamente
- **Filtros avanzados:** Por departamento, puesto, horario, estatus, jefe
- **Historial de cambios:** Registro de modificaciones por empleado

---

### 2.2 MÓDULO DE ASISTENCIA Y PUNTUALIDAD

#### Registro de Asistencia

| Campo | Descripción |
|-------|-------------|
| Fecha de trabajo | Día del registro |
| Hora de entrada/salida | Check-in y check-out |
| Horas trabajadas | Cálculo automático |
| Horas extra autorizadas | Solo si están aprobadas |
| Minutos de retardo | Diferencia con hora de entrada |
| Estatus | Presente / Retardo / Falta / Permiso / Vacaciones |
| Marcajes originales | Datos crudos del ZKTeco |
| Horas reales trabajadas | Tiempo físico trabajado |
| Horas cubiertas por permiso | Tiempo del permiso (cuenta como trabajado) |
| Horas totales para nómina | Suma de reales + permiso |

#### Reglas de Asistencia

| Regla | Descripción |
|-------|-------------|
| **Retardo** | Llegada después de hora + tolerancia |
| **Falta por retardo excesivo** | Superar tiempo máximo de retardo |
| **Salida anticipada = FALTA** | Salir antes de la hora (incluso 1 minuto) |
| **Retardos acumulados** | X retardos = 1 falta (configurable) |

#### Excepción: Permisos Autorizados

Cuando existe un permiso autorizado, las horas del permiso **SE CUENTAN COMO TRABAJADAS**.

**Ejemplo:**
- Horario: 9:00 - 18:00 (8 horas)
- Empleado tiene permiso para salir a las 12:00
- **Sin permiso:** Falta (0 horas)
- **Con permiso:** 8 horas trabajadas (día completo)

#### Puntualidad y Desayuno

- **Bono de puntualidad:** Llegar 10 minutos antes de la hora de entrada
- **Registro de desayuno:** Lectura de base de datos de asistentes
- **En CONTPAQi:** Se registra como cheque (no pago directo)

#### Visualización por Rol

| Rol | ¿Qué ve? |
|-----|----------|
| **Administrador** | Todo tal cual ZKTeco (incluye extras no autorizadas) |
| **Recursos Humanos** | Todo tal cual ZKTeco |
| **Supervisor** | Solo jornada normal + extras/veladas YA autorizadas |
| **Empleado** | Solo su jornada normal + sus extras autorizadas |

---

### 2.3 MÓDULO DE VACACIONES E INCAPACIDADES

#### Vacaciones

| Funcionalidad | Descripción |
|---------------|-------------|
| Solicitud | Por empleado o supervisor |
| Período | Fecha inicio y fin |
| Días a utilizar | Cálculo automático de días hábiles |
| Aprobación | Flujo por jefe superior |
| Validación | Verificación de saldo disponible |

#### Incapacidades

| Funcionalidad | Descripción |
|---------------|-------------|
| Registro | Tipo, período, días |
| **Evidencia obligatoria** | Documento de respaldo |
| Aprobación | Por RH con registro de quién aprobó |
| Afectación a nómina | Indicador de pago |

---

### 2.4 MÓDULO DE AUTORIZACIONES

#### Tipos de Autorizaciones

| Tipo | Requiere Evidencia |
|------|-------------------|
| Horas extra | Sí |
| Veladas | Sí |
| Permisos de salida | Sí |
| Permisos de entrada | Sí |
| Cambio de horario | Sí |
| Permisos especiales | Sí |

#### Momento de Autorización

| Momento | Descripción |
|---------|-------------|
| **Pre-autorización** | Se aprueba ANTES del evento |
| **Post-autorización** | Se aprueba DESPUÉS del evento |

Ambos flujos son válidos.

#### Flujo de Autorización

1. Solicitud con motivo y período
2. Carga de evidencia (obligatoria)
3. Revisión por jefe superior o RH
4. Decisión con registro de:
   - Quién autorizó/rechazó
   - Fecha y hora
   - Motivo (si rechazó)
5. Recálculo automático de asistencia

#### Recálculo con Permisos

| Situación | Sin Permiso | Con Permiso Aprobado |
|-----------|-------------|---------------------|
| Salida a las 12:00 (horario hasta 18:00) | Falta | **8 horas** (día completo) |
| Entrada a las 11:00 (horario desde 9:00) | Falta/Retardo grave | **8 horas** (día completo) |
| No asistir un día | Falta | **8 horas** (permiso completo) |

---

### 2.5 MÓDULO DE HORAS EXTRA Y VELADAS

#### Registro

| Campo | Descripción |
|-------|-------------|
| Empleado | Persona que laboró extra |
| Fecha | Día del tiempo extra |
| Hora inicio/fin | Período del tiempo extra |
| Horas laboradas | Cálculo automático |
| Evidencia | Documento o firma obligatoria |
| Autorizado por | Supervisor que aprobó |
| Estatus | Pendiente / Aprobado / Rechazado / Pagado |

#### Flujo

1. **Detección automática:** ZKTeco registra salida tardía
2. **Registro pendiente:** Se crea con estatus "Pendiente"
3. **Autorización:** Supervisor/RH revisa y decide
4. **Post-autorización:** Si se aprueba, aparece en registros de asistencia
5. **Si se rechaza:** No aparece, día queda con horario normal

#### Visibilidad por Estatus

| Estatus | Vista Asistencia | Vista Nómina |
|---------|------------------|--------------|
| **Pendiente** | NO aparece | NO se calcula |
| **Aprobado** | SÍ aparece | SÍ se calcula |
| **Rechazado** | NO aparece | NO se calcula |

---

### 2.6 MÓDULO DE NÓMINA

#### Dos Sistemas Separados

**SISTEMA 1 - NÓMINA BÁSICA**

| Concepto | Período |
|----------|---------|
| Puntualidad (Desayuno) | Semanal |
| Asistencias | Semanal |
| Retardos | Mensual |
| Faltas | Mensual |
| Faltas por retardos | Mensual |
| Vacaciones | Anual |
| Incapacidades | Por evento |

*Visible para: Todos los roles*

**SISTEMA 2 - NÓMINA COMPLETA**

| Concepto | Período |
|----------|---------|
| Todo lo de Nómina Básica | - |
| Horas Extra (autorizadas) | Semanal |
| Veladas (autorizadas) | Semanal |
| Cenas | Semanal |
| Día Extra | Semanal |
| Día Festivo Trabajado | Por evento |
| Fin de Semana Trabajado | Por evento |
| Bono Semanal | Semanal |
| Bono Mensual | Mensual |

*Visible para: Solo Administrador y RH*

#### Períodos de Cálculo

| Cálculo | Período | Corte |
|---------|---------|-------|
| **Nómina** | Semanal | Miércoles a Martes |
| **Retardos/Faltas** | Mensual | Último día del mes |
| **Vacaciones** | Anual | Fecha de aniversario |

#### Conversión de Retardos

- Configuración: X retardos = 1 falta
- Se aplica al **cierre mensual**
- Ejemplo: 7 retardos ÷ 3 = 2 faltas + 1 retardo restante

---

### 2.7 MÓDULO DE REPORTES

| Reporte | Descripción |
|---------|-------------|
| Asistencia diaria | Resumen de checadas del día |
| Asistencia por período | Consolidado por rango de fechas |
| Retardos y faltas | Incidencias de asistencia |
| Horas extra | Tiempo adicional con evidencia |
| Prenómina CONTPAQi | Exportación para sistema de nómina |
| Vacaciones | Saldos y días tomados |
| Incapacidades | Registro con documentos |
| Autorizaciones | Historial de aprobaciones/rechazos |

---

### 2.8 MÓDULO DE LOGS Y AUDITORÍA

Todas las operaciones quedan registradas:

| Campo | Descripción |
|-------|-------------|
| Fecha y hora | Timestamp de la acción |
| Usuario | Quién realizó la acción |
| Módulo | Parte del sistema |
| Acción | Crear / Modificar / Eliminar / Aprobar / Rechazar |
| Registro afectado | ID del registro |
| Valores anteriores | Estado previo |
| Valores nuevos | Estado posterior |
| IP de origen | Dirección de conexión |

---

## 3. ROLES Y PERMISOS

| Módulo | Admin | RH | Supervisor | Empleado |
|--------|-------|-----|------------|----------|
| Empleados | Total | Total | Su equipo | Solo lectura propia |
| Asistencia | Total | Total | Su equipo | Solo propia |
| Vacaciones | Total | Total | Aprobar equipo | Solicitar |
| Horas extra | Total | Total | Aprobar equipo | Ver propias |
| Nómina Básica | Sí | Sí | Sí (equipo) | Sí (propia) |
| Nómina Completa | Sí | Sí | **NO** | **NO** |
| Reportes | Todos | Todos | Su área | Propios |
| Configuración | Sí | Parcial | No | No |
| Logs | Sí | Lectura | No | No |

---

## 4. INTEGRACIONES

### 4.1 Dispositivos ZKTeco
- Sincronización automática de marcajes
- Lectura de empleados registrados

### 4.2 CONTPAQi Nóminas
- Exportación de prenómina en formato compatible
- Mapeo de conceptos de nómina
- Desayunos como cheque (no pago directo)

---

## 5. EXCLUSIONES

| Exclusión | Descripción | Fase Futura |
|-----------|-------------|-------------|
| **Integración directa CONTPAQi** | No incluye conexión API automática | Fase 2 |
| **Integración BBVA** | No incluye dispersión automática de nómina | Fase 2 |

*Ambas funcionalidades pueden desarrollarse en fase posterior con cotización separada.*

---

## 6. INVERSIÓN Y FORMA DE PAGO

### Inversión Total: $40,000.00 MXN

*(IVA no incluido)*

### Pago por Fases

| Fase | Entregable | Pago |
|------|------------|------|
| **Fase 1** | Empleados + ZKTeco + Estructura base | $10,000 MXN |
| **Fase 2** | Asistencia + Puntualidad + Faltas + Retardos | $10,000 MXN |
| **Fase 3** | Autorizaciones + Horas extra + Veladas + Permisos | $10,000 MXN |
| **Fase 4** | Nómina (ambos sistemas) + Reportes + Logs | $10,000 MXN |
| | **TOTAL** | **$40,000 MXN** |

*Cada fase se paga al inicio. La siguiente fase inicia una vez liquidada la anterior.*

---

## 7. SEGUIMIENTO

| Reunión | Frecuencia | Modalidad |
|---------|------------|-----------|
| Junta de seguimiento | Semanal | **PRESENCIAL** |
| Juntas de aclaración | Las necesarias | Zoom / Google Meet |

*Vigencia: Hasta entrega final del proyecto.*

---

## 8. GARANTÍA

- **Período:** 30 días posteriores a entrega final
- **Cobertura:** Corrección de bugs y errores
- **Exclusiones:** Nuevas funcionalidades o cambios de alcance

---

## 9. TECNOLOGÍA

| Componente | Tecnología |
|------------|------------|
| Backend | Laravel 11 (PHP 8.2+) |
| Frontend | Vue.js + Inertia.js |
| Base de datos | MySQL 8.0 |
| Estilos | Tailwind CSS |
| Servidor | Linux + Nginx |

---

## 10. ENTREGABLES

1. Sistema web PINKY funcional
2. Código fuente documentado
3. Base de datos con estructura y catálogos
4. Scripts de sincronización ZKTeco
5. Exportaciones para CONTPAQi
6. Manual de usuario
7. Capacitación inicial

---

## 11. FIRMAS DE ACEPTACIÓN

&nbsp;

**POR VESTIDOS PINKY:**

Nombre: ________________________________________

Firma: _________________________________________

Fecha: _________________________________________

&nbsp;

**POR OVERCLOUD:**

Nombre: ________________________________________

Firma: _________________________________________

Fecha: _________________________________________

---

*Documento preparado por Overcloud - Enero 2026*
