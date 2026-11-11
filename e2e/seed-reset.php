<?php

/**
 * Full reset of the isolated e2e database, in dependency order:
 * transactions → ledgers → budgets → envelope permissions → envelopes →
 * dashboard widgets → invites → profiles (cascades memberships) → users.
 *
 * Required by the seed scripts that start from an empty database, so a new
 * table joins the reset in one place:
 * require __DIR__.'/seed-reset.php';
 */

use App\Models\Budget;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\EnvelopePermission;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;

Transaction::query()->delete();
Ledger::query()->delete();
Budget::query()->delete();
EnvelopePermission::query()->delete();
Envelope::query()->delete();
DashboardWidget::query()->delete();
FinancialProfileInvite::query()->delete();
FinancialProfile::query()->delete();
User::query()->delete();
