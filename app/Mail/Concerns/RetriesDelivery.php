<?php

namespace App\Mail\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry policy of the queued mailables: a transient SMTP error must not move
 * the message straight to failed_jobs (`queue:work` tries once by default).
 * Five attempts over about 20 minutes, then the failure is logged — the
 * mailable class and the error only, never its (encrypted) content.
 */
trait RetriesDelivery
{
    /** Delivery attempts before the job fails. */
    public int $tries = 5;

    /**
     * Seconds to wait before each retry (the last value repeats).
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function failed(Throwable $exception): void
    {
        Log::error('Queued mail delivery failed', [
            'mailable' => static::class,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
