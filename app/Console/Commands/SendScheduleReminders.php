<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PaymentReminder;
use App\Models\EnvelopeSchedule;
use App\Models\User;
use App\Services\Schedules\ScheduleOccurrenceResolver;
use App\Services\Schedules\ScheduleReminderRecipients;
use App\Services\Schedules\ScheduleRule;
use App\Settings\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily payment-reminder run (issue #15, docs/scheduled-transactions.md):
 * for every active schedule with a reminder, each occurrence D with
 * `today ≥ D − reminder_days` and `D ≥ today` queues one email per eligible
 * recipient. Occurrences already past (downtime) are skipped, never sent
 * late.
 *
 * Each reminder is claimed once per (schedule, occurrence, recipient) in
 * `schedule_reminders` — the unique index is the claim — in the same
 * transaction as the queued mail (the `database` queue), so re-runs,
 * overlapping runs and catch-ups never double-send. The claim is keyed on
 * the date, so an edit moving an occurrence re-arms only the new date.
 * Claims of past occurrences can never match again and are pruned first.
 */
class SendScheduleReminders extends Command
{
    protected $signature = 'schedules:remind';

    protected $description = 'Queue payment reminders for upcoming scheduled transactions, once per occurrence and recipient';

    public function handle(ScheduleOccurrenceResolver $resolver, ScheduleReminderRecipients $recipients): int
    {
        $today = ScheduleRule::parseDate(now()->toDateString());
        $queued = 0;
        $failed = 0;

        $pruned = DB::table('schedule_reminders')->where('occurrence_date', '<', $today->toDateString())->delete();

        EnvelopeSchedule::query()
            ->whereNotNull('reminder_days')
            ->with('envelope:id,user_id')
            ->chunkById(200, function (Collection $schedules) use ($resolver, $recipients, $today, &$queued, &$failed): void {
                /** @var Collection<int, EnvelopeSchedule> $schedules */
                foreach ($schedules as $schedule) {
                    try {
                        $queued += $this->remind($schedule, $today, $resolver, $recipients);
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->error("Schedule {$schedule->id}: reminders could not be queued (reported; retried on the next run).");
                        $failed++;
                    }
                }
            });

        $this->info("{$queued} payment reminder(s) queued, {$pruned} past claim(s) pruned.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return int the number of reminders queued for the schedule
     */
    private function remind(
        EnvelopeSchedule $schedule,
        CarbonImmutable $today,
        ScheduleOccurrenceResolver $resolver,
        ScheduleReminderRecipients $recipients,
    ): int {
        $envelope = $schedule->envelope;
        // reminder_days is bounded by EnvelopeSchedule::MAX_REMINDER_DAYS.
        $until = $today->addDays((int) $schedule->reminder_days)->min(ScheduleRule::parseDate('9999-12-31'));
        $dates = $resolver->resolve($schedule->rule(), $today->toDateString(), $until->toDateString());

        if ($envelope === null || $dates === []) {
            return 0;
        }

        // A dispatch-time pre-filter only: the mailable re-checks
        // everything at send time. A user skipped here is not claimed, so
        // turning reminders back on before the occurrence still reminds.
        $users = User::query()
            ->whereIntegerInRaw('id', $recipients->userIds($envelope))
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->wantsNotification(NotificationSettings::PAYMENT_REMINDERS));

        $queued = 0;

        foreach ($dates as $date) {
            foreach ($users as $user) {
                $queued += (int) DB::transaction(function () use ($schedule, $date, $user): bool {
                    $claimed = DB::table('schedule_reminders')->insertOrIgnore([
                        'schedule_id' => $schedule->id,
                        'occurrence_date' => $date,
                        'user_id' => $user->id,
                        'queued_at' => now(),
                    ]) === 1;

                    if ($claimed) {
                        Mail::queue(new PaymentReminder($schedule->id, $date, $user->id));
                    }

                    return $claimed;
                });
            }
        }

        return $queued;
    }
}
