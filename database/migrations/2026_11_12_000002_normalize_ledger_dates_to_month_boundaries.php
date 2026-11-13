<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ledgers')
            ->select(['id', 'start_date', 'end_date'])
            ->orderBy('id')
            ->chunkById(500, function ($ledgers): void {
                foreach ($ledgers as $ledger) {
                    DB::table('ledgers')
                        ->where('id', $ledger->id)
                        ->update([
                            'start_date' => CarbonImmutable::parse($ledger->start_date)->startOfMonth()->toDateString(),
                            'end_date' => CarbonImmutable::parse($ledger->end_date)->endOfMonth()->toDateString(),
                        ]);
                }
            });
    }

    /**
     * Existing ledger periods are expanded to calendar months; their prior
     * values cannot be recovered, so rollback intentionally leaves them intact.
     */
    public function down(): void
    {
        // This data normalization is intentionally irreversible.
    }
};
