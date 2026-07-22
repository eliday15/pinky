<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

/**
 * Employee model representing a company worker.
 */
class Employee extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Free up unique fields when soft-deleting so they can be reused.
     */
    protected static function booted(): void
    {
        static::softDeleted(function (Employee $employee) {
            $suffix = '_deleted_'.$employee->id;
            // Also flip status away from "active" so withTrashed-aware reports
            // never display a deleted employee as if they were still on payroll.
            $employee->updateQuietly([
                'employee_number' => $employee->employee_number.$suffix,
                'status' => 'inactive',
            ]);
        });
    }

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'employees';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Human readable name of this employee for the audit trail.
     */
    public function auditSubjectLabel(): string
    {
        return trim(($this->employee_number ? "{$this->employee_number} - " : '') . ($this->full_name ?? ''))
            ?: 'Empleado #' . $this->getKey();
    }

    /**
     * An employee record is about itself.
     */
    public function auditEmployeeId(): ?int
    {
        return $this->getKey();
    }

    protected $fillable = [
        'employee_number',
        'contpaqi_code',
        'zkteco_user_id',
        'user_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'address_street',
        'address_city',
        'address_state',
        'address_zip',
        'photo_path',
        'emergency_phone',
        'credential_type',
        'credential_number',
        'hire_date',
        'birth_date',
        'termination_date',
        'department_id',
        'position_id',
        'schedule_id',
        'schedule_overrides',
        'supervisor_id',
        'hourly_rate',
        'overtime_rate',
        'holiday_rate',
        'is_minimum_wage',
        'is_trial_period',
        'trial_period_end_date',
        'imss_number',
        'is_imss_enrolled',
        'rfc',
        'curp',
        'fiscal_regime',
        'bank_code',
        'clabe',
        'contract_type',
        'workday_type',
        'sdi',
        'sbc',
        'infonavit_credit_type',
        'infonavit_credit_value',
        'is_attendance_exempt',
        'cash_pin',
        'daily_salary',
        'monthly_bonus_type',
        'monthly_bonus_amount',
        'vacation_days_entitled',
        'vacation_days_used',
        'vacation_hours_used',
        'vacation_hours_credited',
        'vacation_days_reserved',
        'vacation_days_advanced',
        'vacation_premium_percentage',
        'status',
    ];

    protected $casts = [
        'hire_date' => 'date:Y-m-d',
        'birth_date' => 'date:Y-m-d',
        'termination_date' => 'date:Y-m-d',
        'trial_period_end_date' => 'date:Y-m-d',
        'hourly_rate' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'holiday_rate' => 'decimal:2',
        'daily_salary' => 'decimal:2',
        'monthly_bonus_amount' => 'decimal:2',
        'vacation_premium_percentage' => 'decimal:2',
        'vacation_hours_used' => 'decimal:2',
        'vacation_hours_credited' => 'decimal:2',
        'is_minimum_wage' => 'boolean',
        'is_trial_period' => 'boolean',
        'is_imss_enrolled' => 'boolean',
        'sdi' => 'decimal:2',
        'sbc' => 'decimal:2',
        'infonavit_credit_value' => 'decimal:4',
        'is_attendance_exempt' => 'boolean',
        'schedule_overrides' => 'array',
    ];

    /**
     * Attributes hidden from array/JSON serialization.
     */
    protected $hidden = [
        'cash_pin',
    ];

    /**
     * Hash the cash PIN on assignment. An empty value is ignored so the form
     * can leave the field blank to keep the current PIN unchanged.
     */
    public function setCashPinAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->attributes['cash_pin'] = Hash::make($value);
    }

    /**
     * Whether this employee has a cash collection PIN set.
     */
    public function hasCashPin(): bool
    {
        return ! empty($this->attributes['cash_pin'] ?? null);
    }

    /**
     * Verify a plaintext cash PIN against the stored hash.
     */
    public function verifyCashPin(string $pin): bool
    {
        $hash = $this->attributes['cash_pin'] ?? null;

        if (empty($hash)) {
            return false;
        }

        return Hash::check($pin, $hash);
    }

    /**
     * Get the user account for this employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the department this employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the position of this employee.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Get the schedule assigned to this employee.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Get the supervisor (direct manager) of this employee.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * Get all subordinates (direct reports) of this employee.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    /**
     * Cached transitive subordinate id list for the current request.
     */
    private ?array $cachedSubtree = null;

    /**
     * Get every subordinate id under this employee, recursively (full subtree).
     *
     * Cycle-safe via visited set. Cached per-instance for the request.
     * Returns ids only — does NOT include the employee's own id.
     */
    public function allSubordinateIds(): array
    {
        if ($this->cachedSubtree !== null) {
            return $this->cachedSubtree;
        }

        $visited = [$this->id => true];
        $queue = [$this->id];
        $result = [];

        while (! empty($queue)) {
            $batch = $queue;
            $queue = [];

            $children = Employee::whereIn('supervisor_id', $batch)->pluck('id', 'id');
            foreach ($children as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return $this->cachedSubtree = $result;
    }

    /**
     * Get all attendance records for this employee.
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Get all incidents for this employee.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get all payroll entries for this employee.
     */
    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    /**
     * Get late accumulations for this employee.
     */
    public function lateAccumulations(): HasMany
    {
        return $this->hasMany(LateAccumulation::class);
    }

    /**
     * Get all authorizations (overtime, permissions, etc.) for this employee.
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }

    /**
     * Get emergency contacts for this employee.
     */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /**
     * Get compensation types assigned to this employee.
     */
    public function breakfastClaims(): HasMany
    {
        return $this->hasMany(BreakfastClaim::class);
    }

    public function compensationTypes(): BelongsToMany
    {
        return $this->belongsToMany(CompensationType::class, 'employee_compensation_type')
            ->withPivot('custom_percentage', 'custom_fixed_amount', 'is_active')
            ->withTimestamps();
    }

    /**
     * Get the effective schedule values for this employee, applying overrides.
     *
     * Returns the schedule with per-employee overrides merged on top.
     */
    public function getEffectiveSchedule(): ?object
    {
        if (! $this->schedule) {
            return null;
        }

        $base = $this->schedule;
        $overrides = $this->schedule_overrides ?? [];

        return (object) [
            'entry_time' => $overrides['entry_time'] ?? $base->entry_time,
            'exit_time' => $overrides['exit_time'] ?? $base->exit_time,
            'break_minutes' => (int) ($overrides['break_minutes'] ?? $base->break_minutes),
            'daily_work_hours' => (float) ($overrides['daily_work_hours'] ?? $base->daily_work_hours),
            'late_tolerance_minutes' => (int) ($overrides['late_tolerance_minutes'] ?? $base->late_tolerance_minutes),
            'working_days' => $overrides['working_days'] ?? $base->working_days,
            'is_flexible' => $base->is_flexible,
            'name' => $base->name,
        ];
    }

    /**
     * Get the effective schedule for a specific day, applying employee overrides.
     *
     * Returns the day-specific schedule with employee overrides merged on top.
     */
    public function getEffectiveScheduleForDay(string $dayName): ?object
    {
        if (! $this->schedule) {
            return null;
        }

        $daySchedule = $this->schedule->getScheduleForDay($dayName);
        $overrides = $this->schedule_overrides ?? [];

        // Per-day employee overrides take priority over global overrides
        $day = strtolower($dayName);
        $dayOverrides = $overrides['day_schedules'][$day] ?? [];

        $entryOverride = $dayOverrides['entry_time'] ?? $overrides['entry_time'] ?? null;
        $exitOverride = $dayOverrides['exit_time'] ?? $overrides['exit_time'] ?? null;
        $breakOverride = $dayOverrides['break_minutes'] ?? $overrides['break_minutes'] ?? null;
        $hoursOverride = $dayOverrides['daily_work_hours'] ?? $overrides['daily_work_hours'] ?? null;

        if (! empty($entryOverride)) {
            $daySchedule->entry_time = $entryOverride;
        }
        if (! empty($exitOverride)) {
            $daySchedule->exit_time = $exitOverride;
        }
        if ($breakOverride !== null) {
            $daySchedule->break_minutes = (int) $breakOverride;
        }
        if ($hoursOverride !== null) {
            $daySchedule->daily_work_hours = (float) $hoursOverride;
        }

        return $daySchedule;
    }

    /**
     * Check if a day is a working day for this employee, considering overrides.
     */
    public function isEffectiveWorkingDay(string $dayName): bool
    {
        $overrides = $this->schedule_overrides ?? [];

        if (! empty($overrides['working_days'])) {
            return in_array(strtolower($dayName), array_map('strtolower', $overrides['working_days']));
        }

        return $this->schedule?->isWorkingDay($dayName) ?? false;
    }

    /**
     * Whether a given calendar date counts as WEEKEND WORK for this employee.
     *
     * Desde 2026-07-07 (Dani): en TODOS los departamentos, cualquier sábado o
     * domingo trabajado cuenta como fin de semana, SIN importar el horario — el
     * sábado y el domingo dejaron de ser días obligatorios. Antes solo Almacén PT
     * (weekend_unit_hours) contaba cualquier Sat/Sun; el resto solo si el día
     * caía fuera del horario. El pago sigue guiado por la autorización FIN
     * aprobada, así que esta bandera solo decide si el día se OFRECE como fin de
     * semana ("Cargar desde checadas") y nunca duplica el base.
     */
    public function isWeekendWorkDay(Carbon $date): bool
    {
        return $date->isWeekend();
    }

    /**
     * ¿Este día es OBLIGATORIO para el empleado (puede generar falta)?
     *
     * Un día es obligatorio cuando es día laborable de su horario y NO es sábado
     * ni domingo. Sábado y domingo dejaron de ser obligatorios en todos los
     * departamentos (Dani 2026-07-08): faltar o salir temprano un fin de semana
     * ya no es falta. Los días festivos se excluyen aparte en cada punto (vía
     * `is_holiday` / Holiday::isHoliday), porque su fuente varía por contexto.
     */
    public function isObligatoryWorkDay(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return $this->isEffectiveWorkingDay($date->englishDayOfWeek);
    }

    /** Horas que, por omisión, valen 1 "fin de semana" en un depto normal. */
    public const WEEKEND_UNIT_DEFAULT_HOURS = 7;

    /**
     * Umbral (en horas) del FIN DE SEMANA para deptos que NO pagan por unidades
     * fijas (todos menos Almacén PT). Regla de Dani 2026-07-07:
     *
     * - Menos de T horas trabajadas en fin de semana: NO cuenta fin de semana,
     *   todo es tiempo extra.
     * - Exactamente T: 1 fin de semana (sin tiempo extra).
     * - Más de T: 1 fin de semana + las horas por encima de T como tiempo extra.
     *
     * T = departments.weekend_overtime_after_hours si está configurado (p. ej.
     * Saldos = 7), o 7 por omisión. Almacén PT (weekend_unit_hours) devuelve
     * NULL: paga el fin de semana por UNIDADES de horas, no por umbral.
     */
    public function weekendUnitThreshold(): ?float
    {
        if ($this->department?->weekend_unit_hours !== null) {
            return null;
        }

        return (float) ($this->department?->weekend_overtime_after_hours ?? self::WEEKEND_UNIT_DEFAULT_HOURS);
    }

    /**
     * Umbral de tiempo extra del fin de semana para un total de horas trabajadas.
     *
     * El "fin de semana" absorbe las primeras T horas: si trabajó T o más, el
     * tiempo extra empieza tras T (y gana 1 fin de semana); si trabajó menos de
     * T, no gana fin de semana y TODO es tiempo extra (umbral 0). Almacén PT
     * (paga por unidades) devuelve NULL — aquí el fin de semana no genera OT.
     *
     * @param  float  $workedHours  Total de horas trabajadas ese día de fin de semana.
     */
    public function weekendOvertimeThresholdForHours(float $workedHours): ?float
    {
        $threshold = $this->weekendUnitThreshold();
        if ($threshold === null) {
            return null;
        }

        return $workedHours >= $threshold ? $threshold : 0.0;
    }

    /**
     * ¿Este total de horas de fin de semana gana 1 "fin de semana"? Solo en
     * deptos que NO pagan por unidades fijas y cuando alcanza el umbral T.
     */
    public function qualifiesForWeekendUnit(float $workedHours): bool
    {
        $threshold = $this->weekendUnitThreshold();

        return $threshold !== null && $workedHours >= $threshold;
    }

    /**
     * Get the effective late tolerance in minutes for this employee.
     */
    public function getEffectiveLateTolerance(): int
    {
        $overrides = $this->schedule_overrides ?? [];

        return (int) ($overrides['late_tolerance_minutes'] ?? $this->schedule?->late_tolerance_minutes ?? 10);
    }

    /**
     * Scope for active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for minimum wage employees.
     */
    public function scopeMinimumWage($query)
    {
        return $query->where('is_minimum_wage', true);
    }

    /**
     * Scope for above minimum wage employees.
     */
    public function scopeAboveMinimumWage($query)
    {
        return $query->where('is_minimum_wage', false);
    }

    /**
     * Scope for employees exempt from attendance (no checador / no ZKTeco).
     * Their faltas and authorizations are captured manually; they are still
     * paid the full daily-salary base.
     */
    public function scopeAttendanceExempt($query)
    {
        return $query->where('is_attendance_exempt', true);
    }

    /**
     * Scope for employees that DO check attendance (biometric / ZKTeco). Used to
     * exclude attendance-exempt employees from absence auto-generation.
     */
    public function scopeRequiresAttendance($query)
    {
        return $query->where('is_attendance_exempt', false);
    }

    /**
     * Calculate vacation entitlement based on years of service.
     *
     * Args:
     *     vacationTable: Collection of VacationTable entries
     *
     * Returns:
     *     Number of vacation days entitled, or null if no matching entry
     */
    public function calculateVacationEntitlement($vacationTable = null): ?int
    {
        if (! $this->hire_date) {
            return null;
        }

        $years = max(1, (int) $this->hire_date->diffInYears(now()));

        if (! $vacationTable) {
            $vacationTable = VacationTable::orderBy('years_of_service')->get();
        }

        $entry = $vacationTable->where('years_of_service', '<=', $years)->last();

        return $entry?->vacation_days;
    }

    /**
     * Get remaining vacation days (entitled - used - reserved).
     */
    public function getVacationDaysRemainingAttribute(): int
    {
        return max(0, $this->vacation_days_entitled - $this->vacation_days_used - ($this->vacation_days_reserved ?? 0));
    }

    /**
     * Días "para disfrutar": el derecho menos los apartados como obligatorios de
     * diciembre. Es el techo de lo que el colaborador puede llegar a pedir en el
     * año (antes de restarle lo ya usado) — Dani 2026-07-17.
     */
    public function getVacationDaysForEnjoymentAttribute(): int
    {
        return max(0, $this->vacation_days_entitled - ($this->vacation_days_reserved ?? 0));
    }

    /**
     * ¿Es de nuevo ingreso? (menos de un año de antigüedad).
     *
     * A éstos se les ADELANTAN los días obligatorios de diciembre aunque aún no
     * generen derecho, para que no se queden sin sueldo en el cierre.
     */
    public function isNewHire(): bool
    {
        if (! $this->hire_date) {
            return false;
        }

        return $this->hire_date->gt(now()->subYear());
    }

    /**
     * Saldar la deuda de días adelantados con el derecho ya generado.
     *
     * "Cuando generen su derecho, esos días se descuentan automáticamente de su
     * saldo hasta cubrir los días que se les prestaron" (Dani 2026-07-17). El
     * abono es PARCIAL: si generó menos de lo que debe, se salda lo que alcance
     * y el resto sigue pendiente. Los días saldados pasan a `used` (que es lo
     * que realmente ocurrió: los disfrutó en diciembre).
     *
     * @return int Días saldados en esta pasada.
     */
    public function settleVacationAdvance(): int
    {
        $debt = (int) ($this->vacation_days_advanced ?? 0);

        if ($debt <= 0) {
            return 0;
        }

        // Lo que su derecho alcanza a cubrir hoy, sin contar lo ya usado.
        $coverable = max(0, $this->vacation_days_entitled - $this->vacation_days_used);
        $settled = min($debt, $coverable);

        if ($settled <= 0) {
            return 0;
        }

        $this->vacation_days_used += $settled;
        $this->vacation_days_advanced = $debt - $settled;
        $this->save();

        return $settled;
    }

    /** 1 día de vacaciones = 8 horas de crédito (Dani 2026-07-01). */
    public const VACATION_HOURS_PER_DAY = 8;

    /**
     * Horas de vacaciones disponibles para los permisos "a cuenta de vacaciones":
     * los días restantes convertidos a horas (1 día = 8 h) menos las horas ya
     * consumidas por esos permisos.
     */
    public function getVacationHoursRemainingAttribute(): float
    {
        return max(
            0.0,
            $this->vacation_days_remaining * self::VACATION_HOURS_PER_DAY - (float) ($this->vacation_hours_used ?? 0),
        );
    }

    /**
     * Horas disponibles en la BOLSA explícita de vacaciones (Dani 2026-07-09):
     * las horas convertidas por RRHH (`vacation_hours_credited`) menos las ya
     * gastadas, sin exceder nunca las horas que el saldo real de vacaciones
     * respalda (defensivo — el descuento del saldo es proporcional a lo gastado).
     */
    public function getVacationHoursBankRemainingAttribute(): float
    {
        $credited = (float) ($this->vacation_hours_credited ?? 0);
        $used = (float) ($this->vacation_hours_used ?? 0);

        return max(0.0, min($credited - $used, $this->vacation_hours_remaining));
    }

    /** ¿El colaborador usa la bolsa de horas a cuenta de vacaciones? (opt-in) */
    public function usesVacationHoursBank(): bool
    {
        return (float) ($this->vacation_hours_credited ?? 0) > 0;
    }

    /**
     * Días de vacaciones disponibles para una solicitud de días completos.
     *
     * Descuenta:
     *  - lo ya usado;
     *  - los APARTADOS como obligatorios de diciembre (no se pueden solicitar —
     *    Dani 2026-07-17);
     *  - los ADELANTADOS pendientes de saldar (ya se disfrutaron, son deuda);
     *  - de forma PROPORCIONAL las horas gastadas de la bolsa (8 h = 1 día),
     *    para evitar el doble gasto: tomar días como vacación y como horas.
     */
    public function getVacationDaysAvailableForRequestAttribute(): float
    {
        $usedHoursAsDays = (float) ($this->vacation_hours_used ?? 0) / self::VACATION_HOURS_PER_DAY;

        return max(
            0.0,
            $this->vacation_days_entitled
                - $this->vacation_days_used
                - (int) ($this->vacation_days_reserved ?? 0)
                - (int) ($this->vacation_days_advanced ?? 0)
                - $usedHoursAsDays,
        );
    }

    /**
     * Días disponibles INCLUYENDO los apartados de diciembre.
     *
     * Sólo el Administrador puede "jalar" de la reserva de diciembre en
     * emergencias (Dani 2026-07-22): este techo permite aprobar una vacación que
     * dipa en esos días, respetando aún la deuda de adelanto y las horas
     * gastadas de la bolsa.
     */
    public function getVacationDaysAvailableWithReserveAttribute(): float
    {
        $usedHoursAsDays = (float) ($this->vacation_hours_used ?? 0) / self::VACATION_HOURS_PER_DAY;

        return max(
            0.0,
            $this->vacation_days_entitled
                - $this->vacation_days_used
                - (int) ($this->vacation_days_advanced ?? 0)
                - $usedHoursAsDays,
        );
    }

    /**
     * Get vacation days available (alias for remaining, accounts for reserved days).
     */
    public function getVacationDaysAvailableAttribute(): int
    {
        return $this->vacation_days_remaining;
    }

    /**
     * Get the effective daily salary.
     *
     * If daily_salary is set explicitly, use that. Otherwise derive from
     * hourly_rate * daily work hours from the schedule.
     */
    public function getDailySalaryComputedAttribute(): float
    {
        if ($this->daily_salary && (float) $this->daily_salary > 0) {
            return (float) $this->daily_salary;
        }

        $schedule = $this->getEffectiveSchedule();
        $dailyHours = $schedule?->daily_work_hours ?? 8;

        return round((float) $this->hourly_rate * $dailyHours, 2);
    }

    /**
     * ¿El sueldo BASE de este empleado se paga en EFECTIVO?
     *
     * Regla (Luis 2026-07-09): el empleado cobra su base en EFECTIVO mientras NO
     * esté FORMALIZADO. Formalizado = inscrito al IMSS + con número de IMSS
     * registrado + fuera del periodo de prueba; solo entonces cobra por
     * TRANSFERENCIA (banco/CONTPAQi). El periodo de prueba puede ser INDEFINIDO
     * (sin fecha fin): mientras esté marcado, sigue en efectivo. Los EXTRAS
     * siempre son efectivo. Fuente única: la consumen la nómina y el cierre de
     * efectivo.
     */
    public function paysBaseInCash(): bool
    {
        $formalized = $this->is_imss_enrolled
            && filled($this->imss_number)
            && ! $this->isInTrialPeriod();

        return ! $formalized;
    }

    /**
     * ¿El cumpleaños del empleado (mes/día) cae dentro del rango dado?
     *
     * Compara solo mes y día (ignora el año), recorriendo los días del periodo.
     * Así una semana que cruza el fin de año (dic→ene) también acierta. Devuelve
     * false si no hay fecha de nacimiento capturada.
     *
     * Args:
     *     start: Primer día del rango (inclusive).
     *     end: Último día del rango (inclusive).
     *
     * Returns:
     *     true si algún día del rango coincide con el mes/día de nacimiento.
     */
    public function birthdayFallsBetween(\Carbon\Carbon $start, \Carbon\Carbon $end): bool
    {
        if (! $this->birth_date) {
            return false;
        }

        $bMonth = (int) $this->birth_date->format('n');
        $bDay = (int) $this->birth_date->format('j');

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ((int) $day->format('n') === $bMonth && (int) $day->format('j') === $bDay) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the monthly bonus for this employee.
     *
     * Args:
     *     absences: Number of absence days in the period
     *     workingDaysInPeriod: Total working days in the period
     *
     * Returns:
     *     Calculated bonus amount
     */
    public function calculateMonthlyBonus(int $absences = 0, int $workingDaysInPeriod = 26): float
    {
        $type = $this->monthly_bonus_type ?? 'none';
        $amount = (float) ($this->monthly_bonus_amount ?? 0);

        if ($type === 'none' || $amount <= 0) {
            return 0.0;
        }

        if ($type === 'fixed') {
            return $amount;
        }

        // Variable: reduce proportionally by absences
        if ($workingDaysInPeriod <= 0) {
            return $amount;
        }

        $attendedDays = max(0, $workingDaysInPeriod - $absences);

        return round($amount * ($attendedDays / $workingDaysInPeriod), 2);
    }

    /**
     * Check if the employee is currently in trial period.
     */
    public function isInTrialPeriod(): bool
    {
        if (! $this->is_trial_period) {
            return false;
        }

        if ($this->trial_period_end_date) {
            return $this->trial_period_end_date->isFuture() || $this->trial_period_end_date->isToday();
        }

        return true;
    }

    /**
     * Scope for employees with incomplete profiles (missing schedule, supervisor, or compensation types).
     */
    public function scopeIncomplete($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('schedule_id')
                ->orWhereNull('supervisor_id')
                ->orWhereDoesntHave('compensationTypes');
        });
    }

    /**
     * Scope for employees in trial period.
     */
    public function scopeInTrialPeriod($query)
    {
        return $query->where('is_trial_period', true)
            ->where(function ($q) {
                $q->whereNull('trial_period_end_date')
                    ->orWhere('trial_period_end_date', '>=', now()->toDateString());
            });
    }

    /**
     * Get the identifier to use for CONTPAQi exports.
     * Uses contpaqi_code if set, otherwise falls back to employee_number.
     */
    public function getContpaqiIdentifierAttribute(): string
    {
        return $this->contpaqi_code ?? $this->employee_number;
    }
}
