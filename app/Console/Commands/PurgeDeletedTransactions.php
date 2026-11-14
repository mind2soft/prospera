<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class PurgeDeletedTransactions extends Command
{
    protected $signature = 'transactions:purge-deleted';

    protected $description = 'Permanently delete the transactions deleted longer ago than the undo window';

    public function handle(): int
    {
        $this->info(Transaction::purgeExpiredDeletions().' deleted transaction(s) purged.');

        return self::SUCCESS;
    }
}
