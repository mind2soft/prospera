<?php

namespace App\Settings;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The user's email notification preferences, stored as the
 * `users.notification_settings` JSON column.
 *
 * This is the single catalog of notification types: every notification
 * sender must gate on {@see User::wantsNotification()} instead
 * of reading raw keys, and every toggle added to this class must appear on
 * the Settings → Notifications page (`resources/js/pages/settings/Notifications.vue`).
 *
 * Unknown or missing keys default to *enabled*: a user who never opened the
 * settings page (`null` column) receives every notification. The master
 * switch short-circuits everything — master off means no system emails,
 * regardless of the individual toggles.
 *
 * @implements Arrayable<string, bool>
 */
final class NotificationSettings implements Arrayable
{
    /** The master switch — disables every notification when off. */
    public const MASTER = 'master';

    /** Upcoming scheduled-transaction payment reminders. */
    public const PAYMENT_REMINDERS = 'payment_reminders';

    /** An open ledger you are a member of whose end date has passed. */
    public const LEDGER_EXPIRED = 'ledger_expired';

    /** Another user recorded a transaction in a ledger you own. */
    public const CONTRIBUTOR_TRANSACTIONS = 'contributor_transactions';

    /** Recorded spending exceeded an envelope's monthly allocation. */
    public const ENVELOPE_LIMIT_EXCEEDED = 'envelope_limit_exceeded';

    /**
     * The togglable notification types (everything but the master switch).
     *
     * @var list<string>
     */
    public const TOGGLES = [
        self::PAYMENT_REMINDERS,
        self::LEDGER_EXPIRED,
        self::CONTRIBUTOR_TRANSACTIONS,
        self::ENVELOPE_LIMIT_EXCEEDED,
    ];

    /** @var list<string> */
    public const KEYS = [self::MASTER, ...self::TOGGLES];

    public function __construct(
        public readonly bool $master = true,
        public readonly bool $paymentReminders = true,
        public readonly bool $ledgerExpired = true,
        public readonly bool $contributorTransactions = true,
        public readonly bool $envelopeLimitExceeded = true,
    ) {}

    /**
     * Create a fully-enabled settings object.
     */
    public static function default(): self
    {
        return new self;
    }

    /**
     * Hydrate from the stored JSON (a raw decoded array, possibly partial
     * or null). Missing and unknown keys fall back to the defaults, so
     * adding new toggles never requires a data migration.
     */
    public static function fromStored(mixed $value): self
    {
        $settings = is_array($value) ? $value : [];

        return new self(
            master: (bool) ($settings[self::MASTER] ?? true),
            paymentReminders: (bool) ($settings[self::PAYMENT_REMINDERS] ?? true),
            ledgerExpired: (bool) ($settings[self::LEDGER_EXPIRED] ?? true),
            contributorTransactions: (bool) ($settings[self::CONTRIBUTOR_TRANSACTIONS] ?? true),
            envelopeLimitExceeded: (bool) ($settings[self::ENVELOPE_LIMIT_EXCEEDED] ?? true),
        );
    }

    /**
     * @param  array<string, bool>  $values
     */
    public function with(array $values): self
    {
        return new self(
            master: $values[self::MASTER] ?? $this->master,
            paymentReminders: $values[self::PAYMENT_REMINDERS] ?? $this->paymentReminders,
            ledgerExpired: $values[self::LEDGER_EXPIRED] ?? $this->ledgerExpired,
            contributorTransactions: $values[self::CONTRIBUTOR_TRANSACTIONS] ?? $this->contributorTransactions,
            envelopeLimitExceeded: $values[self::ENVELOPE_LIMIT_EXCEEDED] ?? $this->envelopeLimitExceeded,
        );
    }

    /**
     * Whether a notification of the given type may be sent to the user —
     * the master switch **and** the individual toggle must both be on.
     * Unknown keys are never enabled (defensive: senders use the constants).
     */
    public function allows(string $key): bool
    {
        if (! in_array($key, self::KEYS, true) || ! $this->master) {
            return false;
        }

        return match ($key) {
            self::MASTER => true,
            self::PAYMENT_REMINDERS => $this->paymentReminders,
            self::LEDGER_EXPIRED => $this->ledgerExpired,
            self::CONTRIBUTOR_TRANSACTIONS => $this->contributorTransactions,
            self::ENVELOPE_LIMIT_EXCEEDED => $this->envelopeLimitExceeded,
        };
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            self::MASTER => $this->master,
            self::PAYMENT_REMINDERS => $this->paymentReminders,
            self::LEDGER_EXPIRED => $this->ledgerExpired,
            self::CONTRIBUTOR_TRANSACTIONS => $this->contributorTransactions,
            self::ENVELOPE_LIMIT_EXCEEDED => $this->envelopeLimitExceeded,
        ];
    }
}
