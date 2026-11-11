<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * "Today" and "the current month" as the requesting user sees them. The
 * server runs on UTC, so near midnight its calendar day (and around the
 * first of a month, its month) differs from the user's. Clients send their
 * IANA time zone as `timezone`; without it, the application time zone
 * applies (docs/budget.md, docs/dashboard.md).
 */
final class LocalCalendar
{
    private function __construct(private readonly string $timezone) {}

    /**
     * The calendar of the request's validated `timezone` input (422 if it is
     * not a known IANA time zone).
     */
    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ]);

        return new self($validated['timezone'] ?? self::appTimezone());
    }

    public static function inTimezone(string $timezone): self
    {
        return new self($timezone);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * The user's current calendar date, at 00:00:00 in the application time
     * zone — the convention transaction dates are stored with (a calendar
     * date, no time zone conversion).
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            CarbonImmutable::now($this->timezone)->toDateString(),
            self::appTimezone(),
        );
    }

    private static function appTimezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
