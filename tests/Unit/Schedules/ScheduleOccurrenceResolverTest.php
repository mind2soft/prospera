<?php

declare(strict_types=1);

namespace Tests\Unit\Schedules;

use App\Services\Schedules\ScheduleOccurrenceResolver;
use App\Services\Schedules\ScheduleRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleOccurrenceResolverTest extends TestCase
{
    /** @param list<string> $expected */
    #[DataProvider('occurrenceCases')]
    public function test_resolves_occurrences(ScheduleRule $rule, string $from, string $to, array $expected): void
    {
        $this->assertSame($expected, new ScheduleOccurrenceResolver()->resolve($rule, $from, $to));
    }

    /** @return iterable<string, array{ScheduleRule, string, string, list<string>}> */
    public static function occurrenceCases(): iterable
    {
        yield 'once, exact inclusive window' => [
            new ScheduleRule('once', '2026-03-05'), '2026-03-05', '2026-03-05', ['2026-03-05'],
        ];
        yield 'once, wider window' => [
            new ScheduleRule('once', '2026-03-05'), '2026-01-01', '2026-12-31', ['2026-03-05'],
        ];
        yield 'once, window after occurrence' => [
            new ScheduleRule('once', '2026-03-05'), '2026-03-06', '2026-03-31', [],
        ];
        yield 'window before schedule' => [
            new ScheduleRule('weekly', '2026-04-01', weekdays: [1]), '2026-03-01', '2026-03-31', [],
        ];
        yield 'window after schedule' => [
            new ScheduleRule('monthly', '2026-01-01', '2026-01-31', dayOfMonth: 10), '2026-02-01', '2026-02-28', [],
        ];
        yield 'weekly, Monday only' => [
            new ScheduleRule('weekly', '2026-03-01', weekdays: [1]), '2026-03-01', '2026-03-31',
            ['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'],
        ];
        yield 'weekly, sorted unique weekdays and start/end clipping' => [
            new ScheduleRule('weekly', '2026-03-04', '2026-03-16', weekdays: [4, 1, 4]), '2026-03-01', '2026-03-31',
            ['2026-03-05', '2026-03-09', '2026-03-12', '2026-03-16'],
        ];
        yield 'weekly, Sunday is ISO 7' => [
            new ScheduleRule('weekly', '2026-03-01', weekdays: [7]), '2026-03-01', '2026-03-08',
            ['2026-03-01', '2026-03-08'],
        ];
        yield 'weekly, same-day bounds' => [
            new ScheduleRule('weekly', '2026-03-05', '2026-03-05', weekdays: [4]), '2026-01-01', '2026-12-31',
            ['2026-03-05'],
        ];
        yield 'biweekly, start itself selected' => [
            new ScheduleRule('biweekly', '2026-03-02', weekdays: [1]), '2026-03-01', '2026-04-01',
            ['2026-03-02', '2026-03-16', '2026-03-30'],
        ];
        yield 'biweekly, first occurrence is after start' => [
            new ScheduleRule('biweekly', '2026-03-04', weekdays: [1]), '2026-03-01', '2026-04-01',
            ['2026-03-09', '2026-03-23'],
        ];
        yield 'biweekly, multiple weekdays straddle an ISO week' => [
            new ScheduleRule('biweekly', '2026-03-04', weekdays: [1, 4]), '2026-03-01', '2026-03-31',
            ['2026-03-05', '2026-03-09', '2026-03-19', '2026-03-23'],
        ];
        yield 'biweekly, query does not re-anchor' => [
            new ScheduleRule('biweekly', '2026-03-04', weekdays: [1, 4]), '2026-03-10', '2026-03-23',
            ['2026-03-19', '2026-03-23'],
        ];
        yield 'biweekly, empty inactive week' => [
            new ScheduleRule('biweekly', '2026-03-02', weekdays: [1]), '2026-03-08', '2026-03-14', [],
        ];
        yield 'biweekly, year with ISO week 53 does not reset cadence' => [
            new ScheduleRule('biweekly', '2020-12-21', weekdays: [1]), '2020-12-21', '2021-01-31',
            ['2020-12-21', '2021-01-04', '2021-01-18'],
        ];
        yield 'monthly, 31 clamps without drift' => [
            new ScheduleRule('monthly', '2026-01-01', dayOfMonth: 31), '2026-01-01', '2026-05-31',
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
        ];
        yield 'monthly, 30 in leap February' => [
            new ScheduleRule('monthly', '2028-01-01', dayOfMonth: 30), '2028-01-01', '2028-03-31',
            ['2028-01-30', '2028-02-29', '2028-03-30'],
        ];
        yield 'monthly, 29 in non-leap February' => [
            new ScheduleRule('monthly', '2027-01-01', dayOfMonth: 29), '2027-01-01', '2027-03-31',
            ['2027-01-29', '2027-02-28', '2027-03-29'],
        ];
        yield 'monthly, candidate before start skipped' => [
            new ScheduleRule('monthly', '2026-01-20', dayOfMonth: 10), '2026-01-01', '2026-03-31',
            ['2026-02-10', '2026-03-10'],
        ];
        yield 'monthly, query excludes both surrounding candidates' => [
            new ScheduleRule('monthly', '2026-01-01', dayOfMonth: 15), '2026-01-16', '2026-02-14', [],
        ];
        yield 'monthly, clamped occurrence at inclusive end' => [
            new ScheduleRule('monthly', '2026-01-31', '2026-02-28', dayOfMonth: 31), '2026-02-28', '2026-03-31',
            ['2026-02-28'],
        ];
        yield 'monthly, year transition' => [
            new ScheduleRule('monthly', '2026-12-01', dayOfMonth: 1), '2026-12-01', '2027-02-01',
            ['2026-12-01', '2027-01-01', '2027-02-01'],
        ];
        yield 'yearly, February 29 recovers in leap year' => [
            new ScheduleRule('yearly', '2026-01-01', dayOfMonth: 29, month: 2), '2026-01-01', '2030-01-01',
            ['2026-02-28', '2027-02-28', '2028-02-29', '2029-02-28'],
        ];
        yield 'yearly, February 31 also clamps' => [
            new ScheduleRule('yearly', '2028-01-01', dayOfMonth: 31, month: 2), '2028-01-01', '2029-12-31',
            ['2028-02-29', '2029-02-28'],
        ];
        yield 'yearly, day 31 in April' => [
            new ScheduleRule('yearly', '2026-01-01', dayOfMonth: 31, month: 4), '2026-01-01', '2027-12-31',
            ['2026-04-30', '2027-04-30'],
        ];
        yield 'yearly, start after candidate' => [
            new ScheduleRule('yearly', '2026-03-01', dayOfMonth: 29, month: 2), '2026-01-01', '2028-02-28',
            ['2027-02-28'],
        ];
        yield 'yearly, December boundary' => [
            new ScheduleRule('yearly', '2026-12-31', '2027-12-31', dayOfMonth: 31, month: 12), '2026-01-01', '2028-12-31',
            ['2026-12-31', '2027-12-31'],
        ];
        yield 'yearly, Gregorian century is not a leap year' => [
            new ScheduleRule('yearly', '2099-01-01', dayOfMonth: 29, month: 2), '2099-01-01', '2100-12-31',
            ['2099-02-28', '2100-02-28'],
        ];
        yield 'maximum supported ISO year' => [
            new ScheduleRule('monthly', '9999-12-01', dayOfMonth: 31), '9999-12-01', '9999-12-31',
            ['9999-12-31'],
        ];
    }

    public function test_catch_up_matches_adjacent_windows_for_every_period(): void
    {
        $resolver = new ScheduleOccurrenceResolver;
        $rules = [
            new ScheduleRule('once', '2026-02-28'),
            new ScheduleRule('weekly', '2026-01-07', weekdays: [1, 4, 7]),
            new ScheduleRule('biweekly', '2026-01-07', weekdays: [1, 4, 7]),
            new ScheduleRule('monthly', '2026-01-07', dayOfMonth: 31),
            new ScheduleRule('yearly', '2026-01-07', dayOfMonth: 29, month: 2),
        ];

        foreach ($rules as $rule) {
            $whole = $resolver->resolve($rule, '2026-01-01', '2026-04-30');
            $this->assertSame($whole, [
                ...$resolver->resolve($rule, '2026-01-01', '2026-02-13'),
                ...$resolver->resolve($rule, '2026-02-14', '2026-04-30'),
            ], $rule->period);
            $this->assertSame($whole, $resolver->resolve($rule, '2026-01-01', '2026-04-30'));
            $this->assertSame($whole, array_values(array_unique($whole)));
        }
    }

    public function test_calendar_dates_do_not_depend_on_the_clock_timezone_or_dst(): void
    {
        $timezone = date_default_timezone_get();
        $testNow = CarbonImmutable::getTestNow();

        try {
            date_default_timezone_set('America/Toronto');
            CarbonImmutable::setTestNow('2040-11-01');
            $rule = new ScheduleRule('biweekly', '2026-03-01', weekdays: [7]);
            $resolver = new ScheduleOccurrenceResolver;
            $this->assertSame(['2026-03-01', '2026-03-15', '2026-03-29'], $resolver->resolve($rule, '2026-03-01', '2026-03-31'));

            date_default_timezone_set('Pacific/Kiritimati');
            $this->assertSame(['2026-03-01', '2026-03-15', '2026-03-29'], $resolver->resolve($rule, '2026-03-01', '2026-03-31'));
            $this->assertSame('2026-03-01 00:00:00 +00:00', $rule->startDate->format('Y-m-d H:i:s P'));
        } finally {
            date_default_timezone_set($timezone);
            CarbonImmutable::setTestNow($testNow);
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidRules')]
    public function test_rejects_invalid_rules(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScheduleRule(...$arguments);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRules(): iterable
    {
        yield 'unknown period' => [['period' => 'daily', 'startDate' => '2026-01-01']];
        yield 'end before start' => [['period' => 'once', 'startDate' => '2026-01-02', 'endDate' => '2026-01-01']];
        yield 'weekly missing weekdays' => [['period' => 'weekly', 'startDate' => '2026-01-01']];
        yield 'biweekly missing weekdays' => [['period' => 'biweekly', 'startDate' => '2026-01-01']];

        foreach ([0, 8, -1, '1', 1.5] as $index => $weekday) {
            yield "invalid weekday $index" => [['period' => 'weekly', 'startDate' => '2026-01-01', 'weekdays' => [$weekday]]];
        }

        foreach (['monthly', 'yearly'] as $period) {
            foreach ([null, 0, 32] as $index => $day) {
                yield "$period invalid day $index" => [[
                    'period' => $period, 'startDate' => '2026-01-01', 'dayOfMonth' => $day,
                    'month' => $period === 'yearly' ? 1 : null,
                ]];
            }
        }

        foreach ([null, 0, 13] as $index => $month) {
            yield "yearly invalid month $index" => [[
                'period' => 'yearly', 'startDate' => '2026-01-01', 'dayOfMonth' => 1, 'month' => $month,
            ]];
        }

        yield 'irrelevant weekdays' => [['period' => 'once', 'startDate' => '2026-01-01', 'weekdays' => [1]]];
        yield 'irrelevant day' => [['period' => 'weekly', 'startDate' => '2026-01-01', 'weekdays' => [1], 'dayOfMonth' => 1]];
        yield 'irrelevant month' => [['period' => 'monthly', 'startDate' => '2026-01-01', 'dayOfMonth' => 1, 'month' => 1]];
    }

    #[DataProvider('invalidDates')]
    public function test_rejects_invalid_dates_in_all_date_positions(string $date): void
    {
        $rule = new ScheduleRule('once', '2026-01-01');
        $resolver = new ScheduleOccurrenceResolver;
        $calls = [
            fn () => new ScheduleRule('once', $date),
            fn () => new ScheduleRule('once', '2026-01-01', $date),
            fn () => $resolver->resolve($rule, $date, '2026-12-31'),
            fn () => $resolver->resolve($rule, '2026-01-01', $date),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail("Accepted invalid date [$date].");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Schedule dates must be valid YYYY-MM-DD calendar dates.', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        foreach (['2026-02-29', '2028-02-30', '2026-04-31', '2026-13-01', '2026-01-00', '0000-01-01',
            '10000-01-01', '2026-1-01', '26-01-01', 'tomorrow', '', ' 2026-01-01', '2026-01-01 ',
            '2026-01-01T00:00:00Z', "2026-01-01\n"] as $date) {
            yield "invalid date [$date]" => [$date];
        }
    }

    public function test_rejects_a_reversed_query_window(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The occurrence window must end on or after it starts.');

        new ScheduleOccurrenceResolver()->resolve(new ScheduleRule('once', '2026-01-01'), '2026-02-01', '2026-01-31');
    }
}
