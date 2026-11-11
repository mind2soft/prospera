<?php

namespace App\Http\Requests\Concerns;

use App\Services\LocalCalendar;

/**
 * Requests carrying the browser's IANA time zone as `timezone`, so "today"
 * is the user's calendar day (docs/budget.md, docs/dashboard.md).
 */
trait ResolvesLocalCalendar
{
    /**
     * @return array<string, list<string>>
     */
    protected function timezoneRules(): array
    {
        return [
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * The calendar of the validated `timezone` (the application time zone
     * without one).
     */
    public function calendar(): LocalCalendar
    {
        $timezone = $this->validated('timezone');

        return LocalCalendar::in(is_string($timezone) ? $timezone : null);
    }
}
