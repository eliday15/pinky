<?php

namespace App\Support;

/**
 * Translates raw database column names into the Spanish labels the payroll
 * team actually uses, so the "Cambios" panel reads like a change log instead
 * of a database dump.
 */
class AuditFieldLabels
{
    /**
     * Column name to label. Keys are checked verbatim first, then without a
     * trailing `_id`, so `department_id` falls back to `department`.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        // Identity
        'full_name' => 'Nombre completo',
        'employee_number' => 'Numero de empleado',
        'contpaqi_code' => 'Codigo Contpaq',
        'name' => 'Nombre',
        'email' => 'Correo',
        'phone' => 'Telefono',
        'birth_date' => 'Fecha de nacimiento',
        'hire_date' => 'Fecha de ingreso',
        'termination_date' => 'Fecha de baja',
        'curp' => 'CURP',
        'rfc' => 'RFC',
        'nss' => 'NSS',
        'status' => 'Estatus',
        'is_active' => 'Activo',
        'notes' => 'Notas',
        'description' => 'Descripcion',

        // Org
        'department' => 'Departamento',
        'position' => 'Puesto',
        'schedule' => 'Horario',
        'supervisor' => 'Supervisor',
        'user' => 'Usuario',
        'employee' => 'Empleado',

        // Money
        'daily_salary' => 'Sueldo diario',
        'monthly_salary' => 'Sueldo mensual',
        'hourly_rate' => 'Costo por hora',
        'base_salary' => 'Sueldo base',
        'gross_total' => 'Total bruto',
        'net_total' => 'Total neto',
        'total_deductions' => 'Total deducciones',
        'total_perceptions' => 'Total percepciones',
        'amount' => 'Monto',
        'fixed_amount' => 'Monto fijo',
        'cash_amount' => 'Monto en efectivo',
        'transfer_amount' => 'Monto por transferencia',
        'imss_deduction' => 'Deduccion IMSS',
        'isr_deduction' => 'Deduccion ISR',
        'pays_via_transfer' => 'Paga por transferencia',
        'payment_period' => 'Periodo de pago',

        // Attendance
        'check_in' => 'Entrada',
        'check_out' => 'Salida',
        'date' => 'Fecha',
        'worked_hours' => 'Horas trabajadas',
        'late_minutes' => 'Minutos de retardo',
        'overtime_hours' => 'Horas extra',
        'is_absence' => 'Es falta',
        'is_late' => 'Es retardo',
        'is_attendance_exempt' => 'No checa',
        'zkteco_user_id' => 'ID en reloj',
        'raw_punches' => 'Checadas del reloj',

        // Workflow
        'approved_by' => 'Aprobado por',
        'approved_at' => 'Fecha de aprobacion',
        'rejected_by' => 'Rechazado por',
        'rejected_at' => 'Fecha de rechazo',
        'authorized_by' => 'Autorizado por',
        'requested_by' => 'Solicitado por',
        'created_by' => 'Creado por',
        'reviewed_by' => 'Revisado por',
        'rejection_reason' => 'Motivo de rechazo',
        'reason' => 'Motivo',
        'comments' => 'Comentarios',

        // Payroll periods
        'start_date' => 'Fecha inicial',
        'end_date' => 'Fecha final',
        'period_type' => 'Tipo de periodo',
        'closed_at' => 'Fecha de cierre',
        'cash_closed_at' => 'Cierre de efectivo',
        'cash_return_amount' => 'Efectivo devuelto',
        'cash_enabled_denominations' => 'Denominaciones habilitadas',

        // Incidents / vacations
        'incident_type' => 'Tipo de incidencia',
        'days' => 'Dias',
        'hours' => 'Horas',
        'vacation_days_available' => 'Dias de vacaciones disponibles',
        'vacation_days_used' => 'Dias de vacaciones usados',
        'vacation_days_advanced' => 'Dias de vacaciones adelantados',
        'is_paid' => 'Con goce de sueldo',

        // Compensation types
        'code' => 'Codigo',
        'calculation_type' => 'Tipo de calculo',
        'is_recurring' => 'Recurrente',
        'is_taxable' => 'Grava impuestos',
    ];

    /**
     * Human label for a column name.
     */
    public static function label(string $field): string
    {
        if (isset(self::LABELS[$field])) {
            return self::LABELS[$field];
        }

        if (str_ends_with($field, '_id')) {
            $base = substr($field, 0, -3);

            if (isset(self::LABELS[$base])) {
                return self::LABELS[$base];
            }
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Build a field-by-field diff from an audit entry's stored values.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return array<int, array{field: string, label: string, old: mixed, new: mixed}>
     */
    public static function diff(?array $old, ?array $new): array
    {
        $old ??= [];
        $new ??= [];

        $fields = array_keys($old + $new);
        sort($fields);

        $diff = [];

        foreach ($fields as $field) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;

            // Only surface fields that actually moved.
            if (array_key_exists($field, $old) && array_key_exists($field, $new) && $before === $after) {
                continue;
            }

            $diff[] = [
                'field' => $field,
                'label' => self::label($field),
                'old' => $before,
                'new' => $after,
            ];
        }

        return $diff;
    }
}
