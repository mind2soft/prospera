<?php

namespace App\Security\Amounts;

use LogicException;
use RuntimeException;

/**
 * AES-256-GCM encryption of signed 64-bit amounts.
 *
 * Payload: "pa1.<keyVersion>.<base64url(iv[12] || ciphertext[8] || tag[16])>".
 * The plaintext is always 8 bytes, so every payload has the same length.
 */
final class AmountCipher
{
    public const FORMAT = 'pa1';

    public const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    private const PLAINTEXT_LENGTH = 8;

    private const PATTERN = '/^pa1\.([1-9][0-9]{0,8})\.([A-Za-z0-9_-]{48})$/D';

    public function encrypt(#[\SensitiveParameter] int $amount, DataKey $key, AmountContext $context): string
    {
        $this->assertKeyMatchesContext($key, $context);

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            pack('J', $amount),
            self::CIPHER,
            $key->bytes(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context->aad($key->version),
            self::TAG_LENGTH,
        );

        if ($ciphertext === false || strlen($ciphertext) !== self::PLAINTEXT_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Amount encryption failed.');
        }

        return self::FORMAT.'.'.$key->version.'.'.self::base64UrlEncode($iv.$ciphertext.$tag);
    }

    public function decrypt(string $payload, DataKey $key, AmountContext $context): int
    {
        $this->assertKeyMatchesContext($key, $context);

        [$version, $binary] = self::parse($payload, $context);

        if ($version !== $key->version) {
            throw self::failure($context, 'key version mismatch');
        }

        $plaintext = openssl_decrypt(
            substr($binary, self::IV_LENGTH, self::PLAINTEXT_LENGTH),
            self::CIPHER,
            $key->bytes(),
            OPENSSL_RAW_DATA,
            substr($binary, 0, self::IV_LENGTH),
            substr($binary, self::IV_LENGTH + self::PLAINTEXT_LENGTH),
            $context->aad($key->version),
        );

        if ($plaintext === false || strlen($plaintext) !== self::PLAINTEXT_LENGTH) {
            throw self::failure($context, 'authentication failed');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $plaintext);

        return $unpacked[1];
    }

    /**
     * The key version a payload was encrypted with.
     */
    public static function keyVersionOf(string $payload, ?AmountContext $context = null): int
    {
        return self::parse($payload, $context)[0];
    }

    public static function looksEncrypted(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function parse(string $payload, ?AmountContext $context): array
    {
        if (! preg_match(self::PATTERN, $payload, $matches)) {
            throw self::failure($context, 'malformed payload');
        }

        $binary = base64_decode(strtr($matches[2], '-_', '+/'), true);

        if ($binary === false || strlen($binary) !== self::IV_LENGTH + self::PLAINTEXT_LENGTH + self::TAG_LENGTH) {
            throw self::failure($context, 'malformed payload');
        }

        return [(int) $matches[1], $binary];
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function assertKeyMatchesContext(DataKey $key, AmountContext $context): void
    {
        if ($key->profileId !== $context->profileId) {
            throw new LogicException('Amount key does not belong to the context profile.');
        }
    }

    private static function failure(?AmountContext $context, string $reason): AmountDecryptionException
    {
        $where = $context === null
            ? ''
            : " ({$context->table}.{$context->column}, profile {$context->profileId})";

        return new AmountDecryptionException("Unable to decrypt amount{$where}: {$reason}.");
    }
}
