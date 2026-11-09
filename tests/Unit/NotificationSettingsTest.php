<?php

namespace Tests\Unit;

use App\Settings\NotificationSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single notification gate contract (issue #33): every notification
 * sender routes through `User::wantsNotification()`, which delegates here.
 * The master switch short-circuits everything; unknown and missing keys
 * default to enabled, so adding toggles never breaks stored rows.
 */
final class NotificationSettingsTest extends TestCase
{
    public function test_defaults_are_fully_enabled(): void
    {
        $settings = NotificationSettings::default();

        $this->assertTrue($settings->toArray() === [
            'master' => true,
            'payment_reminders' => true,
            'ledger_expired' => true,
            'contributor_transactions' => true,
            'envelope_limit_exceeded' => true,
            'profile_invite' => true,
        ]);
    }

    /**
     * `null` (the column default) and partial stored objects hydrate with
     * the defaults — unknown keys are ignored, missing keys are enabled.
     */
    public function test_partial_and_null_storage_hydrate_with_defaults(): void
    {
        $null = NotificationSettings::fromStored(null);
        $this->assertTrue($null->allows(NotificationSettings::PAYMENT_REMINDERS));

        $partial = NotificationSettings::fromStored([
            'master' => true,
            'payment_reminders' => false,
            'unknown_key' => false,
        ]);

        $this->assertFalse($partial->allows(NotificationSettings::PAYMENT_REMINDERS));
        $this->assertTrue($partial->allows(NotificationSettings::LEDGER_EXPIRED));
        $this->assertFalse($partial->allows('unknown_key'));
    }

    public function test_master_switch_short_circuits_every_notification(): void
    {
        $settings = NotificationSettings::default()->with(['master' => false]);

        foreach (NotificationSettings::TOGGLES as $key) {
            $this->assertFalse($settings->allows($key), "master off must disable {$key}");
        }

        // The toggles are still stored as enabled — flipping the master
        // back on restores them.
        $this->assertTrue($settings->toArray()['payment_reminders']);
        $this->assertTrue($settings->with(['master' => true])->allows(NotificationSettings::PAYMENT_REMINDERS));
    }

    public static function toggleCombinations(): iterable
    {
        yield 'master off, type on' => [
            ['master' => false, 'payment_reminders' => true],
            NotificationSettings::PAYMENT_REMINDERS,
            false,
        ];

        yield 'master on, type off' => [
            ['master' => true, 'ledger_expired' => false],
            NotificationSettings::LEDGER_EXPIRED,
            false,
        ];

        yield 'master on, type on' => [
            ['master' => true, 'contributor_transactions' => true],
            NotificationSettings::CONTRIBUTOR_TRANSACTIONS,
            true,
        ];

        yield 'unknown type' => [
            [],
            'something_else',
            false,
        ];
    }

    #[DataProvider('toggleCombinations')]
    public function test_allows_requires_master_and_type(array $stored, string $key, bool $expected): void
    {
        $this->assertSame($expected, NotificationSettings::fromStored($stored)->allows($key));
    }

    public function test_the_master_switch_itself_only_requires_the_master(): void
    {
        $settings = NotificationSettings::default()->with(['ledger_expired' => false]);

        $this->assertTrue($settings->allows(NotificationSettings::MASTER));
    }

    public function test_to_array_round_trips_through_from_stored(): void
    {
        $settings = NotificationSettings::default()->with([
            'master' => false,
            'envelope_limit_exceeded' => false,
        ]);

        $this->assertSame(
            $settings->toArray(),
            NotificationSettings::fromStored($settings->toArray())->toArray(),
        );
    }
}
