<?php

namespace App\Http\Requests\Sharing;

/**
 * Grant payload {user_id, permission}; "owner" is reserved for
 * transfer-ownership. Whether the target may be granted is resolved by
 * ResourcePermissionService.
 */
class StoreShareRequest extends SharedResourceRequest
{
    protected function ability(): string
    {
        return 'share';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            ...UpdateShareRequest::permissionRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UpdateShareRequest::permissionMessages();
    }

    public function targetId(): int
    {
        return (int) $this->validated('user_id');
    }

    public function permission(): string
    {
        return (string) $this->validated('permission');
    }
}
