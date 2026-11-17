<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\Amounts\AmountCipher;
use App\Security\Amounts\AmountContext;
use App\Security\Amounts\AmountDecryptionException;
use App\Security\Amounts\AmountKeyScope;
use App\Security\Amounts\DataKey;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class AmountCipherTest extends TestCase
{
    private AmountCipher $cipher;

    private DataKey $key;

    private AmountContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cipher = new AmountCipher;
        $this->key = DataKey::generate(7, 3);
        $this->context = new AmountContext('transactions', 'amount', 7, ['l' => 11, 'e' => 13, 'd' => '2026-10-05']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'minus one' => [-1],
            'max validated' => [999999999],
            'min validated' => [-999999999],
            'int max' => [PHP_INT_MAX],
            'int min' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('amounts')]
    public function test_round_trips_signed_64_bit_amounts(int $amount): void
    {
        $payload = $this->cipher->encrypt($amount, $this->key, $this->context);

        $this->assertSame($amount, $this->cipher->decrypt($payload, $this->key, $this->context));
    }

    public function test_encryption_is_randomized(): void
    {
        $this->assertNotSame(
            $this->cipher->encrypt(42, $this->key, $this->context),
            $this->cipher->encrypt(42, $this->key, $this->context),
        );
    }

    public function test_payload_length_does_not_depend_on_value_or_sign(): void
    {
        $lengths = array_map(
            fn (array $amount): int => strlen($this->cipher->encrypt($amount[0], $this->key, $this->context)),
            self::amounts(),
        );

        $this->assertCount(1, array_unique($lengths));
    }

    public function test_payload_format_carries_the_key_version(): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);

        $this->assertMatchesRegularExpression('/^pa1\.3\.[A-Za-z0-9_-]{48}$/', $payload);
        $this->assertSame(3, AmountCipher::keyVersionOf($payload));
        $this->assertTrue(AmountCipher::looksEncrypted($payload));
    }

    public function test_any_tampered_byte_is_rejected(): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);
        $binary = base64_decode(strtr(substr($payload, 6), '-_', '+/'), true);
        $this->assertIsString($binary);

        for ($i = 0; $i < strlen($binary); $i++) {
            $tampered = $binary;
            $tampered[$i] = chr(ord($tampered[$i]) ^ 0x01);

            $forged = 'pa1.3.'.rtrim(strtr(base64_encode($tampered), '+/', '-_'), '=');

            try {
                $this->cipher->decrypt($forged, $this->key, $this->context);
                $this->fail("Tampering byte {$i} was not detected.");
            } catch (AmountDecryptionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return array<string, array{AmountContext}>
     */
    public static function otherContexts(): array
    {
        return [
            'table' => [new AmountContext('budget_envelopes', 'amount', 7, ['l' => 11, 'e' => 13, 'd' => '2026-10-05'])],
            'column' => [new AmountContext('transactions', 'starting_balance', 7, ['l' => 11, 'e' => 13, 'd' => '2026-10-05'])],
            'ledger' => [new AmountContext('transactions', 'amount', 7, ['l' => 12, 'e' => 13, 'd' => '2026-10-05'])],
            'envelope' => [new AmountContext('transactions', 'amount', 7, ['l' => 11, 'e' => 14, 'd' => '2026-10-05'])],
            'date' => [new AmountContext('transactions', 'amount', 7, ['l' => 11, 'e' => 13, 'd' => '2026-10-06'])],
            'missing binding' => [new AmountContext('transactions', 'amount', 7, ['l' => 11, 'e' => 13])],
            'binding order' => [new AmountContext('transactions', 'amount', 7, ['e' => 13, 'l' => 11, 'd' => '2026-10-05'])],
        ];
    }

    #[DataProvider('otherContexts')]
    public function test_a_different_context_is_rejected(AmountContext $other): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);

        $this->expectException(AmountDecryptionException::class);

        $this->cipher->decrypt($payload, $this->key, $other);
    }

    public function test_a_different_profile_is_rejected(): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);
        $otherKey = DataKey::generate(8, 3);
        $otherContext = new AmountContext('transactions', 'amount', 8, ['l' => 11, 'e' => 13, 'd' => '2026-10-05']);

        $this->expectException(AmountDecryptionException::class);

        $this->cipher->decrypt($payload, $otherKey, $otherContext);
    }

    public function test_a_forged_key_version_is_rejected(): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);
        $relabeled = 'pa1.4.'.substr($payload, 6);

        try {
            $this->cipher->decrypt($relabeled, new DataKey(7, 4, $this->key->bytes()), $this->context);
            $this->fail('A payload relabeled with another key version decrypted.');
        } catch (AmountDecryptionException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(AmountDecryptionException::class);
        $this->cipher->decrypt($relabeled, $this->key, $this->context);
    }

    public function test_a_wrong_key_is_rejected(): void
    {
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);

        $this->expectException(AmountDecryptionException::class);

        $this->cipher->decrypt($payload, DataKey::generate(7, 3), $this->context);
    }

    public function test_a_key_of_another_profile_cannot_be_used(): void
    {
        $this->expectException(LogicException::class);

        $this->cipher->encrypt(42, DataKey::generate(8, 3), $this->context);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'plaintext number' => ['123'],
            'negative plaintext' => ['-123'],
            'empty' => [''],
            'unknown format' => ['pa2.3.'.str_repeat('A', 48)],
            'version zero' => ['pa1.0.'.str_repeat('A', 48)],
            'leading zero version' => ['pa1.03.'.str_repeat('A', 48)],
            'too short' => ['pa1.3.'.str_repeat('A', 47)],
            'too long' => ['pa1.3.'.str_repeat('A', 49)],
            'padding' => ['pa1.3.'.str_repeat('A', 46).'=='],
            'standard base64 alphabet' => ['pa1.3.'.str_repeat('A', 47).'+'],
            'trailing newline' => ['pa1.3.'.str_repeat('A', 48)."\n"],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payloads_are_rejected(string $payload): void
    {
        $this->assertFalse(AmountCipher::looksEncrypted($payload));

        $this->expectException(AmountDecryptionException::class);

        $this->cipher->decrypt($payload, $this->key, $this->context);
    }

    public function test_the_aad_is_canonical(): void
    {
        $this->assertSame(
            'prospera/amount/pa1|v=3|t=transactions.amount|p=7|l=11|e=13|d=2026-10-05',
            $this->context->aad(3),
        );
    }

    #[DataProvider('amounts')]
    public function test_envelope_scoped_amounts_round_trip_signed_64_bit_values(int $amount): void
    {
        $key = DataKey::generate(7, 3, AmountKeyScope::Envelope);
        $context = new AmountContext('envelope_schedules', 'amount', 7, ['d' => '2026-01-01'], AmountKeyScope::Envelope);
        $payload = $this->cipher->encrypt($amount, $key, $context);

        $this->assertSame($amount, $this->cipher->decrypt($payload, $key, $context));
        $this->assertSame('prospera/amount/pa1|v=3|t=envelope_schedules.amount|e=7|d=2026-01-01', $context->aad(3));
    }

    public function test_key_scope_must_match_even_when_owner_ids_and_key_bytes_match(): void
    {
        $envelopeKey = new DataKey(7, 3, $this->key->bytes(), AmountKeyScope::Envelope);
        $this->expectException(LogicException::class);
        $this->cipher->encrypt(42, $envelopeKey, $this->context);
    }

    public function test_scope_is_authenticated_even_when_table_owner_id_and_key_bytes_match(): void
    {
        $envelopeKey = new DataKey(7, 3, $this->key->bytes(), AmountKeyScope::Envelope);
        $context = new AmountContext('transactions', 'amount', 7, $this->context->bindings, AmountKeyScope::Envelope);
        $payload = $this->cipher->encrypt(42, $this->key, $this->context);
        $this->expectException(AmountDecryptionException::class);
        $this->cipher->decrypt($payload, $envelopeKey, $context);
    }

    public function test_legacy_profile_ciphertext_still_decrypts_without_a_data_migration(): void
    {
        // Construct a pre-envelope-scope fixture without the production AAD
        // builder, proving the existing cryptographic contract is unchanged.
        $bytes = str_repeat('K', 32);
        $iv = str_repeat('I', 12);
        $tag = '';
        $ciphertext = openssl_encrypt(pack('J', -12345), 'aes-256-gcm', $bytes, OPENSSL_RAW_DATA, $iv, $tag,
            'prospera/amount/pa1|v=3|t=transactions.amount|p=7|l=11|e=13|d=2026-10-05', 16);
        $this->assertIsString($ciphertext);
        $legacy = 'pa1.3.'.rtrim(strtr(base64_encode($iv.$ciphertext.$tag), '+/', '-_'), '=');
        $this->assertSame(-12345, $this->cipher->decrypt($legacy, new DataKey(7, 3, $bytes), $this->context));
    }

    /**
     * @return array<string, array{array<string, int|string>}>
     */
    public static function invalidBindings(): array
    {
        return [
            'separator injection' => [['d' => '2026-10-05|p=8']],
            'trailing newline' => [['d' => "2026-10-05\n"]],
            'non-positive id' => [['l' => 0]],
            'long key' => [['ledger' => 1]],
        ];
    }

    /**
     * @param  array<string, int|string>  $bindings
     */
    #[DataProvider('invalidBindings')]
    public function test_context_bindings_are_canonical(array $bindings): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AmountContext('transactions', 'amount', 7, $bindings);
    }

    public function test_context_dates_are_normalized_strictly(): void
    {
        $this->assertSame('2026-10-05', AmountContext::date('2026-10-05 00:00:00'));
        $this->assertSame('2026-10-05', AmountContext::date('2026-10-05'));

        $this->expectException(InvalidArgumentException::class);
        AmountContext::date("2026-10-05\n");
    }

    public function test_data_keys_never_leak_their_bytes(): void
    {
        $bytes = str_repeat("\xAB", DataKey::LENGTH);
        $key = new DataKey(7, 3, $bytes);

        $this->assertStringNotContainsString($bytes, print_r($key, true));
        $this->assertStringNotContainsString($bytes, print_r([$key], true));
        $this->assertStringNotContainsString($bytes, var_export($key, true));
        $this->assertStringNotContainsString($bytes, print_r((array) $key, true));
        $this->assertStringNotContainsString($bytes, var_export((array) $key, true));

        // Symfony VarDumper (dump(), dd(), Tinker).
        $dumped = (new CliDumper)->dump((new VarCloner)->cloneVar($key), true);
        $this->assertIsString($dumped);
        $this->assertStringNotContainsString($bytes, $dumped);
        $this->assertStringNotContainsString(bin2hex($bytes), $dumped);
        $this->assertSame('{"ownerId":7,"version":3,"scope":"profile"}', json_encode($key));
        $this->assertSame($bytes, $key->bytes());

        ob_start();
        var_dump($key);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString($bytes, $dump);

        try {
            clone $key;
            $this->fail('An amount key was cloned.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        serialize($key);
    }

    public function test_data_keys_must_be_32_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DataKey(7, 3, str_repeat('a', 16));
    }
}
