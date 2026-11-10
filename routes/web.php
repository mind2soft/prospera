<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EnvelopeController;
use App\Http\Controllers\FinancialProfileController;
use App\Http\Controllers\FinancialProfileInviteController;
use App\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

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

Route::middleware(['auth', 'verified'])->group(function () {
    // One-click accept from the invitation email: the signed-in user whose
    // account email matches the invite is accepted straight through; guests
    // are redirected to the sign-in page (the auth middleware preserves the
    // intended URL), unverified users to the verification notice, and every
    // other case falls back to the invite landing page.
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
                ->middleware('throttle:invites');
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
            Route::get('{envelope}/totals', [EnvelopeController::class, 'totals'])->name('totals');
            Route::get('{envelope}/transactions', [EnvelopeController::class, 'apiTransactions'])->name('transactions.index');
            Route::get('{envelope}/shares', [EnvelopeController::class, 'permissionsIndex'])->name('shares.index');
            Route::post('{envelope}/shares', [EnvelopeController::class, 'addPermission'])->name('shares.store');
            Route::patch('{envelope}/shares/{userId}', [EnvelopeController::class, 'updatePermission'])->name('shares.update');
            Route::delete('{envelope}/shares/{userId}', [EnvelopeController::class, 'removePermission'])->name('shares.destroy');
            Route::post('{envelope}/transfer-ownership', [EnvelopeController::class, 'transferOwnership'])->name('transfer-ownership');
        })->whereNumber('envelope');

        Route::delete('transactions/{transaction}', [LedgerController::class, 'destroyTransaction'])
            ->name('transactions.destroy')->whereNumber('transaction');
    });
});

require __DIR__.'/settings.php';
