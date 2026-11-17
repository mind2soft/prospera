<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\Concerns\ResolvesUser;
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
    use ResolvesUser;

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
            // An empty layout is a valid save (removing every widget) — the
            // list must be present, not non-empty.
            'widgets' => ['present', 'array', 'max:'.DashboardLayoutValidator::MAX_WIDGETS],
            // The layout revision the client edited against (issue #55): a
            // stale value is refused without changing data, so an older save
            // can never overwrite a newer layout.
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * The normalized widget configurations, in payload (display) order.
     *
     * @return list<array{id: int|null, type: string, title: string|null, view_type: string, column_width: int, period: array{type: string, range: int}, selection: list<array<string, mixed>>}>
     */
    public function widgets(DashboardLayoutValidator $validator): array
    {
        return $validator->validate((array) $this->validated('widgets'), $this->currentUser());
    }
}
