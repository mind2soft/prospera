<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Production has no long-lived queue worker: the scheduler runs it
 * (routes/console.php, README → Scheduled tasks).
 */
class QueueScheduleTest extends TestCase
{
    private function scheduled(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
    }

    public function test_the_scheduler_drains_the_queue_every_minute_without_overlapping(): void
    {
        $worker = $this->scheduled('queue:work');

        $this->assertNotNull($worker, 'queue:work is not scheduled');
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertStringContainsString('--stop-when-empty', (string) $worker->command);
        $this->assertStringContainsString('--max-time=50', (string) $worker->command);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertSame(10, $worker->expiresAt);
        $this->assertTrue($worker->runInBackground);
        $this->assertTrue($worker->onOneServer);
    }

    public function test_failed_jobs_are_pruned_daily(): void
    {
        $prune = $this->scheduled('queue:prune-failed');

        $this->assertNotNull($prune);
        $this->assertSame('0 0 * * *', $prune->expression);
        $this->assertStringContainsString('--hours=720', (string) $prune->command);
    }
}
