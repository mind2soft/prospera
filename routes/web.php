<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvelopeController;
use App\Http\Controllers\FinancialProfileController;
use App\Http\Controllers\FinancialProfileInviteController;
use App\Http\Controllers\LedgerController;
use App\Http\Middleware\EnsureAccountHasProfile;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::inertia('/', 'Welcome')->name('home');

// Public privacy policy (issue #18): reachable without authentication; the
// localized copy is loaded and rendered client-side (see docs/localization.md).
Route::inertia('privacy', 'Privacy')->name('privacy');

// Public contact form (issue #17): reachable by guests and authenticated
// users; submissions are throttled and rate-limited per IP.
Route::get('contact', [ContactController::class, 'show'])->name('contact.show');
Route::post('contact', [ContactController::class, 'store'])
    ->name('contact.store')
    ->middleware('throttle:contact');
Route::get('contact/sent', [ContactController::class, 'sent'])->name('contact.sent');

// Registration (Fortify's own route has no throttle): redeclared here — app
// routes load after Fortify's, so this one replaces it — to rate-limit the
// verification emails it sends to arbitrary addresses.
if (Features::enabled(Features::registration())) {
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard'), 'throttle:register'])
        ->name('register.store');
}

// Same for the password-reset request (an email to the account's address)
// and the password confirmation (a password guess on a signed-in session):
// Fortify declares both without a throttle.
if (Features::enabled(Features::resetPasswords())) {
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware(['guest:'.config('fortify.guard'), 'throttle:password-reset'])
        ->name('password.email');
}

Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard'), 'throttle:password-confirm'])
    ->name('password.confirm.store');

// Public invite validation (used by the invite landing page and the
// registration flow); accepting requires authentication below.
Route::get('api/invites/{inviteKey}', [FinancialProfileInviteController::class, 'show'])
    ->name('api.invites.show')
    ->middleware('throttle:invites');

// Invite landing page: renders the registration page with the invite context
// (new users are routed to registration; membership is granted on account
// verification).
Route::get('invites/{inviteKey}', [FinancialProfileInviteController::class, 'landing'])
    ->name('invites.landing')
    ->middleware('throttle:invites');

// EnsureAccountHasProfile completes an invite grant whose verification
// listener failed (no verified account is left without a profile).
Route::middleware(['auth', 'verified', EnsureAccountHasProfile::class])->group(function () {
    // Accept link from the invitation email: never changes state — it lands
    // on the invite confirmation page, whose button POSTs the accept. Guests
    // are redirected to the sign-in page (the auth middleware preserves the
    // intended URL) and unverified users to the verification notice.
    Route::get('invites/{inviteKey}/accept', [FinancialProfileInviteController::class, 'acceptViaLink'])
        ->name('invites.accept-link')
        ->middleware('throttle:invites');

    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('budget', 'Budget')->name('budget');
    Route::inertia('ledger', 'Ledger')->name('ledger');
    Route::get('envelopes', [EnvelopeController::class, 'index'])->name('envelopes');
    Route::post('envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::put('envelopes/{envelope}', [EnvelopeController::class, 'update'])->name('envelopes.update');
    Route::delete('envelopes/{envelope}', [EnvelopeController::class, 'destroy'])->name('envelopes.destroy');

    Route::prefix('api')->name('api.')->group(function () {
        Route::post('invites/{inviteKey}/accept', [FinancialProfileInviteController::class, 'accept'])
            ->name('invites.accept')
            ->middleware('throttle:invites');
        Route::post('invites/{inviteKey}/decline', [FinancialProfileInviteController::class, 'decline'])
            ->name('invites.decline')
            ->middleware('throttle:invites');

        Route::prefix('profiles')->name('profiles.')->group(function () {
            Route::get('/', [FinancialProfileController::class, 'index'])->name('index');
            Route::post('/', [FinancialProfileController::class, 'store'])->name('store');
            Route::get('{profile}', [FinancialProfileController::class, 'show'])->name('show');
            Route::patch('{profile}', [FinancialProfileController::class, 'update'])->name('update');
            Route::delete('{profile}', [FinancialProfileController::class, 'destroy'])->name('destroy');
            Route::get('{profile}/members', [FinancialProfileController::class, 'members'])->name('members.index');
            Route::delete('{profile}/members/{user}', [FinancialProfileController::class, 'removeMember'])->name('members.destroy')->whereNumber('user');
            Route::post('{profile}/transfer-ownership', [FinancialProfileController::class, 'transferOwnership'])->name('transfer-ownership');
            Route::post('{profile}/set-default', [FinancialProfileController::class, 'setDefault'])->name('set-default');

            Route::post('{profile}/invites', [FinancialProfileInviteController::class, 'store'])->name('invites.store')
                ->middleware('throttle:invite-creation');
            Route::get('{profile}/invites', [FinancialProfileInviteController::class, 'index'])->name('invites.index');
            Route::delete('{profile}/invites/{invite}', [FinancialProfileInviteController::class, 'destroy'])->name('invites.destroy');
        })->whereNumber('profile');

        Route::prefix('budgets')->name('budgets.')->group(function () {
            Route::get('/', [BudgetController::class, 'index'])->name('index');
            Route::post('/', [BudgetController::class, 'store'])->name('store');
            Route::get('{budget}', [BudgetController::class, 'show'])->name('show');
            Route::delete('{budget}', [BudgetController::class, 'destroy'])->name('destroy');
            Route::post('{budget}/publish', [BudgetController::class, 'publish'])->name('publish');
            Route::post('{budget}/clone', [BudgetController::class, 'clone'])->name('clone');
            Route::post('{budget}/envelopes', [BudgetController::class, 'addEnvelope'])->name('envelopes.store');
            Route::patch('{budget}/envelopes/{envelopeId}', [BudgetController::class, 'updateEnvelope'])->name('envelopes.update');
            Route::delete('{budget}/envelopes/{envelopeId}', [BudgetController::class, 'removeEnvelope'])->name('envelopes.destroy');
            Route::get('{budget}/shares', [BudgetController::class, 'permissionsIndex'])->name('shares.index');
            Route::post('{budget}/shares', [BudgetController::class, 'addPermission'])->name('shares.store');
            Route::patch('{budget}/shares/{userId}', [BudgetController::class, 'updatePermission'])->name('shares.update');
            Route::delete('{budget}/shares/{userId}', [BudgetController::class, 'removePermission'])->name('shares.destroy');
            Route::post('{budget}/transfer-ownership', [BudgetController::class, 'transferOwnership'])->name('transfer-ownership');
        })->whereNumber('budget');

        Route::prefix('ledgers')->name('ledgers.')->group(function () {
            Route::get('/', [LedgerController::class, 'apiIndex'])->name('index');
            Route::post('/', [LedgerController::class, 'store'])->name('store');
            Route::get('{ledger}/transactions', [LedgerController::class, 'transactions'])->name('transactions.index');
            Route::post('{ledger}/transactions', [LedgerController::class, 'addTransaction'])->name('transactions.store');
            Route::post('{ledger}/close', [LedgerController::class, 'close'])->name('close');
            Route::post('{ledger}/reopen', [LedgerController::class, 'reopen'])->name('reopen');
            Route::get('{ledger}/shares', [LedgerController::class, 'permissionsIndex'])->name('shares.index');
            Route::post('{ledger}/shares', [LedgerController::class, 'addPermission'])->name('shares.store');
            Route::patch('{ledger}/shares/{userId}', [LedgerController::class, 'updatePermission'])->name('shares.update');
            Route::delete('{ledger}/shares/{userId}', [LedgerController::class, 'removePermission'])->name('shares.destroy');
            Route::post('{ledger}/transfer-ownership', [LedgerController::class, 'transferOwnership'])->name('transfer-ownership');
            Route::delete('{ledger}', [LedgerController::class, 'destroy'])->name('destroy');
        })->whereNumber('ledger');

        Route::prefix('envelopes')->name('envelopes.')->group(function () {
            Route::get('/', [EnvelopeController::class, 'apiIndex'])->name('index');
            Route::get('{envelope}/shares', [EnvelopeController::class, 'permissionsIndex'])->name('shares.index');
            Route::post('{envelope}/shares', [EnvelopeController::class, 'addPermission'])->name('shares.store');
            Route::patch('{envelope}/shares/{userId}', [EnvelopeController::class, 'updatePermission'])->name('shares.update');
            Route::delete('{envelope}/shares/{userId}', [EnvelopeController::class, 'removePermission'])->name('shares.destroy');
            Route::post('{envelope}/transfer-ownership', [EnvelopeController::class, 'transferOwnership'])->name('transfer-ownership');
        })->whereNumber('envelope');

        Route::delete('transactions/{transaction}', [LedgerController::class, 'destroyTransaction'])
            ->name('transactions.destroy')->whereNumber('transaction');

        // Dashboard (issue #29): per-user widget configuration per profile
        // plus the server-computed aggregate data per widget.
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('{profile}/widgets', [DashboardController::class, 'index'])->name('widgets.index');
            Route::put('{profile}/widgets', [DashboardController::class, 'save'])->name('widgets.save');
            Route::delete('{profile}/widgets/{widget}', [DashboardController::class, 'destroy'])->name('widgets.destroy')
                ->whereNumber('widget');
            Route::get('{profile}/widgets/{widget}/data', [DashboardController::class, 'data'])->name('widgets.data')
                ->whereNumber('widget');
        })->whereNumber('profile');
    });
});

require __DIR__.'/settings.php';
