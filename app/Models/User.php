<?php

namespace App\Models;

use App\Models\Casts\AsNotificationSettings;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $locale
 * @property NotificationSettings $notification_settings
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden([
    'notification_settings',
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'notification_settings' => AsNotificationSettings::class,
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The single gate every notification sender must check before sending
     * an email to this user: the master switch **and** the individual
     * toggle must both be enabled. The check happens at send time (inside
     * the queued send), not only when the notification is enqueued.
     *
     * @param  string  $key  a `NotificationSettings::KEYS` constant
     */
    public function wantsNotification(string $key): bool
    {
        return $this->notification_settings->allows($key);
    }

    /**
     * The user's envelopes.
     *
     * @return HasMany<Envelope, $this>
     */
    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }

    /**
     * The transactions the user entered.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The user's financial profiles (owned + shared). The currently selected
     * profile is tracked client-side, not persisted.
     *
     * @return BelongsToMany<FinancialProfile, $this, FinancialProfileUser, 'pivot'>
     */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(FinancialProfile::class, 'financial_profile_user', 'user_id', 'profile_id')
            ->using(FinancialProfileUser::class)
            ->withPivot('is_owner', 'is_default')
            ->withTimestamps();
    }
}
