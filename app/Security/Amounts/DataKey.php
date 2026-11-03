<?php

namespace App\Security\Amounts;

use InvalidArgumentException;
use LogicException;

/**
 * A plaintext per-profile amount data key, held in memory only. It refuses
 * to be serialized and hides its bytes from debug output.
 */
final class DataKey
{
    public const LENGTH = 32;

    public function __construct(
        public readonly int $profileId,
        public readonly int $version,
        #[\SensitiveParameter] private readonly string $bytes,
    ) {
        if ($profileId < 1 || $version < 1) {
            throw new InvalidArgumentException('Amount key profile id and version must be positive.');
        }

        if (strlen($bytes) !== self::LENGTH) {
            throw new InvalidArgumentException('Amount key must be exactly '.self::LENGTH.' bytes.');
        }
    }

    public static function generate(int $profileId, int $version): self
    {
        return new self($profileId, $version, random_bytes(self::LENGTH));
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * @return array{profileId: int, version: int}
     */
    public function __debugInfo(): array
    {
        return ['profileId' => $this->profileId, 'version' => $this->version];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Amount keys must never be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Amount keys must never be unserialized.');
    }
}
