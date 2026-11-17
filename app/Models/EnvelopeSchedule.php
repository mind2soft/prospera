<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\EncryptsAmounts;
use App\Models\Contracts\HasEncryptedAmounts;
use App\Models\Contracts\HasImmutableColumns;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyRing;
use App\Security\Amounts\AmountKeyScope;
use App\Services\Schedules\ScheduleRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Schedule definitions, materialized into open ledgers by ScheduleReconciler
 * (docs/scheduled-transactions.md).
 *
 * @property int $id
 * @property int $envelope_id
 * @property int $amount Signed integer minor units, encrypted at rest.
 * @property string $start_date ISO calendar date, not a timestamp.
 * @property string|null $end_date
 * @property string $period
 * @property list<int> $weekdays
 * @property int|null $day_of_month
 * @property int|null $month
 * @property int|null $reminder_days
 * @property string $reminder_unit Either REMINDER_UNIT_DAY (also for legacy
 *                                 nulls) or REMINDER_UNIT_MONTH, the unit of `reminder_days`.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class EnvelopeSchedule extends Model implements HasEncryptedAmounts, HasImmutableColumns
{
    use EncryptsAmounts;
    use SoftDeletes;

    /** Reminders are sent at most this many days before an occurrence. */
    public const MAX_REMINDER_DAYS = 366;

    /** Reminders with the month unit go back at most this many months. */
    public const MAX_REMINDER_MONTHS = 12;

    /** The default (legacy) unit: `reminder_days` counts days. */
    public const REMINDER_UNIT_DAY = 'day';

    /** `reminder_days` counts calendar months, clamped at month ends. */
    public const REMINDER_UNIT_MONTH = 'month';

    protected $fillable = [
        'envelope_id', 'amount', 'start_date', 'end_date', 'period',
        'weekdays', 'day_of_month', 'month', 'reminder_days', 'reminder_unit',
    ];

    protected $attributes = ['weekdays' => '[]'];

    // Only the owner-authorized API serializer opts into exposing this money data.
    protected $hidden = ['amount'];

    protected $casts = ['weekdays' => 'array'];

    /** @return Attribute<int, mixed> */
    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): int => $this->decryptStoredAmount('amount', $value),
            set: fn (mixed $value): int => $this->encodeAmount('amount', $value),
        );
    }

    /**
     * Legacy rows have no unit on disk: their `reminder_days` count days.
     *
     * @return Attribute<string, mixed>
     */
    protected function reminderUnit(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => match ($value) {
                self::REMINDER_UNIT_MONTH => self::REMINDER_UNIT_MONTH,
                null, self::REMINDER_UNIT_DAY => self::REMINDER_UNIT_DAY,
                default => throw new InvalidArgumentException('Schedule reminder unit must be "day" or "month".'),
            },
        );
    }

    public function rule(): ScheduleRule
    {
        return new ScheduleRule(
            $this->period, $this->start_date, $this->end_date,
            $this->weekdays, $this->day_of_month, $this->month,
        );
    }

    /**
     * Serialize writers with envelope direction/deletion/ownership changes and
     * envelope-key rotation. Do not depend on events (saveQuietly is safe).
     *
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('envelope_id')) {
            throw new LogicException('A schedule cannot move to another envelope.');
        }

        foreach (['day_of_month' => 2147483647, 'month' => 2147483647] as $column => $max) {
            $value = $this->getAttribute($column);
            if ($value !== null && (! is_int($value) || $value < 0 || $value > $max)) {
                throw new InvalidArgumentException('Schedule numeric fields must be non-negative integers within the supported range.');
            }
        }
        // The bound depends on the unit: days go out to a year, months to 12.
        // Reading the unit throws for an unknown unit; legacy nulls are days.
        $unit = $this->reminder_unit;
        $days = $this->getAttribute('reminder_days');
        $maxDays = $unit === self::REMINDER_UNIT_MONTH
            ? self::MAX_REMINDER_MONTHS
            : self::MAX_REMINDER_DAYS;
        if ($days !== null && (! is_int($days) || $days < 0 || $days > $maxDays)) {
            throw new InvalidArgumentException('Schedule reminder days must be a non-negative integer within the supported range for its unit.');
        }
        $rule = $this->rule();
        $this->weekdays = $rule->weekdays;

        $ownerId = AmountContext::id($this->getAttribute('envelope_id'), 'envelope_id');
        $ring = app(AmountKeyRing::class);

        try {
            return DB::transaction(function () use ($ownerId, $options, $ring): bool {
                $envelope = Envelope::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail();
                $ring->forget($ownerId, AmountKeyScope::Envelope);

                if (! EnvelopeAmountKey::query()->where('envelope_id', $ownerId)->exists()) {
                    // A missing key on an existing schedule is corruption, not
                    // a reason to silently create replacement key material.
                    if ($this->exists || static::withTrashed()->where('envelope_id', $ownerId)->exists()) {
                        throw new AmountDecryptionException('The envelope schedule amount key is missing.');
                    }
                    $ring->createKey($ownerId, AmountKeyScope::Envelope);
                }

                $amount = $this->amount;
                if (($envelope->type === Envelope::DIRECTION_EXPENSE && $amount > 0)
                    || ($envelope->type === Envelope::DIRECTION_REVENUE && $amount < 0)) {
                    throw new InvalidArgumentException('Schedule amount sign must match the envelope direction.');
                }

                return parent::save($options);
            });
        } finally {
            // Never memoize keys whose creating transaction may roll back.
            $ring->forget($ownerId, AmountKeyScope::Envelope);
        }
    }

    public static function encryptedAmountColumns(): array
    {
        return ['amount'];
    }

    public static function amountContextAttributes(): array
    {
        return ['envelope_id', 'start_date'];
    }

    public static function immutableColumns(): array
    {
        return ['envelope_id'];
    }

    public static function amountContextFor(string $column, array $attributes, int $ownerId): AmountContext
    {
        return new AmountContext('envelope_schedules', $column, $ownerId, [
            'd' => AmountContext::date($attributes['start_date'] ?? null),
        ], AmountKeyScope::Envelope);
    }

    protected function amountKeyOwnerId(array $attributes): int
    {
        return AmountContext::id($attributes['envelope_id'] ?? null, 'envelope_id');
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
