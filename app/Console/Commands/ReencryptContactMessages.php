<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Re-encrypt the retained contact-form submissions with the current APP_KEY
 * (issue #83). `ContactSubmission::message` is cast `encrypted`, so it is
 * encrypted with APP_KEY directly — unlike the amounts, it is not covered by
 * `amounts:rewrap-keys` or `amounts:verify`, and the APP_KEY rotation runbook
 * must migrate it before the previous key leaves APP_PREVIOUS_KEYS.
 *
 * Idempotent and resumable: rows that already decrypt with the current key
 * alone are skipped, so rerunning after an interruption (or entirely) only
 * touches what is still on a previous key. Rows that decrypt with neither
 * key are reported by id and left untouched; a corrupted row makes the
 * command fail so the rotation is never declared complete over it.
 */
class ReencryptContactMessages extends Command
{
    private const CHUNK = 500;

    protected $signature = 'contact:reencrypt';

    protected $description = 'Re-encrypt retained contact-form messages with the current APP_KEY (docs/encryption.md)';

    public function handle(): int
    {
        if (! is_string(config('app.key')) || config('app.key') === '') {
            $this->error('No usable APP_KEY is configured (docs/encryption.md).');

            return self::FAILURE;
        }

        $current = self::currentKeyEncrypter();
        $reencrypted = 0;
        $skipped = 0;
        /** @var list<array{id: int, error: string}> $failures */
        $failures = [];

        DB::transaction(function () use (&$reencrypted, &$skipped, &$failures, $current): void {
            ContactSubmission::query()->orderBy('id')->lockForUpdate()->chunkById(self::CHUNK, function (Collection $rows) use (&$reencrypted, &$skipped, &$failures, $current): void {
                foreach ($rows as $row) {
                    $raw = (string) $row->getRawOriginal('message');

                    // Already on the current key: the encrypted cast stores
                    // the plain value encrypted (no serialization), so a
                    // successful decryptString is exactly what the cast
                    // needs to read it.
                    if ($raw === '' || $this->decrypts($raw, $current)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        // Decrypt through the configured keys (current +
                        // previous), re-encrypt with the current one; the
                        // model cast does both halves. Only `message` is
                        // written — metadata stays as it is.
                        $row->setAttribute('message', $row->getAttribute('message'));
                        $row->saveQuietly();
                        $reencrypted++;
                    } catch (Throwable $e) {
                        $failures[] = ['id' => (int) $row->id, 'error' => $e->getMessage()];
                    }
                }
            });
        });

        foreach ($failures as $failure) {
            $this->error("contact_submissions #{$failure['id']}: {$failure['error']}");
        }

        if ($failures !== []) {
            $this->error(count($failures).' contact message(s) could not be re-encrypted — do not remove the previous APP_KEY (docs/encryption.md).');

            return self::FAILURE;
        }

        $this->info("{$reencrypted} contact message(s) re-encrypted with the current application key ({$skipped} already on it).");

        return self::SUCCESS;
    }

    /**
     * An encrypter holding only the current key: a row that decrypts with it
     * is migrated, one that decrypts with the application's fallback
     * decryptor alone is not — verification must not count previous keys.
     */
    private function decrypts(string $raw, Encrypter $current): bool
    {
        try {
            $current->decryptString($raw);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A fresh encrypter for the current APP_KEY only (never the previous
     * keys), shared by the rotation commands. The plain Encrypter does not
     * decode the `base64:` prefix itself (only the bootstrapper does).
     */
    public static function currentKeyEncrypter(): Encrypter
    {
        $key = (string) config('app.key');

        return new Encrypter(
            Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key,
            (string) config('app.cipher'),
        );
    }
}
