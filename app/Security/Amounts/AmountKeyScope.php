<?php

declare(strict_types=1);

namespace App\Security\Amounts;

use App\Models\Envelope;
use App\Models\EnvelopeAmountKey;
use App\Models\FinancialProfile;
use App\Models\FinancialProfileKey;

/** Distinct key namespaces, even when a profile and envelope have the same id. */
enum AmountKeyScope: string
{
    case Profile = 'profile';
    case Envelope = 'envelope';

    public function binding(): string
    {
        return $this === self::Profile ? 'p' : 'e';
    }

    public function column(): string
    {
        return $this->value.'_id';
    }

    /** @return class-string<FinancialProfileKey>|class-string<EnvelopeAmountKey> */
    public function keyModel(): string
    {
        return $this === self::Profile ? FinancialProfileKey::class : EnvelopeAmountKey::class;
    }

    /** @return class-string<FinancialProfile>|class-string<Envelope> */
    public function ownerModel(): string
    {
        return $this === self::Profile ? FinancialProfile::class : Envelope::class;
    }
}
