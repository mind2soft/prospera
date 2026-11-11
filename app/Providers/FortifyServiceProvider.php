<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\FinancialProfileInvite;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public const INVITES_PER_MINUTE = 10;

    public const INVITES_PER_DAY = 50;

    public const REGISTRATIONS_PER_MINUTE = 5;

    public const REGISTRATIONS_PER_HOUR = 20;

    public const PASSWORD_RESETS_PER_MINUTE = 6;

    public const PASSWORD_CONFIRMATIONS_PER_MINUTE = 6;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function (Request $request) {
            // The invite key travels either as a query parameter
            // (/register?invite_key=…) or as a path segment (/invites/{key}).
            $key = $request->route('inviteKey') ?? $request->query('invite_key');

            $invite = FinancialProfileInvite::contextFor($key === null ? null : (string) $key);

            return Inertia::render('auth/Register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'invite' => $invite,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Public invite endpoints (landing page and key validation).
        RateLimiter::for('invites', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Invite creation sends an email to an arbitrary address: limited
        // per IP and, so rotating IPs does not help, per user per minute and
        // per day.
        RateLimiter::for('invite-creation', function (Request $request) {
            $userId = (string) $request->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(self::INVITES_PER_MINUTE)->by('user-minute:'.$userId),
                Limit::perDay(self::INVITES_PER_DAY)->by('user-day:'.$userId),
            ];
        });

        // Registration sends a verification email to the submitted address.
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(self::REGISTRATIONS_PER_MINUTE)->by('minute:'.$request->ip()),
                Limit::perHour(self::REGISTRATIONS_PER_HOUR)->by('hour:'.$request->ip()),
            ];
        });

        // Password-reset requests email the account: per IP, and per
        // submitted address so rotating IPs cannot flood one inbox (the
        // broker's own 60-second throttle only covers existing accounts).
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(self::PASSWORD_RESETS_PER_MINUTE)->by('ip:'.$request->ip()),
                Limit::perMinute(self::PASSWORD_RESETS_PER_MINUTE)->by('email:'.Str::lower((string) $request->input(Fortify::email()))),
            ];
        });

        // Password confirmation is a password guess on a signed-in session.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(self::PASSWORD_CONFIRMATIONS_PER_MINUTE)
                ->by('user:'.$request->user()?->getAuthIdentifier().'|'.$request->ip());
        });

        // Public contact form (issue #17): first-line guard in front of the
        // 12-hour per-IP cooldown.
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
