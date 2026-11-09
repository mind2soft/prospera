<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Boot-time smoke check for the amount keys (issue #36, docs/encryption.md):
 * a rotated APP_KEY must produce an explicit, actionable error at boot in
 * production instead of scattered AmountDecryptionException 500s, while
 * artisan keeps booting so the documented recovery stays runnable.
 */
class AmountKeyBootCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironmentPath = $this->app->environmentPath();

        // The provider's contact fail-fast must not shadow the checks here.
        config()->set('mail.contact_address', 'team@example.com');
    }

    protected function tearDown(): void
    {
        $this->app->useEnvironmentPath($this->originalEnvironmentPath);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_a_rotated_app_key_fails_the_check_with_an_actionable_error(): void
    {
        FinancialProfile::createOwnedBy(User::factory()->create(), 'Family');
        $this->rotateAppKey();
        $this->app['env'] = 'production';

        try {
            $this->runBootCheck(asHttp: true);

            $this->fail('Expected the boot check to fail fast on a rotated APP_KEY.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_KEY', $exception->getMessage());
            $this->assertStringContainsString('APP_PREVIOUS_KEYS', $exception->getMessage());
            $this->assertStringContainsString('amounts:rewrap-keys', $exception->getMessage());
        }
    }

    public function test_the_check_survives_readable_amount_keys(): void
    {
        FinancialProfile::createOwnedBy(User::factory()->create(), 'Family');
        $this->app['env'] = 'production';

        $this->runBootCheck(asHttp: true);

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_database_leaves_nothing_to_verify(): void
    {
        $this->app['env'] = 'production';

        $this->runBootCheck(asHttp: true);

        $this->addToAssertionCount(1);
    }

    public function test_only_production_environments_are_checked(): void
    {
        FinancialProfile::createOwnedBy(User::factory()->create(), 'Family');
        $this->rotateAppKey();
        $this->app['env'] = 'local';

        $this->runBootCheck(asHttp: true);

        $this->addToAssertionCount(1);
    }

    public function test_a_bare_boot_without_an_environment_file_is_not_checked(): void
    {
        FinancialProfile::createOwnedBy(User::factory()->create(), 'Family');
        $this->rotateAppKey();
        $this->app['env'] = 'production';
        $this->app->useEnvironmentPath('/nonexistent');

        $this->runBootCheck(asHttp: true);

        $this->addToAssertionCount(1);
    }

    public function test_console_boots_are_not_checked_so_recovery_stays_possible(): void
    {
        FinancialProfile::createOwnedBy(User::factory()->create(), 'Family');
        $this->rotateAppKey();
        $this->app['env'] = 'production';

        // Artisan and the queue workers run in the console: the check must
        // not fire there, or `amounts:rewrap-keys` could never run. The
        // scheduled `amounts:verify` reports the failure in the logs instead.
        $this->runBootCheck(asHttp: false);

        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'Amount verification failed')
                && isset($context['failures']),
        );

        $this->artisan('amounts:verify')->assertFailed();
    }

    /**
     * Simulate a bare `key:generate`: the current key is replaced with a
     * fresh one and no previous key is kept.
     */
    private function rotateAppKey(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        Crypt::clearResolvedInstance('encrypter');
        $this->app->forgetInstance('encrypter');
    }

    /**
     * Run the provider's amount-key check without a full re-boot (the
     * provider already booted for the test). Tests run in the console, where
     * the real `runningInConsole()` would skip the check: the seam lets a
     * test pretend to serve an HTTP request while the other conditions
     * (production, environment file) stay real.
     */
    private function runBootCheck(bool $asHttp): void
    {
        $provider = new class($this->app, $asHttp) extends AppServiceProvider
        {
            public function __construct($app, private readonly bool $asHttp)
            {
                parent::__construct($app);
            }

            protected function runsInConsole(): bool
            {
                return ! $this->asHttp;
            }

            public function run(): void
            {
                $this->assertAmountKeysAreReadable();
            }
        };

        $provider->run();
    }
}
