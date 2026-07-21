<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Vacation entitlement table based on Mexican LFT.
 *
 * Maps years of service to vacation days entitled per year.
 */
class VacationTable extends Model
{
    use Auditable, HasFactory;

    /**
     * Module name for audit logging.
     */
    protected string $auditModule = 'vacations';

    /**
     * Fields to exclude from audit logs.
     */
    protected array $auditExcluded = ['created_at', 'updated_at'];

    protected $fillable = [
        'years_of_service',
        'vacation_days',
    ];

    /**
     * Human readable name of this table row for the audit trail.
     */
    public function auditSubjectLabel(): string
    {
        return "Tabla de vacaciones: {$this->years_of_service} anos de servicio";
    }

    /**
     * A table row is not tied to an employee.
     */
    public function auditEmployeeId(): ?int
    {
        return null;
    }

    protected $casts = [
        'years_of_service' => 'integer',
        'vacation_days' => 'integer',
    ];
}
