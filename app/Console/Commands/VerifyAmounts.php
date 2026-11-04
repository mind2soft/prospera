<?php

namespace App\Console\Commands;

use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;

class VerifyAmounts extends Command
{
    protected $signature = 'amounts:verify
        {--profile=* : Financial profile id(s) to verify (default: all)}';

    protected $description = 'Check that every encrypted amount decrypts (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        /** @var list<string> $ids */
        $ids = $this->option('profile');

        foreach ($ids as $id) {
            if (! preg_match('/^[1-9]\d*$/D', $id)) {
                $this->error('--profile must be a positive integer id.');

                return self::INVALID;
            }
        }

        $failures = $manager->verify(array_map(intval(...), $ids));

        foreach ($failures as $failure) {
            $this->error("{$failure['table']} #{$failure['id']}: {$failure['error']}");
        }

        if ($failures !== []) {
            $this->error(count($failures).' amount(s) failed verification.');

            return self::FAILURE;
        }

        $this->info('All amounts decrypt.');

        return self::SUCCESS;
    }
}
