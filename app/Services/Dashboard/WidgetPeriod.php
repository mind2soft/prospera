<?php

namespace App\Services\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The time window of a dashboard widget, resolved from its canonical period
 * enumeration (docs/dashboard.md). Pure value object — fully unit-tested.
 *
 * - days: the past N days including today (1 = today alone).
 * - months: the current month back N months inclusive (1 = current month,
 *   month-to-date).
 *
 * The window always ends today; the start is the first day of the window.
 */
final class WidgetPeriod
{
    /**
     * @param  'days'|'months'  $type
     */
    private function __construct(
        private readonly string $type,
        private readonly int $range,
    ) {}

    /**
     * @param  array{type: string, range: int}  $period
     */
    public static function fromConfig(array $period): self
    {
        if (! in_array($period['type'], ['days', 'months'], true)) {
            throw new \InvalidArgumentException("Unknown period type [{$period['type']}].");
        }

        return new self($period['type'], $period['range']);
    }

    public static function days(int $range): self
    {
        return new self('days', $range);
    }

    public static function months(int $range): self
    {
        return new self('months', $range);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function range(): int
    {
        return $this->range;
    }

    /**
     * The inclusive [from, to] date window ending today. Transactions are
     * stored at 00:00:00, so `from` starts at 00:00:00 and `to` ends the
     * day.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function window(?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $to = $today->copy()->endOfDay();

        $from = $this->type === 'days'
            ? $today->copy()->subDays($this->range - 1)->startOfDay()
            : $today->copy()->startOfMonth()->subMonths($this->range - 1)->startOfDay();

        return ['from' => $from, 'to' => $to];
    }

    /**
     * The ordered bucket keys covering the window: per day for `days`
     * periods, per calendar month for `months` periods.
     *
     * @return list<string>
     */
    public function buckets(?Carbon $today = null): array
    {
        ['from' => $from, 'to' => $to] = $this->window($today);

        $buckets = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $buckets[] = $this->bucketKey($cursor);

            // Days step one day at a time; months jump to the first day of
            // the next month.
            $cursor = $this->type === 'days'
                ? $cursor->copy()->addDay()
                : $cursor->copy()->startOfMonth()->addMonth();
        }

        return $buckets;
    }

    /**
     * The bucket key a date falls into: `Y-m-d` for days periods, `Y-m` for
     * months periods.
     */
    public function bucketKey(CarbonInterface $date): string
    {
        return $this->type === 'days' ? $date->toDateString() : $date->format('Y-m');
    }
}
