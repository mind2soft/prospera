<?php

namespace App\Http\Requests\Sharing;

/**
 * Ownership-transfer payload {user_id}; whether the target qualifies is
 * resolved by ResourcePermissionService.
 */
class TransferOwnershipRequest extends SharedResourceRequest
{
    protected function ability(): string
    {
        return 'transferOwnership';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }

    public function targetId(): int
    {
        return (int) $this->validated('user_id');
    }
}
