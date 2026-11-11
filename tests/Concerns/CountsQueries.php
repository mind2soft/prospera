<?php

namespace Tests\Concerns;

use App\Security\Amounts\AmountKeyRing;
use Illuminate\Support\Facades\DB;

/**
 * Query-count budgets for hot endpoints: run a request and return the SQL
 * statements it executed. The amount key ring is reset first so memoized
 * keys and profile lookups from the test's own setup do not hide queries.
 */
trait CountsQueries
{
    /**
     * @return list<string>
     */
    protected function queriesDuring(callable $callback): array
    {
        app(AmountKeyRing::class)->forget();

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return array_values(array_map(
                fn (array $query): string => $query['query'],
                DB::getQueryLog(),
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
