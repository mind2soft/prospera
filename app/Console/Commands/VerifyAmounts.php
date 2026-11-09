<?php

namespace App\Console\Commands;

use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
            // The scheduler's output goes to /dev/null (docs/encryption.md):
            // log the failure so a rotated APP_KEY is caught even when no
            // request touches amounts.
            Log::error(
                'Amount verification failed: '.count($failures).' encrypted amount(s) do not decrypt — a rotated APP_KEY without APP_PREVIOUS_KEYS? See docs/encryption.md, "Detecting a broken APP_KEY".',
                ['failures' => array_slice($failures, 0, 20)],
            );

            $this->error(count($failures).' amount(s) failed verification.');

            return self::FAILURE;
        }

        $this->info('All amounts decrypt.');

        return self::SUCCESS;
    }
}
