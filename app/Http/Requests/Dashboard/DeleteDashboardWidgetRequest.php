<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Removes one of the caller's widgets (destructive — the client asks for
 * confirmation). The removal is a layout write like any other: it must
 * carry the revision the client saw, so it cannot race a concurrent save
 * (issue #55) — the expected revision travels as a query parameter.
 */
class DeleteDashboardWidgetRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('delete', [$this->route('widget'), $this->route('profile')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
