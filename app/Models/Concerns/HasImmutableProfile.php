<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * The resource cannot move to another financial profile: its amounts (and
 * its children's) are encrypted with the profile's key and bound to it
 * (docs/encryption.md).
 */
trait HasImmutableProfile
{
    /**
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty('profile_id')) {
            throw new LogicException(static::class.' cannot move to another financial profile.');
        }

        return parent::save($options);
    }
}
