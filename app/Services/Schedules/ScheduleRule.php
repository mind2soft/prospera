<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The date-only part of a schedule; deliberately carries no money or ownership data. */
final readonly class ScheduleRule
{
    public CarbonImmutable $startDate;

    public ?CarbonImmutable $endDate;

    /** @var list<int> */
    public array $weekdays;

    /**
     * @param  array<array-key, mixed>  $weekdays  Validated here as ISO weekday integers (Monday = 1, Sunday = 7).
     */
    public function __construct(
        public string $period,
        string $startDate,
        ?string $endDate = null,
        array $weekdays = [],
        public ?int $dayOfMonth = null,
        public ?int $month = null,
    ) {
        if (! in_array($period, ['once', 'weekly', 'biweekly', 'monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Unknown schedule period.');
        }

        $this->startDate = self::parseDate($startDate);
        $this->endDate = $endDate === null ? null : self::parseDate($endDate);

        if ($this->endDate !== null && $this->endDate->lt($this->startDate)) {
            throw new InvalidArgumentException('The schedule end date must be on or after its start date.');
        }

        $weekly = in_array($period, ['weekly', 'biweekly'], true);
        if ($weekly && $weekdays === []) {
            throw new InvalidArgumentException('Weekly schedules require at least one ISO weekday.');
        }

        $validatedWeekdays = [];
        foreach ($weekdays as $weekday) {
            if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('ISO weekdays must be integers between 1 and 7.');
            }

            $validatedWeekdays[] = $weekday;
        }

        if (! $weekly && $weekdays !== []) {
            throw new InvalidArgumentException('Only weekly schedules accept weekdays.');
        }

        $monthly = in_array($period, ['monthly', 'yearly'], true);
        if ($monthly && ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31)) {
            throw new InvalidArgumentException('Monthly and yearly schedules require a day between 1 and 31.');
        }

        if (! $monthly && $dayOfMonth !== null) {
            throw new InvalidArgumentException('Only monthly and yearly schedules accept a day of month.');
        }

        if ($period === 'yearly' && ($month === null || $month < 1 || $month > 12)) {
            throw new InvalidArgumentException('Yearly schedules require a month between 1 and 12.');
        }

        if ($period !== 'yearly' && $month !== null) {
            throw new InvalidArgumentException('Only yearly schedules accept a month.');
        }

        $validatedWeekdays = array_values(array_unique($validatedWeekdays));
        sort($validatedWeekdays);
        $this->weekdays = $validatedWeekdays;
    }

    /**
     * Parse an ISO calendar date without relative-date parsing, overflow, or
     * timezone conversion. UTC is only a stable container for date arithmetic.
     */
    public static function parseDate(string $date): CarbonImmutable
    {
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Schedule dates must be valid YYYY-MM-DD calendar dates.');
        }

        return CarbonImmutable::parse($date, 'UTC')->startOfDay();
    }
}
