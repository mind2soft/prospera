<?php

namespace App\Http\Requests\Sharing;

use App\Services\ResourcePermissions;
use Illuminate\Validation\Rule;

/**
 * Permission-only payload ("owner" is rejected — use transfer-ownership).
 */
class UpdateShareRequest extends SharedResourceRequest
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
        return self::permissionRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::permissionMessages();
    }

    public function permission(): string
    {
        return (string) $this->validated('permission');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function permissionRules(): array
    {
        return [
            'permission' => ['bail', 'required', 'string', Rule::in(ResourcePermissions::GRANTABLE)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function permissionMessages(): array
    {
        return [
            'permission.in' => __('The "owner" permission cannot be granted — use transfer-ownership.'),
        ];
    }
}
