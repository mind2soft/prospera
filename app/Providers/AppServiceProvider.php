<?php

namespace App\Providers;

use App\Models\FinancialProfileInvite;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyHealthCheck;
use App\Security\Amounts\AmountKeyRing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AmountCipher::class);
        // Scoped: decrypted amount keys live for one request/job only.
        $this->app->scoped(AmountKeyRing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureEvents();
        $this->assertAmountKeysAreReadable();
    }

    /**
     * Invite registrations become profile memberships once the new account
     * verifies its email (docs/shared-finances.md — invites).
     */
    protected function configureEvents(): void
    {
        // The account is already verified when this runs: a failure must not
        // turn the verification into a 500. It is reported and the
        // EnsureAccountHasProfile middleware completes the grant on the next
        // authenticated request (the post-verification redirect).
        Event::listen(Verified::class, function (Verified $event): void {
            if ($event->user instanceof User) {
                try {
                    FinancialProfileInvite::completePendingFor($event->user);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });

        // Keys or ids memoized inside a rolled-back transaction may not exist
        // any more (and ids can be reused): drop the whole memo.
        Event::listen(TransactionRolledBack::class, function (): void {
            if ($this->app->resolved(AmountKeyRing::class)) {
                $this->app->make(AmountKeyRing::class)->forget();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // The contact form has nowhere to deliver to without it (issue #17).
        if ($this->isRealDeployment() && blank(config('mail.contact_address'))) {
            throw new RuntimeException(
                'MAIL_CONTACT_ADDRESS must be set in production — the contact form needs a recipient.',
            );
        }

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Boot-time smoke check (issue #36): a rotated APP_KEY without
     * APP_PREVIOUS_KEYS breaks every wrapped amount key at once — fail fast
     * with an actionable error instead of scattered AmountDecryptionException
     * 500s (docs/encryption.md).
     *
     * HTTP requests only: artisan must keep booting so the documented
     * recovery (`amounts:rewrap-keys`) stays runnable. Console-side failures
     * surface through the daily scheduled `amounts:verify` instead.
     */
    protected function assertAmountKeysAreReadable(): void
    {
        if (! $this->shouldVerifyAmountsAtBoot()) {
            return;
        }

        $this->app->make(AmountKeyHealthCheck::class)->assertAppKeyCanUnwrap();
    }

    protected function shouldVerifyAmountsAtBoot(): bool
    {
        // In maintenance mode the request only renders the 503 page: the
        // check must not stand in its way (e.g. during a deploy's
        // migrations, or with the database down).
        return $this->isRealDeployment()
            && ! $this->runsInConsole()
            && ! $this->app->isDownForMaintenance();
    }

    protected function runsInConsole(): bool
    {
        return $this->app->runningInConsole();
    }

    /**
     * A production environment with an environment file: a bare boot without
     * one (e.g. `composer install` running `package:discover` in CI) defaults
     * APP_ENV to production but is not a real deployment.
     */
    private function isRealDeployment(): bool
    {
        return $this->app->isProduction()
            && file_exists($this->app->environmentFilePath());
    }
}
