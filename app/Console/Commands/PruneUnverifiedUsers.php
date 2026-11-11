<?php

namespace App\Console\Commands;

use App\Actions\DeleteAccount;
use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneUnverifiedUsers extends Command
{
    protected $signature = 'users:prune-unverified';

    protected $description = 'Delete accounts left unverified past the retention window (UNVERIFIED_ACCOUNT_RETENTION_DAYS) that hold no data';

    /**
     * Only accounts that are unverified, untouched (created and last updated)
     * for the whole window, and hold no data are deleted: they own no
     * envelope and every profile they belong to is a solo profile without
     * budgets or ledgers. An account that verified, then changed its email
     * (which resets verification) and kept its data is never pruned.
     *
     * Each candidate is re-checked under a lock on its row right before the
     * deletion (it may have verified or created data since it was selected),
     * and deleted in its own transaction: one failing account is reported
     * and skipped, the rest of the run goes on (the next run retries it).
     */
    public function handle(DeleteAccount $deleteAccount): int
    {
        $cutoff = now()->subDays((int) config('prospera.unverified_account_retention_days', 30));

        $deleted = 0;
        $failed = 0;

        $this->candidates($cutoff)
            ->select('users.id')
            ->lazyById()
            ->each(function (User $candidate) use ($deleteAccount, $cutoff, &$deleted, &$failed): void {
                try {
                    $wasDeleted = DB::transaction(function () use ($candidate, $deleteAccount, $cutoff): bool {
                        /** @var User|null $user */
                        $user = $this->candidates($cutoff)
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->first();

                        if ($user === null || ! $this->holdsNoData($user)) {
                            return false;
                        }

                        $deleteAccount->handle($user);

                        return true;
                    });
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;

                    return;
                }

                if ($wasDeleted) {
                    $deleted++;
                }
            });

        $this->info($deleted.' unverified account(s) deleted.');

        if ($failed > 0) {
            $this->warn($failed.' unverified account(s) could not be deleted (reported; retried on the next run).');
        }

        return self::SUCCESS;
    }

    /**
     * Unverified accounts untouched since the cutoff that own no envelope.
     *
     * @return Builder<User>
     */
    private function candidates(CarbonInterface $cutoff): Builder
    {
        return User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', $cutoff)
            ->where('updated_at', '<=', $cutoff)
            ->whereDoesntHave('envelopes');
    }

    private function holdsNoData(User $user): bool
    {
        return $user->profiles()->get()->every(
            fn (FinancialProfile $profile): bool => $this->isEmptySoloProfile($profile),
        );
    }

    private function isEmptySoloProfile(FinancialProfile $profile): bool
    {
        return $profile->isSolo()
            && ! Budget::query()->where('profile_id', $profile->id)->exists()
            && ! Ledger::query()->where('profile_id', $profile->id)->exists();
    }
}
