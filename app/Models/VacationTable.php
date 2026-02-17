<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Vacation entitlement table based on Mexican LFT.
 *
 * Maps years of service to vacation days entitled per year.
 */
class VacationTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'years_of_service',
        'vacation_days',
    ];

    protected $casts = [
        'years_of_service' => 'integer',
        'vacation_days' => 'integer',
    ];
}
