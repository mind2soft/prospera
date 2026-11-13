<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyRing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        DB::transaction(fn () => $this->convertAmounts(toMinorUnits: true));
    }

    public function down(): void
    {
        // A rollback must never round already-recorded fractional transactions.
        DB::transaction(function (): void {
            $this->assertAmountsAreWholeUnits();
            $this->convertAmounts(toMinorUnits: false);
        });
    }

    private function convertAmounts(bool $toMinorUnits): void
    {
        $ring = app(AmountKeyRing::class);
        $cipher = app(AmountCipher::class);

        DB::table('transactions')
            ->join('ledgers', 'ledgers.id', '=', 'transactions.ledger_id')
            ->select('transactions.*', 'ledgers.profile_id')
            ->orderBy('transactions.id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($ring, $cipher, $toMinorUnits): void {
                foreach ($rows as $row) {
                    $attributes = (array) $row;
                    $profileId = (int) $attributes['profile_id'];
                    $ledgerId = (int) $attributes['ledger_id'];
                    $ring->rememberLedgerProfiles([$ledgerId], $profileId);

                    $context = Transaction::amountContextFor('amount', $attributes, $profileId);
                    $payload = (string) $attributes['amount'];
                    $amount = $ring->decrypt($payload, $context);

                    if ($toMinorUnits && abs($amount) > intdiv(PHP_INT_MAX, 100)) {
                        throw new RuntimeException("Transaction #{$attributes['id']} exceeds the supported amount range.");
                    }

                    $converted = $toMinorUnits ? $amount * 100 : intdiv($amount, 100);
                    $version = AmountCipher::keyVersionOf($payload, $context);
                    $convertedPayload = $cipher->encrypt($converted, $ring->key($profileId, $version), $context);

                    if ($cipher->decrypt($convertedPayload, $ring->key($profileId, $version), $context) !== $converted) {
                        throw new RuntimeException("Amount conversion round trip failed for transaction #{$attributes['id']}.");
                    }

                    DB::table('transactions')->where('id', $attributes['id'])->update([
                        'amount' => $convertedPayload,
                    ]);
                }
            }, 'transactions.id', 'id');
    }

    private function assertAmountsAreWholeUnits(): void
    {
        $ring = app(AmountKeyRing::class);

        DB::table('transactions')
            ->join('ledgers', 'ledgers.id', '=', 'transactions.ledger_id')
            ->select('transactions.*', 'ledgers.profile_id')
            ->orderBy('transactions.id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($ring): void {
                foreach ($rows as $row) {
                    $attributes = (array) $row;
                    $profileId = (int) $attributes['profile_id'];
                    $ledgerId = (int) $attributes['ledger_id'];
                    $ring->rememberLedgerProfiles([$ledgerId], $profileId);
                    $context = Transaction::amountContextFor('amount', $attributes, $profileId);
                    $amount = $ring->decrypt((string) $attributes['amount'], $context);

                    if ($amount % 100 !== 0) {
                        throw new RuntimeException('Cannot roll back transaction amount precision while fractional transactions exist.');
                    }
                }
            }, 'transactions.id', 'id');
    }
};
