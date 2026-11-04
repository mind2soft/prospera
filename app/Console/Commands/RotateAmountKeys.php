<?php

namespace App\Console\Commands;

use App\Models\FinancialProfile;
use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;
use Throwable;

class RotateAmountKeys extends Command
{
    protected $signature = 'amounts:rotate-keys
        {--profile=* : Financial profile id(s) to rotate}
        {--all : Rotate every financial profile}';

    protected $description = 'Rotate the amount data keys and re-encrypt the amounts (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        $profileIds = $this->profileIds();

        if ($profileIds === null) {
            $this->error('Pass --profile=<id> (repeatable) or --all.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($profileIds as $profileId) {
            try {
                $count = $manager->rotate($profileId);
                $this->line("Profile {$profileId}: rotated, {$count} amount(s) re-encrypted.");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Profile {$profileId}: rotation failed and was rolled back ({$e->getMessage()}).");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<int>|null
     */
    private function profileIds(): ?array
    {
        /** @var list<string> $ids */
        $ids = $this->option('profile');

        if ($this->option('all')) {
            return $ids === [] ? array_values(array_map(intval(...), FinancialProfile::query()->orderBy('id')->pluck('id')->all())) : null;
        }

        if ($ids === []) {
            return null;
        }

        $profileIds = [];

        foreach ($ids as $id) {
            if (! preg_match('/^[1-9]\d*$/D', $id)) {
                return null;
            }

            $profileIds[] = (int) $id;
        }

        return $profileIds;
    }
}
