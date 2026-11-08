<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use Illuminate\Console\Command;

class PruneContactSubmissions extends Command
{
    protected $signature = 'contact:prune';

    protected $description = 'Delete contact-form submissions past the retention window (CONTACT_LOG_RETENTION_DAYS)';

    public function handle(): int
    {
        $days = (int) config('prospera.contact_retention_days', 90);

        $count = ContactSubmission::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info($count.' contact submission(s) deleted.');

        return self::SUCCESS;
    }
}
