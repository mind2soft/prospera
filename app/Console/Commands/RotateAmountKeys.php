<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\Amounts\AmountKeyManager;
use App\Security\Amounts\AmountKeyScope;
use Illuminate\Console\Command;
use Throwable;

class RotateAmountKeys extends Command
{
    protected $signature = 'amounts:rotate-keys
        {--profile=* : Financial profile id(s) to rotate}
        {--envelope=* : Envelope id(s) to rotate}
        {--all : Rotate every financial profile and every initialized envelope key}';

    protected $description = 'Rotate the amount data keys and re-encrypt the amounts (docs/encryption.md)';

    public function handle(AmountKeyManager $manager): int
    {
        $targets = $this->targets($manager);

        if ($targets === null) {
            $this->error('Pass --profile=<id> and/or --envelope=<id> (repeatable), or --all.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($targets as [$scope, $profileId]) {
            $label = ucfirst($scope->value);
            try {
                $count = $manager->rotate($profileId, $scope);
                $this->line("{$label} {$profileId}: rotated, {$count} amount(s) re-encrypted.");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$label} {$profileId}: rotation failed and was rolled back ({$e->getMessage()}).");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{AmountKeyScope, int}>|null
     */
    private function targets(AmountKeyManager $manager): ?array
    {
        $targets = [];
        foreach (AmountKeyScope::cases() as $scope) {
            /** @var list<string> $ids */
            $ids = $this->option($scope->value);
            if ($this->option('all') && $ids !== []) {
                return null;
            }

            foreach ($this->option('all') ? $manager->ownerIds($scope) : array_unique($ids) as $id) {
                if (! preg_match('/^[1-9]\d*$/D', (string) $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
                    return null;
                }
                $targets[] = [$scope, (int) $id];
            }
        }

        return $targets === [] && ! $this->option('all') ? null : $targets;
    }
}
