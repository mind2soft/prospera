<?php

namespace App\Security\Amounts;

use InvalidArgumentException;
use LogicException;
use WeakMap;

/**
 * A plaintext per-profile amount data key, held in memory only. Its bytes
 * live outside the instance (a private static WeakMap), so no dumper — PHP's
 * own, Symfony VarDumper (dump(), dd(), Tinker) — nor an (array) cast can
 * print them; it refuses to be serialized or cloned. Reflection can still
 * reach them: PHP has no protected memory.
 */
final class DataKey
{
    public const LENGTH = 32;

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $secrets = null;

    public function __construct(
        public readonly int $profileId,
        public readonly int $version,
        #[\SensitiveParameter] string $bytes,
    ) {
        if ($profileId < 1 || $version < 1) {
            throw new InvalidArgumentException('Amount key profile id and version must be positive.');
        }

        if (strlen($bytes) !== self::LENGTH) {
            throw new InvalidArgumentException('Amount key must be exactly '.self::LENGTH.' bytes.');
        }

        self::$secrets ??= new WeakMap;
        self::$secrets[$this] = $bytes;
    }

    public static function generate(int $profileId, int $version): self
    {
        return new self($profileId, $version, random_bytes(self::LENGTH));
    }

    public function bytes(): string
    {
        $bytes = self::$secrets[$this] ?? null;

        if ($bytes === null) {
            throw new LogicException('Amount key bytes are unavailable.');
        }

        return $bytes;
    }

    /**
     * @return array{profileId: int, version: int}
     */
    public function __debugInfo(): array
    {
        return ['profileId' => $this->profileId, 'version' => $this->version];
    }

    public function __clone(): void
    {
        throw new LogicException('Amount keys must never be cloned.');
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
