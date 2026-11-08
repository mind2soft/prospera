<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The production fail-fast guard for the contact form (issue #17): a
 * production deployment without MAIL_CONTACT_ADDRESS must refuse to boot,
 * while a bare boot with no environment file (e.g. `composer install`
 * running `package:discover` in CI) must not.
 */
class AppServiceProviderTest extends TestCase
{
    public function test_production_boot_fails_fast_without_a_contact_address(): void
    {
        $this->app['env'] = 'production';
        config()->set('mail.contact_address', null);

        try {
            (new AppServiceProvider($this->app))->boot();

            $this->fail('Expected the production boot to fail without MAIL_CONTACT_ADDRESS.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('MAIL_CONTACT_ADDRESS', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_production_boot_succeeds_with_a_contact_address(): void
    {
        $this->app['env'] = 'production';
        config()->set('mail.contact_address', 'team@example.com');

        try {
            (new AppServiceProvider($this->app))->boot();

            $this->addToAssertionCount(1);
        } finally {
            $this->app['env'] = 'testing';
            DB::prohibitDestructiveCommands(false);
        }
    }

    public function test_a_bare_boot_without_an_environment_file_does_not_fail(): void
    {
        $originalPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath('/nonexistent');
        $this->app['env'] = 'production';
        config()->set('mail.contact_address', null);

        try {
            (new AppServiceProvider($this->app))->boot();

            $this->addToAssertionCount(1);
        } finally {
            $this->app->useEnvironmentPath($originalPath);
            $this->app['env'] = 'testing';
            DB::prohibitDestructiveCommands(false);
        }
    }
}
