<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Models\Budget;
use App\Models\Envelope;
use Illuminate\Support\Facades\DB;

/**
 * Who receives an envelope's payment reminders
 * (docs/scheduled-transactions.md): the envelope owner plus every member of
 * the profiles referencing the envelope — those with a published budget
 * assigning it, i.e. the profiles its schedules materialize into. Resolved
 * by `schedules:remind` and re-checked by the mailable at send time, so a
 * member who left the profile (or a profile whose budgets no longer assign
 * the envelope) gets nothing. Verification and the notification gate are
 * the caller's concern.
 */
final class ScheduleReminderRecipients
{
    /**
     * @return list<int> deduplicated user ids, ascending
     */
    public function userIds(Envelope $envelope): array
    {
        return array_values(DB::table('financial_profile_user')
            ->whereIn('profile_id', Budget::publishedAssigning($envelope->id)->select('profile_id'))
            ->pluck('user_id')
            ->push($envelope->user_id)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->all());
    }

    public function includes(Envelope $envelope, int $userId): bool
    {
        return $envelope->user_id === $userId
            || DB::table('financial_profile_user')
                ->where('user_id', $userId)
                ->whereIn('profile_id', Budget::publishedAssigning($envelope->id)->select('profile_id'))
                ->exists();
    }
}
