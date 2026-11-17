<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Schedules\ScheduleRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ScheduleCalendarDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value)) {
                throw new InvalidArgumentException;
            }
            ScheduleRule::parseDate($value);
        } catch (InvalidArgumentException) {
            $fail(__('The schedule date must be a valid YYYY-MM-DD calendar date.'));
        }
    }
}
