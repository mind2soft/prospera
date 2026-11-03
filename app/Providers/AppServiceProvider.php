<?php

namespace App\Providers;

use App\Models\FinancialProfileInvite;
use App\Models\User;
use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountKeyRing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
    }

    /**
     * Invite registrations become profile memberships once the new account
     * verifies its email (docs/shared-finances.md — invites).
     */
    protected function configureEvents(): void
    {
        Event::listen(Verified::class, function (Verified $event): void {
            if ($event->user instanceof User) {
                FinancialProfileInvite::completePendingFor($event->user);
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
}
