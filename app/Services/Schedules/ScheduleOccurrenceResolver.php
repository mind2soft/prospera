<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Pure calendar arithmetic, independent of the clock, database, and application timezone. */
final class ScheduleOccurrenceResolver
{
    /**
     * Resolve the inclusive intersection of the requested window and the rule's
     * bounds. Callers should use bounded windows (e.g. one budget month).
     *
     * @return list<string> Chronologically ordered, unique YYYY-MM-DD dates.
     */
    public function resolve(ScheduleRule $rule, string $from, string $to): array
    {
        $fromDate = ScheduleRule::parseDate($from);
        $toDate = ScheduleRule::parseDate($to);

        if ($toDate->lt($fromDate)) {
            throw new InvalidArgumentException('The occurrence window must end on or after it starts.');
        }

        $fromDate = $fromDate->max($rule->startDate);
        $toDate = $rule->endDate === null ? $toDate : $toDate->min($rule->endDate);

        if ($fromDate->gt($toDate)) {
            return [];
        }

        return match ($rule->period) {
            'once' => $rule->startDate->betweenIncluded($fromDate, $toDate)
                ? [$rule->startDate->toDateString()] : [],
            'weekly', 'biweekly' => $this->weekly($rule, $fromDate, $toDate),
            default => $this->monthlyOrYearly($rule, $fromDate, $toDate),
        };
    }

    /** @return list<string> */
    private function weekly(ScheduleRule $rule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Each selected weekday starts at its first occurrence on/after the
        // schedule start, and repeats every 7 or 14 calendar days. Never
        // re-anchor to the query window, ISO week number, or a scheduler run.
        $step = $rule->period === 'biweekly' ? 14 : 7;
        $dates = [];

        foreach ($rule->weekdays as $weekday) {
            $first = $rule->startDate->addDays(($weekday - $rule->startDate->isoWeekday() + 7) % 7);
            $elapsed = max(0, (int) $first->diffInDays($from, false));
            $cursor = $first->addDays(intdiv($elapsed + $step - 1, $step) * $step);

            while ($cursor->lte($to)) {
                $dates[] = $cursor->toDateString();
                $cursor = $cursor->addDays($step);
            }
        }

        sort($dates);

        return $dates;
    }

    /** @return list<string> */
    private function monthlyOrYearly(ScheduleRule $rule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $yearly = $rule->period === 'yearly';
        $cursor = $yearly
            ? $from->startOfYear()->month($rule->month ?? throw new \LogicException('Missing yearly month.'))
            : $from->startOfMonth();
        $day = $rule->dayOfMonth ?? throw new \LogicException('Missing schedule day.');
        $dates = [];

        while ($cursor->lte($to)) {
            // Always derive from the first of the month, not the previously
            // clamped occurrence: Jan 31 -> Feb 28 -> Mar 31 (not Mar 28).
            $date = $cursor->day(min($day, $cursor->daysInMonth));

            if ($date->betweenIncluded($from, $to)) {
                $dates[] = $date->toDateString();
            }

            $cursor = $yearly ? $cursor->addYear() : $cursor->addMonth();
        }

        return $dates;
    }
}
