<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A logged contact-form submission (issue #17). Rows are append-only: they
 * serve the audit log (email, subject, message, IP, date/time) and the
 * 12-hour per-IP cooldown check, then get deleted by `contact:prune` once
 * past the retention window.
 *
 * @property int $id
 * @property string $email
 * @property string $subject
 * @property string $message
 * @property string $ip_address
 * @property int|null $user_id
 * @property CarbonImmutable $created_at
 */
class ContactSubmission extends Model
{
    public const UPDATED_AT = null;

    /**
     * Cooldown window between two submissions from the same IP.
     */
    public const COOLDOWN_HOURS = 12;

    protected $fillable = ['email', 'subject', 'message', 'ip_address', 'user_id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Free-form text may contain sensitive details (issue #17).
            'message' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether this IP submitted within the cooldown window.
     */
    public static function onCooldownFor(string $ip): bool
    {
        return static::query()
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subHours(self::COOLDOWN_HOURS))
            ->exists();
    }
}
