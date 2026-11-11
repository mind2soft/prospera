<?php

namespace App\Http\Requests\Dashboard;

use App\Services\Dashboard\DashboardLayoutValidator;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Full dashboard layout save (profile members only). The payload is a
 * bounded `widgets` list; each widget is then validated and normalized by
 * DashboardLayoutValidator.
 */
class SaveDashboardLayoutRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->route('profile'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'widgets' => ['required', 'array', 'max:'.DashboardLayoutValidator::MAX_WIDGETS],
        ];
    }

    /**
     * The normalized widget configurations, in payload (display) order.
     *
     * @return list<array{id?: int, type: string, title: string|null, view_type: string, column_width: int, period: array{type: string, range: int}, selection: list<array<string, mixed>>}>
     */
    public function widgets(DashboardLayoutValidator $validator): array
    {
        return $validator->validate((array) $this->validated('widgets'), $this->user());
    }
}
