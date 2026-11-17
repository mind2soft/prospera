<?php

declare(strict_types=1);

namespace Tests\Unit\Schedules;

use App\Services\Schedules\ScheduleOccurrencePreview;
use App\Services\Schedules\ScheduleOccurrenceResolver;
use App\Services\Schedules\ScheduleRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleOccurrencePreviewTest extends TestCase
{
    /** @param list<string> $expected */
    #[DataProvider('previews')]
    public function test_preview(ScheduleRule $rule, string $today, array $expected): void
    {
        $preview = new ScheduleOccurrencePreview(new ScheduleOccurrenceResolver);
        $this->assertSame($expected, $preview->next($rule, $today));
    }

    /** @return iterable<string, array{ScheduleRule, string, list<string>}> */
    public static function previews(): iterable
    {
        yield 'once today' => [new ScheduleRule('once', '2026-03-01'), '2026-03-01', ['2026-03-01']];
        yield 'once in past' => [new ScheduleRule('once', '2026-03-01'), '2026-03-02', []];
        yield 'distant future start' => [new ScheduleRule('once', '2090-03-01'), '2026-03-01', ['2090-03-01']];
        yield 'weekly, five only' => [new ScheduleRule('weekly', '2026-03-01', weekdays: [1, 4]), '2026-03-01',
            ['2026-03-02', '2026-03-05', '2026-03-09', '2026-03-12', '2026-03-16']];
        yield 'sparse biweekly, anchor retained' => [new ScheduleRule('biweekly', '2026-03-04', weekdays: [1]), '2026-03-10',
            ['2026-03-23', '2026-04-06', '2026-04-20', '2026-05-04', '2026-05-18']];
        yield 'monthly clamping' => [new ScheduleRule('monthly', '2026-01-01', dayOfMonth: 31), '2026-02-01',
            ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30']];
        yield 'yearly clamping' => [new ScheduleRule('yearly', '2026-01-01', dayOfMonth: 29, month: 2), '2026-03-01',
            ['2027-02-28', '2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28']];
        yield 'inclusive end limits preview' => [new ScheduleRule('weekly', '2026-03-01', '2026-03-09', weekdays: [1]), '2026-03-01',
            ['2026-03-02', '2026-03-09']];
        yield 'expired schedule' => [new ScheduleRule('monthly', '2026-01-01', '2026-01-31', dayOfMonth: 31), '2026-03-01', []];
        yield 'maximum year monthly' => [new ScheduleRule('monthly', '9999-01-01', dayOfMonth: 31), '9999-11-01', ['9999-11-30', '9999-12-31']];
        yield 'maximum year yearly' => [new ScheduleRule('yearly', '9999-01-01', dayOfMonth: 31, month: 12), '9999-01-01', ['9999-12-31']];
        yield 'final ISO day' => [new ScheduleRule('weekly', '9999-12-31', weekdays: [5]), '9999-12-31', ['9999-12-31']];
    }
}
