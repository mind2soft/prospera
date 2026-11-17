<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountKeyManager;
use App\Security\Amounts\AmountKeyScope;
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

        $failed = 0;

        foreach (AmountKeyScope::cases() as $scope) {
            $profileIds = $scope->keyModel()::query()
                ->whereNotNull('retired_at')
                ->where('retired_at', '<', $cutoff)
                ->distinct()
                ->orderBy($scope->column())
                ->pluck($scope->column());

            foreach ($profileIds as $id) {
                $profileId = AmountContext::id($id, $scope->column());
                $label = ucfirst($scope->value);
                try {
                    $count = $manager->prune($profileId, $cutoff, $scope);
                    $this->line("{$label} {$profileId}: {$count} retired key version(s) deleted.");
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("{$label} {$profileId}: pruning failed and was rolled back ({$e->getMessage()}).");
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
