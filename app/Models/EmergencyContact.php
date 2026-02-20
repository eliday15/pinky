<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emergency contact for an employee.
 */
class EmergencyContact extends Model
{
    protected $fillable = [
        'employee_id',
        'name',
        'phone',
        'email',
        'relationship',
        'address',
    ];

    /**
     * Get the employee this contact belongs to.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
