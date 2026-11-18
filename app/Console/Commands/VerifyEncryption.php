<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * The APP_KEY rotation gate (issue #83): check that every persistent payload
 * encrypted directly with APP_KEY decrypts — contact messages, transaction
 * notes, queued and
 * failed mail payloads (`jobs`, `failed_jobs`: the mailables are encrypted in
 * the queue via ShouldBeEncrypted) and 2FA secrets. The amount checks
 * (`amounts:verify`) do not see them.
 *
 * Two modes:
 *
 * - Default (the runbook's old-key-removal gate, step 7 of "Rotating
 *   APP_KEY"): a fresh encrypter holding the **current** key alone, never the
 *   previous keys — a payload still on a previous key is a failure, so
 *   `APP_PREVIOUS_KEYS` may only be removed once this passes.
 * - `--allow-previous-keys` (the deploy gate): the configured keys only
 *   (current + APP_PREVIOUS_KEYS, exactly what the worker would resolve) —
 *   the rotation window passes until the retained data is migrated, while a
 *   key removed too early is refused before the release goes live.
 *
 * Payloads are decrypted in place only to prove the key migration: the outer
 * JSON and the serialized bytes are never rewritten, nothing is deleted, and
 * failures are reported by id without any payload content.
 */
class VerifyEncryption extends Command
{
    private const CHUNK = 500;

    protected $signature = 'encryption:verify
        {--allow-previous-keys : Verify against all configured keys (current + APP_PREVIOUS_KEYS) instead of the current key alone — the deploy gate}';

    protected $description = 'Check that every APP_KEY-encrypted datum (contact messages, queued/failed mail, 2FA secrets) decrypts (docs/encryption.md)';

    public function handle(): int
    {
        if (! is_string(config('app.key')) || config('app.key') === '') {
            $this->error('No usable APP_KEY is configured (docs/encryption.md).');

            return self::FAILURE;
        }

        $allowPrevious = (bool) $this->option('allow-previous-keys');
        $current = $allowPrevious
            ? app(Encrypter::class)
            : ReencryptContactMessages::currentKeyEncrypter();

        /** @var list<array{table: string, id: int, label: string, error: string}> $failures */
        $failures = [
            ...$this->checkContactMessages($current, $allowPrevious),
            ...$this->checkTransactionNotes($current, $allowPrevious),
            ...$this->checkUsers($current, $allowPrevious),
            ...$this->checkJobs('jobs', $current, $allowPrevious),
            ...$this->checkJobs('failed_jobs', $current, $allowPrevious),
        ];

        foreach ($failures as $failure) {
            $label = $failure['label'] === '' ? '' : " ({$failure['label']})";
            $this->error("{$failure['table']} #{$failure['id']}{$label}: {$failure['error']}");
        }

        if ($failures !== []) {
            // The scheduler's output goes to /dev/null (docs/encryption.md):
            // log the failure so a stranded key is caught in the logs too.
            Log::error(
                'APP_KEY data verification failed: '.count($failures).' retained payload(s) do not decrypt'
                    .($allowPrevious ? ' with the configured keys — restore the removed APP_PREVIOUS_KEYS.' : ' with the current key alone — keep APP_PREVIOUS_KEYS until they are migrated.').' See docs/encryption.md, "Rotating APP_KEY".',
                ['failures' => array_slice($failures, 0, 20)],
            );

            $this->error(count($failures).' payload(s) failed verification — '.($allowPrevious
                ? 'restore the removed APP_PREVIOUS_KEYS.'
                : 'do not remove APP_PREVIOUS_KEYS yet.'));

            return self::FAILURE;
        }

        $this->info($allowPrevious
            ? 'Everything encrypted with APP_KEY decrypts with the configured keys.'
            : 'Everything encrypted with APP_KEY decrypts with the current key alone.');

        return self::SUCCESS;
    }

    /**
     * The failure message for an unreadable payload, per mode.
     */
    private function failure(bool $allowPrevious, string $strict, string $relaxed): string
    {
        return $allowPrevious ? $relaxed : $strict;
    }

    /**
     * @return list<array{table: string, id: int, label: string, error: string}>
     */
    private function checkContactMessages(Encrypter $current, bool $allowPrevious): array
    {
        $failures = [];
        $error = $this->failure(
            $allowPrevious,
            'still on a previous key — run contact:reencrypt (docs/encryption.md)',
            'cannot be decrypted with the configured APP_KEY set — restore the removed APP_PREVIOUS_KEYS',
        );

        DB::table('contact_submissions')->chunkById(self::CHUNK, function (Collection $rows) use (&$failures, $current, $error): void {
            foreach ($rows as $row) {
                $raw = (string) $row->message;

                if ($raw === '' || $this->decryptsString($raw, $current)) {
                    continue;
                }

                $failures[] = ['table' => 'contact_submissions', 'id' => (int) $row->id, 'label' => '', 'error' => $error];
            }
        });

        return $failures;
    }

    /**
     * Transaction notes use Laravel's encrypted cast and follow the same
     * APP_KEY rotation window as retained contact messages.
     *
     * @return list<array{table: string, id: int, label: string, error: string}>
     */
    private function checkTransactionNotes(Encrypter $current, bool $allowPrevious): array
    {
        $failures = [];
        $error = $this->failure(
            $allowPrevious,
            'still on a previous key — run contact:reencrypt (docs/encryption.md)',
            'cannot be decrypted with the configured APP_KEY set — restore the removed APP_PREVIOUS_KEYS',
        );

        DB::table('transactions')->whereNotNull('note')->chunkById(self::CHUNK, function (Collection $rows) use (&$failures, $current, $error): void {
            foreach ($rows as $row) {
                if ($this->decryptsString((string) $row->note, $current)) {
                    continue;
                }

                $failures[] = ['table' => 'transactions', 'id' => (int) $row->id, 'label' => 'note', 'error' => $error];
            }
        });

        return $failures;
    }

    /**
     * 2FA secrets and recovery codes are re-encrypted by the runbook's step 5
     * one-liner; this check is what proves the migration completed. Fortify
     * stores them with the serialize-based encrypt(), through no cast.
     *
     * @return list<array{table: string, id: int, label: string, error: string}>
     */
    private function checkUsers(Encrypter $current, bool $allowPrevious): array
    {
        $failures = [];
        $error = $this->failure(
            $allowPrevious,
            'still on a previous key — rerun the rotation runbook\'s 2FA step (docs/encryption.md)',
            'cannot be decrypted with the configured APP_KEY set — restore the removed APP_PREVIOUS_KEYS',
        );

        DB::table('users')->whereNotNull('two_factor_secret')->chunkById(self::CHUNK, function (Collection $rows) use (&$failures, $current, $error): void {
            foreach ($rows as $row) {
                foreach (['two_factor_secret', 'two_factor_recovery_codes'] as $column) {
                    $raw = (string) ($row->{$column} ?? '');

                    if ($raw === '' || $this->decryptsPayload($raw, $current)) {
                        continue;
                    }

                    $failures[] = ['table' => 'users', 'id' => (int) $row->id, 'label' => $column, 'error' => $error];
                }
            }
        });

        return $failures;
    }

    /**
     * Encrypted queued payloads: the mailables' `data.command` holds a Laravel
     * encrypted blob inside the job's JSON payload. Payloads are decrypted in
     * place only to prove the key migration — they are unserialized in memory
     * only, exactly as the worker would resolve them, and never rewritten.
     *
     * @return list<array{table: string, id: int, label: string, error: string}>
     */
    private function checkJobs(string $table, Encrypter $current, bool $allowPrevious): array
    {
        $failures = [];
        $error = $this->failure(
            $allowPrevious,
            'still encrypted with a previous key — drain the queue or resolve the failed job (docs/encryption.md)',
            'cannot be decrypted with the configured APP_KEY set — restore the removed APP_PREVIOUS_KEYS',
        );

        DB::table($table)->chunkById(self::CHUNK, function (Collection $rows) use (&$failures, $current, $error, $table): void {
            foreach ($rows as $row) {
                $command = $this->encryptedCommand($row);

                // The queue encrypts the serialized job: decrypt (which
                // unserializes) is the exact format the worker resolves.
                if ($command === null || $this->decryptsPayload($command, $current)) {
                    continue;
                }

                $failures[] = [
                    'table' => $table,
                    'id' => (int) $row->id,
                    'label' => (string) ($this->decoded($row->payload)['data']['commandName'] ?? ''),
                    'error' => $error,
                ];
            }
        });

        return $failures;
    }

    /**
     * The encrypted job payload of a row, or null when the row carries no
     * encrypted payload: plain serialized jobs start with the PHP class
     * marker and never decode to Laravel's encrypted format, and a malformed
     * row is left to fail on its own when the worker resolves it.
     */
    private function encryptedCommand(stdClass $row): ?string
    {
        $data = $this->decoded($row->payload)['data'] ?? null;

        if (! is_array($data) || ! is_string($command = $data['command'] ?? null)) {
            return null;
        }

        // A Laravel encrypted payload is base64 of a JSON object with the iv,
        // value and mac of the ciphertext (tag for AES-GCM).
        $decoded = json_decode(base64_decode($command, true) ?: '', true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac'])
            ? $command
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $payload): array
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether $raw decrypts with $encrypter alone, as an encrypted-cast
     * column stores it: the plain value encrypted, no serialization.
     */
    private function decryptsString(string $raw, Encrypter $encrypter): bool
    {
        try {
            $encrypter->decryptString($raw);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether $raw decrypts and unserializes with $encrypter alone, as the
     * queue stores a serialized encrypted job.
     */
    private function decryptsPayload(string $raw, Encrypter $encrypter): bool
    {
        try {
            $encrypter->decrypt($raw);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
