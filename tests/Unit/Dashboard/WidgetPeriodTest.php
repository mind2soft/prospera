<?php

namespace Tests\Unit\Dashboard;

use App\Services\Dashboard\WidgetPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WidgetPeriodTest extends TestCase
{
    public function test_a_one_day_period_covers_today_alone(): void
    {
        $period = WidgetPeriod::days(1);

        $window = $period->window(Carbon::parse('2026-11-10'));

        $this->assertSame('2026-11-10', $window['from']->toDateString());
        $this->assertSame('2026-11-10 23:59:59', $window['to']->format('Y-m-d H:i:s'));
        $this->assertSame(['2026-11-10'], $period->buckets(Carbon::parse('2026-11-10')));
    }

    public function test_a_one_week_period_covers_the_past_seven_days_including_today(): void
    {
        $period = WidgetPeriod::days(7);

        $window = $period->window(Carbon::parse('2026-11-10'));

        $this->assertSame('2026-11-04', $window['from']->toDateString());
        $this->assertSame(
            ['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10'],
            $period->buckets(Carbon::parse('2026-11-10')),
        );
    }

    public function test_a_two_week_period_spans_two_calendar_months(): void
    {
        $period = WidgetPeriod::days(14);

        $window = $period->window(Carbon::parse('2026-11-10'));

        $this->assertSame('2026-10-28', $window['from']->toDateString());
        $this->assertCount(14, $period->buckets(Carbon::parse('2026-11-10')));
        $this->assertSame('2026-10-28', $period->buckets(Carbon::parse('2026-11-10'))[0]);
    }

    public function test_the_current_month_is_month_to_date(): void
    {
        $period = WidgetPeriod::months(1);

        $window = $period->window(Carbon::parse('2026-11-10'));

        $this->assertSame('2026-11-01', $window['from']->toDateString());
        $this->assertSame('2026-11-10 23:59:59', $window['to']->format('Y-m-d H:i:s'));
        $this->assertSame(['2026-11'], $period->buckets(Carbon::parse('2026-11-10')));
    }

    public function test_a_twelve_month_period_starts_on_the_first_of_the_month_a_year_ago(): void
    {
        $period = WidgetPeriod::months(12);

        $window = $period->window(Carbon::parse('2026-11-10'));

        $this->assertSame('2025-12-01', $window['from']->toDateString());
        $this->assertCount(12, $period->buckets(Carbon::parse('2026-11-10')));
        $this->assertSame('2025-12', $period->buckets(Carbon::parse('2026-11-10'))[0]);
        $this->assertSame('2026-11', $period->buckets(Carbon::parse('2026-11-10'))[11]);
    }

    public function test_bucket_keys_are_per_month_for_months_periods(): void
    {
        $period = WidgetPeriod::months(2);

        $this->assertSame(
            ['2026-10', '2026-11'],
            $period->buckets(Carbon::parse('2026-11-10')),
        );
        $this->assertSame('2026-10', $period->bucketKey(Carbon::parse('2026-10-31')));
    }

    public function test_the_current_month_is_resolved_without_a_frozen_now(): void
    {
        $period = WidgetPeriod::months(1);

        $window = $period->window();

        $this->assertSame(Carbon::today()->startOfMonth()->toDateString(), $window['from']->toDateString());
    }
}
