<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ResourcePermissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a typical Canadian household for test@domain.com: dual income
 * (~$5,680 net/month, low middle class), one teenager, a $250k mortgage at
 * $1,400/month, monthly published CAD budgets for 2026 and a single ledger
 * spanning the whole year with realistic transactions.
 *
 * Envelope names are in Canadian French; amounts are whole CAD dollars.
 * Assumes the user and their financial profile already exist. Existing data
 * for that user's envelopes and the profile's budgets/ledgers is wiped first.
 */
class TestHouseholdSeeder extends Seeder
{
    use WithoutModelEvents;

    private const EMAIL = 'test@domain.com';

    /**
     * Envelope plan. amount = monthly CAD budget; type = budget allocation
     * type; day = typical transaction day for fixed/revenue envelopes;
     * split = transactions per month for variable envelopes.
     *
     * @var array<int, array{name: string, direction: string, type: string, amount: int, color: string, day?: int, split?: int}>
     */
    private array $envelopes = [
        // --- Revenues -------------------------------------------------------
        ['name' => 'Salaire – Parent 1', 'direction' => Envelope::DIRECTION_REVENUE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 3400, 'color' => '#10b981', 'day' => 1],
        ['name' => 'Salaire – Parent 2', 'direction' => Envelope::DIRECTION_REVENUE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 2100, 'color' => '#34d399', 'day' => 15],
        ['name' => 'Allocation canadienne pour enfants', 'direction' => Envelope::DIRECTION_REVENUE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 180, 'color' => '#6ee7b7', 'day' => 20],

        // --- Housing --------------------------------------------------------
        ['name' => 'Hypothèque', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 1400, 'color' => '#ef4444', 'day' => 1],
        ['name' => 'Taxe municipale et scolaire', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 200, 'color' => '#f97316', 'day' => 5],
        ['name' => 'Électricité', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 110, 'color' => '#f59e0b', 'split' => 1],
        ['name' => 'Chauffage (gaz naturel)', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 70, 'color' => '#fb923c', 'split' => 1],
        ['name' => 'Internet et téléphonie', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 140, 'color' => '#8b5cf6', 'day' => 10],
        ['name' => 'Assurance auto et habitation', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 165, 'color' => '#6366f1', 'day' => 18],
        ['name' => 'Entretien de la maison', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 80, 'color' => '#78716c', 'split' => 1],

        // --- Food -----------------------------------------------------------
        ['name' => 'Épicerie', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 1000, 'color' => '#22c55e', 'split' => 4],
        ['name' => 'Restaurants et sorties', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 250, 'color' => '#ec4899', 'split' => 2],

        // --- Transportation --------------------------------------------------
        ['name' => 'Essence', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 220, 'color' => '#eab308', 'split' => 2],
        ['name' => 'Entretien de l\'auto', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 90, 'color' => '#a3e635', 'split' => 1],

        // --- Family and personal ---------------------------------------------
        ['name' => 'Vêtements', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 120, 'color' => '#f472b6', 'split' => 1],
        ['name' => 'Soins personnels et pharmacie', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 90, 'color' => '#14b8a6', 'split' => 2],
        ['name' => 'Activités de l\'adolescent', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 130, 'color' => '#3b82f6', 'split' => 1],
        ['name' => 'Abonnements numériques', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 55, 'color' => '#a78bfa', 'day' => 12],
        ['name' => 'Cadeaux et fêtes', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_VARIABLE, 'amount' => 80, 'color' => '#e879f9', 'split' => 1],

        // --- Savings ---------------------------------------------------------
        ['name' => 'Fond d\'urgence', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 250, 'color' => '#0ea5e9', 'day' => 2],
        ['name' => 'Fonds vacances', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 150, 'color' => '#06b6d4', 'day' => 2],
        ['name' => 'REEE – Études de l\'ado', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 100, 'color' => '#1d4ed8', 'day' => 2],
        ['name' => 'REER et CELI', 'direction' => Envelope::DIRECTION_EXPENSE, 'type' => BudgetEnvelope::TYPE_FIXED, 'amount' => 150, 'color' => '#059669', 'day' => 2],
    ];

    /**
     * Seasonal multipliers (1.0 = budgeted amount) for weather-driven bills.
     *
     * @var array<string, array<int, float>>
     */
    private array $seasonality = [
        'Chauffage (gaz naturel)' => [1 => 1.6, 1.6, 1.3, 0.8, 0.5, 0.3, 0.3, 0.3, 0.5, 0.8, 1.2, 1.5],
        'Électricité' => [1 => 0.9, 0.9, 0.9, 1.0, 1.0, 1.1, 1.3, 1.3, 1.0, 0.9, 0.9, 0.9],
    ];

    /**
     * December is gift-heavy (Noël): total spend for the year is multiplied
     * beyond the sum of monthly budgets, so December absorbs the excess.
     */
    private const CHRISTMAS_MONTH = 12;

    public function run(): void
    {
        $user = User::query()->where('email', self::EMAIL)->firstOrFail();

        $profile = $user->profiles()->wherePivot('is_owner', true)->first()
            ?? $user->profiles()->first();
        if ($profile === null) {
            throw new \RuntimeException('No financial profile found for '.self::EMAIL.'.');
        }

        // Deterministic pseudo-randomness: re-running the seeder reproduces
        // the exact same transactions.
        mt_srand(20260101);

        DB::transaction(function () use ($user, $profile): void {
            // Transactions first (restrict FKs from envelopes/budgets).
            Transaction::query()
                ->whereHas('ledger', fn ($query) => $query->where('profile_id', $profile->id))
                ->delete();

            Ledger::query()->where('profile_id', $profile->id)->delete();
            Budget::query()->where('profile_id', $profile->id)->delete();
            Envelope::query()->where('user_id', $user->id)->delete();
        });

        DB::transaction(function () use ($user, $profile): void {
            $envelopes = $this->seedEnvelopes($user);
            $this->seedBudgets($profile, $user, $envelopes);
            $this->seedLedger($profile, $user, $envelopes);
        });
    }

    /**
     * The user-owned envelopes, keyed by name.
     *
     * @return array<string, Envelope>
     */
    private function seedEnvelopes(User $user): array
    {
        $byName = [];

        foreach ($this->envelopes as $plan) {
            $byName[$plan['name']] = Envelope::create([
                'user_id' => $user->id,
                'name' => $plan['name'],
                'type' => $plan['direction'],
                'color' => $plan['color'],
            ]);
        }

        return $byName;
    }

    /**
     * One published budget per month of 2026, all with the same envelope
     * allocations (the app matches transactions to the month's published
     * budget).
     *
     * @param  array<string, Envelope>  $envelopes
     */
    private function seedBudgets(FinancialProfile $profile, User $user, array $envelopes): void
    {
        foreach (range(1, 12) as $month) {
            $budget = Budget::create([
                'profile_id' => $profile->id,
                'currency' => 'CAD',
                'effective_year' => 2026,
                'effective_month' => $month,
                'published_at' => Carbon::create(2026, $month, 1, 0, 0, 0),
            ]);

            $budget->permissions()->create([
                'user_id' => $user->id,
                'permission' => ResourcePermissions::OWNER,
            ]);

            foreach ($this->envelopes as $plan) {
                BudgetEnvelope::create([
                    'budget_id' => $budget->id,
                    'envelope_id' => $envelopes[$plan['name']]->id,
                    'amount' => $envelopes[$plan['name']]->signedAmount($plan['amount']),
                    'type' => $plan['type'],
                ]);
            }
        }
    }

    /**
     * The 2026 ledger with a small cash cushion as starting balance.
     *
     * @param  array<string, Envelope>  $envelopes
     */
    private function seedLedger(FinancialProfile $profile, User $user, array $envelopes): void
    {
        $ledger = Ledger::create([
            'profile_id' => $profile->id,
            'name' => 'Finances familiales 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'starting_balance' => 2500, // cash cushion in the joint account
        ]);

        $ledger->permissions()->create([
            'user_id' => $user->id,
            'permission' => ResourcePermissions::OWNER,
        ]);

        foreach (range(1, 12) as $month) {
            $this->seedMonthTransactions($ledger, $user, $envelopes, $month);
        }
    }

    /**
     * Transactions for one month: revenues and fixed expenses as single
     * entries on their usual day, variable expenses split into a few jittered
     * entries. Fixed savings transfers land right after payday.
     *
     * @param  array<string, Envelope>  $envelopes
     */
    private function seedMonthTransactions(Ledger $ledger, User $user, array $envelopes, int $month): void
    {
        $daysInMonth = Carbon::create(2026, $month, 1)->daysInMonth;

        foreach ($this->envelopes as $plan) {
            $envelope = $envelopes[$plan['name']];

            if ($plan['direction'] === Envelope::DIRECTION_REVENUE) {
                $this->record($ledger, $user, $envelope, $plan['amount'], $month, min($plan['day'] ?? 1, $daysInMonth));

                continue;
            }

            $seasonal = $this->seasonality[$plan['name']] ?? null;

            if (isset($plan['day'])) {
                $amount = $plan['amount'];
                if ($seasonal !== null) {
                    $amount = (int) round($amount * $seasonal[$month]);
                }

                $this->record($ledger, $user, $envelope, $amount, $month, min($plan['day'], $daysInMonth));

                continue;
            }

            // Variable envelopes: split the monthly amount into a few dated
            // purchases, ±20% per transaction. December gifting spends the
            // whole year's worth of the envelope plus the year's leftovers.
            $total = $plan['amount'];
            if ($plan['name'] === 'Cadeaux et fêtes') {
                $total = $month === self::CHRISTMAS_MONTH
                    ? $plan['amount'] * 4 // Noël: gifts for everyone
                    : (int) round($plan['amount'] * 0.75);
            }

            foreach ($this->splitAmount($total, $plan['split'] ?? 1, $daysInMonth) as $day => $amount) {
                $amount = (int) round($amount * ($seasonal !== null ? $seasonal[$month] : 1));

                $this->record($ledger, $user, $envelope, max($amount, 1), $month, min($day, $daysInMonth));
            }
        }
    }

    /**
     * Split a monthly amount into $count jittered purchases on spread-out
     * days.
     *
     * @return array<int, int> day-of-month => amount
     */
    private function splitAmount(int $total, int $count, int $daysInMonth): array
    {
        $count = max($count, 1);
        $base = intdiv($total, $count);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $jitter = $count === 1 ? 0 : mt_rand(-20, 20) / 100;
            $amount = max(10, (int) round($base * (1 + $jitter)));
            $day = 2 + (int) floor($i * ($daysInMonth - 4) / $count) + mt_rand(0, 3);

            $result[$day] = ($result[$day] ?? 0) + $amount;
        }

        return $result;
    }

    private function record(Ledger $ledger, User $user, Envelope $envelope, int $amount, int $month, int $day): void
    {
        Transaction::create([
            'ledger_id' => $ledger->id,
            'user_id' => $user->id,
            'envelope_id' => $envelope->id,
            'amount' => $envelope->signedAmount($amount),
            'event_at' => Carbon::create(2026, $month, $day, 0, 0, 0),
        ]);
    }
}
