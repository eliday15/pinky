<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Datos precargados de un periodo de nómina para calcular a TODOS sus
 * empleados sin repetir consultas por empleado (asistencia, incidencias,
 * autorizaciones, festivos).
 *
 * Lo construye PayrollCalculatorService::calculatePeriod con 4 consultas en
 * lote; calculateEmployeePayroll lo consume cuando está presente y su
 * period_id coincide. El camino de un solo empleado (shadow recalc,
 * invalidación, tests) sigue consultando directo — mismos resultados, solo
 * cambia cuántos viajes a la base se hacen.
 */
final class PayrollPeriodCalculationContext
{
    /**
     * @param  Collection  $attendanceByEmployee  employee_id => Collection<AttendanceRecord>
     * @param  Collection  $incidentsByEmployee  employee_id => Collection<Incident> (aprobadas, con incidentType)
     * @param  Collection  $authorizationsByEmployee  employee_id => Collection<Authorization> (aprobadas/pagadas, con compensationType)
     * @param  Collection  $holidays  Festivos dentro del periodo
     * @param  bool  $monthlyIncidentsEnsured  Las FRT mensuales ya se generaron en lote para todos
     * @param  string|null  $scope  Alcance del pago unificado ('base'|'extras'); null = periodo normal
     */
    public function __construct(
        public readonly int $periodId,
        public readonly Collection $attendanceByEmployee,
        public readonly Collection $incidentsByEmployee,
        public readonly Collection $authorizationsByEmployee,
        public readonly Collection $modernAuthorizationTypesByEmployee,
        public readonly Collection $holidays,
        public readonly bool $monthlyIncidentsEnsured,
        public readonly ?string $scope = null,
    ) {}
}
