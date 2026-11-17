<?php

declare(strict_types=1);

namespace App\Services\Schedules;

/** A small bounded query of the canonical resolver, never an unbounded recurrence expansion. */
final class ScheduleOccurrencePreview
{
    public function __construct(private readonly ScheduleOccurrenceResolver $resolver) {}

    /** @return list<string> Up to five upcoming dates, including the supplied calendar day. */
    public function next(ScheduleRule $rule, string $today): array
    {
        $from = ScheduleRule::parseDate($today)->max($rule->startDate);
        $to = match ($rule->period) {
            'once' => $from,
            'weekly' => $from->addDays(35),
            'biweekly' => $from->addDays(70),
            'monthly' => $from->addMonthsNoOverflow(5),
            default => $from->addYearsNoOverflow(5),
        };
        $to = $to->min(ScheduleRule::parseDate('9999-12-31'));

        return array_slice($this->resolver->resolve($rule, $from->toDateString(), $to->toDateString()), 0, 5);
    }
}
