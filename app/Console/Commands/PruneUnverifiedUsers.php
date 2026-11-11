<?php

namespace App\Console\Commands;

use App\Actions\DeleteAccount;
use App\Models\Budget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Console\Command;

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
     */
    public function handle(DeleteAccount $deleteAccount): int
    {
        $cutoff = now()->subDays((int) config('prospera.unverified_account_retention_days', 30));

        $count = 0;

        User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', $cutoff)
            ->where('updated_at', '<=', $cutoff)
            ->whereDoesntHave('envelopes')
            ->with('profiles')
            ->lazyById()
            ->each(function (User $user) use ($deleteAccount, &$count): void {
                if (! $user->profiles->every(fn (FinancialProfile $profile): bool => $this->isEmptySoloProfile($profile))) {
                    return;
                }

                $deleteAccount->handle($user);
                $count++;
            });

        $this->info($count.' unverified account(s) deleted.');

        return self::SUCCESS;
    }

    private function isEmptySoloProfile(FinancialProfile $profile): bool
    {
        return $profile->isSolo()
            && ! Budget::query()->where('profile_id', $profile->id)->exists()
            && ! Ledger::query()->where('profile_id', $profile->id)->exists();
    }
}
