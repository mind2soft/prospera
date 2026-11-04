<?php

namespace App\Console\Commands;

use App\Models\FinancialProfileKey;
use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;
use Throwable;

class PruneAmountKeys extends Command
{
    protected $signature = 'amounts:prune-keys
        {--grace=24 : Hours a retired key version is kept before deletion}';

    protected $description = 'Delete retired amount data keys once no amount uses them (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        $grace = (string) $this->option('grace');

        if (! preg_match('/^\d+$/D', $grace)) {
            $this->error('--grace must be a whole number of hours.');

            return self::INVALID;
        }

        $cutoff = now()->subHours((int) $grace);

        $profileIds = FinancialProfileKey::query()
            ->whereNotNull('retired_at')
            ->where('retired_at', '<', $cutoff)
            ->distinct()
            ->orderBy('profile_id')
            ->pluck('profile_id');

        $failed = 0;

        foreach ($profileIds as $profileId) {
            try {
                $count = $manager->prune((int) $profileId, $cutoff);
                $this->line("Profile {$profileId}: {$count} retired key version(s) deleted.");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Profile {$profileId}: pruning failed and was rolled back ({$e->getMessage()}).");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
