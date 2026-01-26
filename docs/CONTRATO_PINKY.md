# PROPUESTA DE DESARROLLO
# Sistema de Gestión de Nómina y Asistencia - PINKY

---

**Fecha:** Enero 2026
**Cliente:** ________________________________
**Proveedor:** Overcloud
**Versión:** 1.0

---

## 1. OBJETO DEL CONTRATO

Desarrollo del Sistema de Gestión de Nómina y Asistencia **PINKY**, que permitirá:

- Control de asistencia mediante integración con dispositivos ZKTeco
- Gestión de empleados con jerarquía organizacional
- Cálculo automático de nómina (puntualidad, faltas, retardos, vacaciones)
- Sistema de autorizaciones para horas extra, veladas y permisos
- Generación de reportes y exportaciones para CONTPAQi
- Auditoría completa de operaciones

---

## 2. ALCANCE FUNCIONAL

### 2.1 Módulo de Empleados

**Campos del empleado:**
- Datos personales (nombre, email, teléfono)
- Número de empleado y código CONTPAQi
- ID ZKTeco para sincronización
- Departamento, puesto y tipo de posición
- **Jefe superior** (obligatorio)
- Horario y tipo de horario asignado
- Fecha de ingreso
- Días de vacaciones (correspondientes, tomados, disponibles)
- Tarifas (hora normal, hora extra, día festivo)

**Funcionalidades:**
- Edición masiva de empleados con filtros avanzados
- Historial de cambios por empleado

---

### 2.2 Módulo de Asistencia

**Reglas de asistencia:**

| Concepto | Regla |
|----------|-------|
| Retardo | Llegada después de hora de entrada + tolerancia |
| Falta por salida anticipada | Salir antes de la hora = **FALTA** (incluso 1 minuto) |
| Retardos acumulados | X retardos = 1 falta (configurable) |
| Tiempo máximo de retardo | Configurable por horario |

**Puntualidad (Desayuno):**
- Bono para quien llega **10 minutos antes** de su hora
- Lectura de base de datos de asistencia a desayuno
- Se registra en CONTPAQi como cheque (no pago directo)

**Visualización por rol:**

| Rol | Visualización |
|-----|---------------|
| Admin / RH | Todo tal cual ZKTeco (incluye extras no autorizadas) |
| Supervisor / Empleado | Solo jornada normal + extras/veladas autorizadas |

---

### 2.3 Módulo de Autorizaciones

**Tipos:** Horas extra, veladas, permisos de entrada/salida, cambios de horario, incapacidades, vacaciones.

**Características:**
- Autorizaciones **antes o después** del suceso (pre y post autorización)
- **Evidencia obligatoria** (archivo adjunto)
- Registro de quién autorizó/rechazó con fecha y motivo
- **Permiso autorizado = horas completas trabajadas** para nómina

**Ejemplo:**
> Empleado con horario 9:00-18:00 tiene permiso para salir a las 12:00.
> Resultado: Se cuentan **8 horas trabajadas** (día completo).

---

### 2.4 Módulo de Horas Extra y Veladas

- Detección automática cuando ZKTeco registra salida tardía
- Horas extra **pendientes** NO aparecen en registros hasta ser autorizadas
- Al autorizar, aparecen automáticamente en vista del administrador
- Requiere evidencia/justificación con firma

---

### 2.5 Módulo de Nómina

**DOS SISTEMAS SEPARADOS:**

| Sistema | Conceptos | Visible para |
|---------|-----------|--------------|
| **Sistema 1: Básico** | Puntualidad, asistencias, retardos, faltas, vacaciones, incapacidades | Todos los roles |
| **Sistema 2: Completo** | Todo lo básico + horas extra, veladas, cenas, bonos | Solo Admin y RH |

**Períodos de cálculo:**

| Concepto | Período | Corte |
|----------|---------|-------|
| Nómina | Semanal | Miércoles a Martes |
| Retardos y Faltas | Mensual | Último día del mes |
| Vacaciones | Anual | Fecha de aniversario |

**Conversión de retardos:** Se aplica al cierre mensual (ej: 3 retardos = 1 falta).

---

### 2.6 Módulos Adicionales

- **Reportes:** Asistencia, retardos, faltas, horas extra, prenómina, vacaciones
- **Logs de auditoría:** Registro de todas las acciones con usuario, fecha, IP y valores anteriores/nuevos

---

## 3. EXCLUSIONES

**NO INCLUIDO en esta propuesta:**

| Exclusión | Descripción |
|-----------|-------------|
| **Integración directa CONTPAQi** | No incluye conexión API. Se entrega exportación de archivos compatible. |
| **Integración BBVA** | No incluye dispersión automática de nómina. Requiere investigación de viabilidad. |

> Ambas funcionalidades pueden desarrollarse en una **Fase 2** con alcance y cotización por separado.

---

## 4. INVERSIÓN Y FORMA DE PAGO

### 4.1 Inversión Total: **$40,000.00 MXN**

*(IVA no incluido)*

### 4.2 Pago por Fases

| Fase | Entregable | Pago |
|------|------------|------|
| **Fase 1** | Módulo de Empleados + Integración ZKTeco + Estructura base | **$10,000 MXN** |
| **Fase 2** | Módulo de Asistencia + Reglas de puntualidad/faltas/retardos | **$10,000 MXN** |
| **Fase 3** | Módulo de Autorizaciones + Horas extra + Veladas + Permisos | **$10,000 MXN** |
| **Fase 4** | Módulo de Nómina (ambos sistemas) + Reportes + Logs + Entrega final | **$10,000 MXN** |
| | **TOTAL** | **$40,000 MXN** |

**Condiciones:**
- Cada fase se paga al **inicio** de la misma
- La siguiente fase inicia una vez liquidada la fase actual
- La entrega de cada fase incluye demostración funcional

---

## 5. SEGUIMIENTO DEL PROYECTO

| Tipo de Reunión | Frecuencia | Modalidad |
|-----------------|------------|-----------|
| **Junta de seguimiento** | Semanal | **PRESENCIAL** |
| **Juntas de aclaración** | Las necesarias | Zoom / Google Meet |

**Vigencia:** Hasta la entrega final del proyecto.

---

## 6. GARANTÍA Y SOPORTE

- **Garantía:** 30 días posteriores a la entrega final
- **Cobertura:** Corrección de bugs y errores del sistema
- **Exclusiones:** Nuevas funcionalidades o cambios de alcance

---

## 7. TECNOLOGÍA

| Componente | Tecnología |
|------------|------------|
| Backend | Laravel 11 (PHP 8.2+) |
| Frontend | Vue.js + Inertia.js |
| Base de datos | MySQL 8.0 |
| Estilos | Tailwind CSS |
| Servidor | Linux + Nginx |

---

## 8. ENTREGABLES

1. Sistema web PINKY funcional
2. Código fuente documentado
3. Base de datos con estructura y catálogos
4. Scripts de sincronización ZKTeco
5. Exportaciones para CONTPAQi
6. Manual de usuario
7. Capacitación inicial

---

## 9. TÉRMINOS GENERALES

### 9.1 Propiedad Intelectual
El código desarrollado será propiedad del cliente una vez liquidado el proyecto completo.

### 9.2 Confidencialidad
Ambas partes mantendrán confidencial la información del proyecto.

### 9.3 Cambios de Alcance
Modificaciones al alcance deberán acordarse por escrito y pueden afectar costo y tiempo.

---

## 10. FIRMAS DE ACEPTACIÓN

Al firmar este documento, ambas partes aceptan los términos aquí descritos.

&nbsp;

**POR EL CLIENTE:**

Nombre: ________________________________________

Cargo: _________________________________________

Firma: _________________________________________

Fecha: _________________________________________

&nbsp;

**POR OVERCLOUD:**

Nombre: ________________________________________

Cargo: _________________________________________

Firma: _________________________________________

Fecha: _________________________________________

---

*Documento generado por Overcloud - Enero 2026*
