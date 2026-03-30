<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a single TOTP authenticator device for a user.
 *
 * Each user can have multiple devices. During login, the TOTP code
 * is verified against all confirmed devices.
 */
class TwoFactorDevice extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'secret',
        'confirmed_at',
        'last_used_at',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns this device.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this device has been confirmed.
     */
    public function isConfirmed(): bool
    {
        return !is_null($this->confirmed_at);
    }
}
