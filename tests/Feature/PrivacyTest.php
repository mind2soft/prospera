<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_page_is_public()
    {
        $response = $this->get(route('privacy'));
        $response->assertOk();

        // The copy is loaded and rendered client-side; the route only
        // provides the Inertia page shell (issue #18).
        $response->assertInertia(fn ($page) => $page->component('Privacy'));
    }

    public function test_signed_in_users_can_visit_the_privacy_page()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('privacy'))->assertOk();
    }

    /**
     * The privacy policies promise a 7-year retention prune (issue #61,
     * docs/retention.md): the wording is a product commitment, so both
     * localized policies must keep stating the same implemented rules — a
     * policy silently diverging from `retention:prune` is a defect.
     */
    public function test_both_localized_policies_state_the_implemented_retention_rules()
    {
        $english = (string) file_get_contents(resource_path('content/privacy.en.md'));
        $french = (string) file_get_contents(resource_path('content/privacy.fr.md'));

        // Ledgers: 7 years after their end date, budgets and transactions tied to it.
        $this->assertStringContainsStringIgnoringCase(
            '7 years after the ledger\'s end date', $english,
        );
        $this->assertStringContainsStringIgnoringCase(
            '7 ans après sa date de fin', $french,
        );

        // Published budgets and drafts.
        $this->assertStringContainsStringIgnoringCase(
            '7 years past its effective month', $english,
        );
        $this->assertStringContainsStringIgnoringCase(
            '7 ans après son mois effectif', $french,
        );

        // Envelopes and scheduled transactions are not bound to a period.
        $this->assertStringContainsStringIgnoringCase(
            'scheduled transactions are not bound to a period', $english,
        );
        $this->assertStringContainsStringIgnoringCase(
            'transactions planifiées ne sont pas liées à une période', $french,
        );
    }
}
