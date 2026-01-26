<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'name',
        'is_mandatory',
        'pay_multiplier',
    ];

    protected $casts = [
        'date' => 'date',
        'is_mandatory' => 'boolean',
        'pay_multiplier' => 'decimal:2',
    ];

    /**
     * Check if a given date is a holiday.
     */
    public static function isHoliday($date): bool
    {
        return static::where('date', $date)->exists();
    }

    /**
     * Get holiday by date.
     */
    public static function getByDate($date): ?self
    {
        return static::where('date', $date)->first();
    }
}
