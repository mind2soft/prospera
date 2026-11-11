<?php

/**
 * Playwright seed for the dashboard XSS regression (UX-1): on top of
 * seed-dashboard.php, adds an envelope and a group whose names carry HTML
 * markup, and a radial widget charting both. Hovering a pie segment renders
 * the name inside the Unovis tooltip (innerHTML) — the markup must show as
 * text and never execute.
 *
 * Run order: after seed-dashboard.php. Run against the isolated e2e
 * database (.env.e2e — see e2e/serve.sh):
 *
 * npm run e2e:seed -- seed-dashboard-xss.php
 */

use App\Models\DashboardWidget;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

$u = User::query()->where('email', 'playwright@example.com')->firstOrFail();

$profile = FinancialProfile::query()
    ->whereHas('members', fn ($q) => $q->whereKey($u->id)->where('financial_profile_user.is_default', true))
    ->firstOrFail();

$ledger = Ledger::query()->where('profile_id', $profile->id)->firstOrFail();

$evil = $u->envelopes()->create([
    'name' => '<img src=x onerror="window.__xss=1">',
    'type' => 'expense',
    'color' => '#3b82f6',
]);

Transaction::create([
    'ledger_id' => $ledger->id,
    'user_id' => $u->id,
    'envelope_id' => $evil->id,
    'amount' => -300,
    'event_at' => Carbon::today()->startOfDay(),
]);

$food = $u->envelopes()->where('name', 'Food')->firstOrFail();

DashboardWidget::query()
    ->where('user_id', $u->id)
    ->where('financial_profile_id', $profile->id)
    ->delete();

DashboardWidget::create([
    'user_id' => $u->id,
    'financial_profile_id' => $profile->id,
    'type' => DashboardWidget::TYPE_RADIAL,
    'title' => null,
    'view_type' => 'pie',
    'column_width' => 5,
    'period_type' => DashboardWidget::PERIOD_MONTHS,
    'period_range' => 1,
    'selection' => [
        ['kind' => 'envelope', 'envelope_id' => $evil->id],
        ['kind' => 'group', 'name' => '<img src=y onerror="window.__xss=2">', 'envelope_ids' => [$food->id]],
    ],
    'sort_order' => 0,
]);

echo "dashboard xss seed ok: envelope {$evil->id}\n";
