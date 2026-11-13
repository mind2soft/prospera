<?php

namespace Tests;

use App\Security\Amounts\AmountCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Assert the single row matching $where stores $column as an encrypted
     * amount (never plaintext) that decrypts to $expected.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $where
     */
    protected function assertEncryptedAmount(string $model, array $where, string $column, int|float $expected): void
    {
        $row = $model::query()->where($where)->sole();

        $this->assertTrue(
            AmountCipher::looksEncrypted($row->getRawOriginal($column)),
            "{$row->getTable()}.{$column} is not stored encrypted.",
        );
        $this->assertSame($expected, $row->getAttribute($column));
    }
}
