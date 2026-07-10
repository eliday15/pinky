<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The table associated with the model.
     */
    protected $table = 'app_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Get the user's two-factor authentication devices.
     */
    public function twoFactorDevices(): HasMany
    {
        return $this->hasMany(TwoFactorDevice::class);
    }

    /**
     * Check if the user has at least one confirmed 2FA device.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactorDevices()->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Check if 2FA is mandatory for this user's role.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyRole(['superadmin', 'admin', 'rrhh', 'supervisor']);
    }

    /**
     * Get the employee associated with this user.
     */
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * ¿Este usuario puede operar el efectivo de este periodo de nómina?
     *
     * Quien tiene payroll.pay_cash (admin/superadmin) opera todas. Los
     * cobradores solo la suya: cobrador_general los periodos GENERALES
     * (department_id NULL) y cobrador_taller los periodos de departamentos con
     * nómina propia (has_separate_payroll, hoy solo Taller).
     */
    public function allowsCashPeriod(PayrollPeriod $period): bool
    {
        if ($this->hasPermissionTo('payroll.pay_cash')) {
            return true;
        }

        if ($this->hasRole('cobrador_general') && $period->department_id === null) {
            return true;
        }

        if ($this->hasRole('cobrador_taller')
            && $period->department_id !== null
            && $period->department?->has_separate_payroll) {
            return true;
        }

        return false;
    }
}
