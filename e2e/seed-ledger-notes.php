<?php

/**
 * Playwright seed for the transaction notes spec (issue #121): the ledger
 * seed (e2e/seed-ledger.php), plus notes on some of its transactions —
 * a short one, a 200-character one (the maximum) and an older note on the
 * same envelope so the recording form has suggestions to offer.
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-ledger-notes.php
 */

use App\Models\Transaction;

require __DIR__.'/seed-ledger.php';

$noteOn = function (string $envelope, string $date, string $note) use ($ledger): void {
    Transaction::query()
        ->where('ledger_id', $ledger->id)
        ->whereHas('envelope', fn ($q) => $q->where('name', $envelope))
        ->where('event_at', $date.' 00:00:00')
        ->firstOrFail()
        ->forceFill(['note' => $note])
        ->save();
};

$noteOn('Food', '2026-10-15', 'Weekly groceries at the market');
$noteOn('Food', '2026-09-10', 'Farmers market');
$noteOn('Mortgage', '2026-10-20', str_repeat('Partial payment, the balance is due next month. ', 4).'Ref 1234');

echo 'seeded notes ledger_id='.$ledger->id.PHP_EOL;
