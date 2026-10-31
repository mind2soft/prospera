<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\EnveloppeController;
use App\Http\Controllers\FinancialProfileController;
use App\Http\Controllers\FinancialProfileInviteController;
use App\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

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
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('budget', 'Budget')->name('budget');
    Route::inertia('ledger', 'Ledger')->name('ledger');
    Route::get('enveloppes', [EnveloppeController::class, 'index'])->name('enveloppes');
    Route::post('enveloppes', [EnveloppeController::class, 'store'])->name('enveloppes.store');
    Route::put('enveloppes/{enveloppe}', [EnveloppeController::class, 'update'])->name('enveloppes.update');
    Route::delete('enveloppes/{enveloppe}', [EnveloppeController::class, 'destroy'])->name('enveloppes.destroy');

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

            Route::post('{profile}/invites', [FinancialProfileInviteController::class, 'store'])->name('invites.store');
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
            Route::post('{budget}/enveloppes', [BudgetController::class, 'addEnveloppe'])->name('enveloppes.store');
            Route::patch('{budget}/enveloppes/{enveloppeId}', [BudgetController::class, 'updateEnveloppe'])->name('enveloppes.update');
            Route::delete('{budget}/enveloppes/{enveloppeId}', [BudgetController::class, 'removeEnveloppe'])->name('enveloppes.destroy');
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

        Route::prefix('enveloppes')->name('enveloppes.')->group(function () {
            Route::get('/', [EnveloppeController::class, 'apiIndex'])->name('index');
            Route::get('{enveloppe}/totals', [EnveloppeController::class, 'totals'])->name('totals');
            Route::get('{enveloppe}/transactions', [EnveloppeController::class, 'apiTransactions'])->name('transactions.index');
            Route::get('{enveloppe}/shares', [EnveloppeController::class, 'permissionsIndex'])->name('shares.index');
            Route::post('{enveloppe}/shares', [EnveloppeController::class, 'addPermission'])->name('shares.store');
            Route::patch('{enveloppe}/shares/{userId}', [EnveloppeController::class, 'updatePermission'])->name('shares.update');
            Route::delete('{enveloppe}/shares/{userId}', [EnveloppeController::class, 'removePermission'])->name('shares.destroy');
            Route::post('{enveloppe}/transfer-ownership', [EnveloppeController::class, 'transferOwnership'])->name('transfer-ownership');
        })->whereNumber('enveloppe');

        Route::delete('transactions/{transaction}', [LedgerController::class, 'destroyTransaction'])
            ->name('transactions.destroy')->whereNumber('transaction');
    });
});

require __DIR__.'/settings.php';
