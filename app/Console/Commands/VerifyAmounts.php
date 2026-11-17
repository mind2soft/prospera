<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\Amounts\AmountKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyAmounts extends Command
{
    protected $signature = 'amounts:verify
        {--profile=* : Financial profile id(s) to verify}
        {--envelope=* : Envelope id(s) to verify (no targets: all profiles and initialized envelopes)}';

    protected $description = 'Check that every encrypted amount decrypts (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        /** @var list<string> $profiles */
        $profiles = $this->option('profile');
        /** @var list<string> $envelopes */
        $envelopes = $this->option('envelope');

        foreach ([...$profiles, ...$envelopes] as $id) {
            if (! preg_match('/^[1-9]\d*$/D', (string) $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                $this->error('--profile and --envelope must be positive integer ids.');

                return self::INVALID;
            }
        }

        $failures = $manager->verify(array_values(array_map(intval(...), array_unique($profiles))), array_values(array_map(intval(...), array_unique($envelopes))));

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
