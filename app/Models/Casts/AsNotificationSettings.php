<?php

namespace App\Models\Casts;

use App\Settings\NotificationSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the `users.notification_settings` JSON column to the immutable
 * {@see NotificationSettings} value object, merging the stored keys with
 * the defaults so the accessor never returns a partial object.
 *
 * @implements CastsAttributes<NotificationSettings, array<string, bool>>
 */
final class AsNotificationSettings implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): NotificationSettings
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return NotificationSettings::fromStored($decoded);
    }

    /**
     * @param  NotificationSettings|mixed  $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $settings = match (true) {
            $value instanceof NotificationSettings => $value,
            is_array($value) => NotificationSettings::fromStored($value),
            default => null,
        };

        if ($settings === null) {
            return null;
        }

        $encoded = json_encode($settings->toArray());

        return $encoded === false ? null : $encoded;
    }
}
