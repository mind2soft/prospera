<?php

namespace App\Console\Commands;

use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;

class RewrapAmountKeys extends Command
{
    protected $signature = 'amounts:rewrap-keys';

    protected $description = 'Re-wrap every amount data key with the current APP_KEY (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        $count = $manager->rewrapAll();

        $this->info("{$count} amount key(s) re-wrapped with the current application key.");

        return self::SUCCESS;
    }
}
