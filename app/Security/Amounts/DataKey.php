<?php

namespace App\Security\Amounts;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * A plaintext per-profile amount data key, held in memory only. It refuses
 * to be serialized and hides its bytes from debug output (an explicit
 * (array) cast or reflection can still reach them: PHP has no protected
 * memory).
 */
final class DataKey
{
    public const LENGTH = 32;

    /**
     * Holds the bytes out of the object's properties, so var_export() and
     * the debug dumpers (with __debugInfo) cannot print them.
     *
     * @var Closure(): string
     */
    private readonly Closure $bytes;

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

        $this->bytes = static fn (): string => $bytes;
    }

    public static function generate(int $profileId, int $version): self
    {
        return new self($profileId, $version, random_bytes(self::LENGTH));
    }

    public function bytes(): string
    {
        return ($this->bytes)();
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
