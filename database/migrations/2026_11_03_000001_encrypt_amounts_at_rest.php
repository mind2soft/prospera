<?php

use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyRing;
use App\Security\Amounts\DataKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encryption at rest of money amounts (issue #4, docs/encryption.md): every
 * financial profile gets a wrapped AES-256-GCM data key and the three money
 * columns are converted in place to authenticated ciphertext.
 *
 * Works on raw rows only (no models). The "pa1" contexts are frozen here —
 * identical to the models' amountContextFor() today (asserted by
 * EncryptAmountsMigrationTest) — so later model changes cannot alter what
 * this migration decrypts. Every value is checked by a round trip before
 * the plaintext column is dropped. Fully reversible.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        $this->guardSqliteTableRebuilds();

        Schema::create('financial_profile_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('wrapped_key');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'version']);
        });

        $cipher = new AmountCipher;

        /** @var array<int, DataKey> $keys */
        $keys = [];

        DB::transaction(function () use (&$keys): void {
            foreach (DB::table('financial_profiles')->orderBy('id')->pluck('id') as $profileId) {
                $key = DataKey::generate((int) $profileId, 1);

                DB::table('financial_profile_keys')->insert([
                    'profile_id' => $key->profileId,
                    'version' => $key->version,
                    'wrapped_key' => AmountKeyRing::wrap($key),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $keys[$key->profileId] = $key;
            }
        });

        foreach ($this->columns() as [$table, $column, $contextFor, $query]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->string($column.'_ct', 80)->nullable();
            });

            DB::transaction(function () use ($table, $column, $contextFor, $query, $cipher, $keys): void {
                $query()->chunkById(self::CHUNK, function ($rows) use ($table, $column, $contextFor, $cipher, $keys): void {
                    foreach ($rows as $row) {
                        $attributes = (array) $row;
                        $profileId = (int) $attributes['profile_id'];
                        $amount = $this->plaintext($attributes[$column], $table, $column, (int) $attributes['id']);
                        $context = $contextFor($attributes, $profileId);

                        $payload = $cipher->encrypt($amount, $keys[$profileId], $context);

                        if ($cipher->decrypt($payload, $keys[$profileId], $context) !== $amount) {
                            throw new RuntimeException("Round trip failed for {$table} #{$attributes['id']}.");
                        }

                        DB::table($table)->where('id', $attributes['id'])->update([$column.'_ct' => $payload]);
                    }
                }, $table.'.id', 'id');
            });

            if (DB::table($table)->whereNull($column.'_ct')->exists()) {
                throw new RuntimeException("Some {$table}.{$column} values were not encrypted.");
            }

            $this->replaceColumn($table, $column.'_ct', $column, fn (Blueprint $blueprint) => $blueprint->string($column, 80));
        }
    }

    public function down(): void
    {
        $this->guardSqliteTableRebuilds();

        /** @var array<int, array<int, DataKey>> $keys */
        $keys = [];

        foreach (DB::table('financial_profile_keys')->get() as $row) {
            $keys[(int) $row->profile_id][(int) $row->version] = AmountKeyRing::unwrap((int) $row->profile_id, (int) $row->version, $row->wrapped_key);
        }

        $cipher = new AmountCipher;

        foreach ($this->columns() as [$table, $column, $contextFor, $query]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->integer($column.'_pt')->nullable();
            });

            DB::transaction(function () use ($table, $column, $contextFor, $query, $cipher, $keys): void {
                $query()->chunkById(self::CHUNK, function ($rows) use ($table, $column, $contextFor, $cipher, $keys): void {
                    foreach ($rows as $row) {
                        $attributes = (array) $row;
                        $profileId = (int) $attributes['profile_id'];
                        $context = $contextFor($attributes, $profileId);
                        $version = AmountCipher::keyVersionOf((string) $attributes[$column], $context);
                        $key = $keys[$profileId][$version]
                            ?? throw new AmountDecryptionException("Unknown amount key version {$version} for profile {$profileId}.");

                        DB::table($table)->where('id', $attributes['id'])->update([
                            $column.'_pt' => $cipher->decrypt((string) $attributes[$column], $key, $context),
                        ]);
                    }
                }, $table.'.id', 'id');
            });

            if (DB::table($table)->whereNull($column.'_pt')->exists()) {
                throw new RuntimeException("Some {$table}.{$column} values were not decrypted.");
            }

            $this->replaceColumn($table, $column.'_pt', $column, fn (Blueprint $blueprint) => $blueprint->integer($column));
        }

        Schema::dropIfExists('financial_profile_keys');
    }

    /**
     * The money columns, their frozen "pa1" context builder and a query
     * yielding the rows with their owning profile id.
     *
     * @return list<array{0: string, 1: string, 2: Closure(array<string, mixed>, int): AmountContext, 3: Closure(): Builder}>
     */
    private function columns(): array
    {
        return [
            [
                'transactions', 'amount',
                fn (array $row, int $profileId): AmountContext => new AmountContext('transactions', 'amount', $profileId, [
                    'l' => AmountContext::id($row['ledger_id'] ?? null, 'ledger_id'),
                    'e' => AmountContext::id($row['envelope_id'] ?? null, 'envelope_id'),
                    'd' => AmountContext::date($row['event_at'] ?? null),
                ]),
                fn (): Builder => DB::table('transactions')
                    ->join('ledgers', 'ledgers.id', '=', 'transactions.ledger_id')
                    ->select('transactions.*', 'ledgers.profile_id'),
            ],
            [
                'budget_envelopes', 'amount',
                fn (array $row, int $profileId): AmountContext => new AmountContext('budget_envelopes', 'amount', $profileId, [
                    'b' => AmountContext::id($row['budget_id'] ?? null, 'budget_id'),
                    'e' => AmountContext::id($row['envelope_id'] ?? null, 'envelope_id'),
                ]),
                fn (): Builder => DB::table('budget_envelopes')
                    ->join('budgets', 'budgets.id', '=', 'budget_envelopes.budget_id')
                    ->select('budget_envelopes.*', 'budgets.profile_id'),
            ],
            [
                'ledgers', 'starting_balance',
                fn (array $row, int $profileId): AmountContext => new AmountContext('ledgers', 'starting_balance', $profileId),
                fn (): Builder => DB::table('ledgers')->select('ledgers.*'),
            ],
        ];
    }

    /**
     * Swap the converted column in for the original one, then make it NOT
     * NULL.
     *
     * @param  Closure(Blueprint): mixed  $definition
     */
    private function replaceColumn(string $table, string $from, string $to, Closure $definition): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($to) {
            $blueprint->dropColumn($to);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
            $blueprint->renameColumn($from, $to);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($definition) {
            $definition($blueprint)->nullable(false)->change();
        });
    }

    /**
     * SQLite changes columns by rebuilding the table (DROP TABLE). Laravel
     * disables foreign keys around it, which SQLite ignores inside a
     * transaction — dropping `ledgers` would then cascade-delete its
     * transactions. Refuse before touching anything.
     */
    private function guardSqliteTableRebuilds(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('This migration must not run inside a transaction on SQLite.');
        }
    }

    private function plaintext(mixed $value, string $table, string $column, int $id): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/D', $value)) {
            return (int) $value;
        }

        throw new RuntimeException("{$table}.{$column} #{$id} is not a plaintext integer amount.");
    }
};
