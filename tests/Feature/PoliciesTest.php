<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\DashboardWidget;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileInvite;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Policies\BudgetPolicy;
use App\Policies\DashboardWidgetPolicy;
use App\Policies\EnvelopePolicy;
use App\Policies\FinancialProfileInvitePolicy;
use App\Policies\FinancialProfilePolicy;
use App\Policies\LedgerPolicy;
use App\Policies\TransactionPolicy;
use App\Services\ResourcePermissions;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PoliciesTest extends TestCase
{
    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function policies(): array
    {
        return [
            'profile' => [FinancialProfile::class, FinancialProfilePolicy::class],
            'budget' => [Budget::class, BudgetPolicy::class],
            'ledger' => [Ledger::class, LedgerPolicy::class],
            'transaction' => [Transaction::class, TransactionPolicy::class],
            'envelope' => [Envelope::class, EnvelopePolicy::class],
            'dashboard widget' => [DashboardWidget::class, DashboardWidgetPolicy::class],
            'invite' => [FinancialProfileInvite::class, FinancialProfileInvitePolicy::class],
        ];
    }

    /**
     * @param  class-string  $model
     * @param  class-string  $policy
     */
    #[DataProvider('policies')]
    public function test_each_model_resolves_its_policy(string $model, string $policy): void
    {
        $this->assertInstanceOf($policy, Gate::getPolicyFor($model));
    }

    public function test_permissions_map_to_not_found_forbidden_or_allowed(): void
    {
        $none = ResourcePermissions::inspect(ResourcePermissions::NONE, ResourcePermissions::VIEWER);
        $this->assertTrue($none->denied());
        $this->assertSame(404, $none->status());

        $tooLow = ResourcePermissions::inspect(ResourcePermissions::VIEWER, ResourcePermissions::OWNER);
        $this->assertTrue($tooLow->denied());
        $this->assertSame(403, $tooLow->status());

        $this->assertTrue(ResourcePermissions::inspect(ResourcePermissions::CONTRIBUTOR, ResourcePermissions::CONTRIBUTOR)->allowed());
        $this->assertTrue(ResourcePermissions::inspect(ResourcePermissions::OWNER, ResourcePermissions::VIEWER)->allowed());
    }
}
