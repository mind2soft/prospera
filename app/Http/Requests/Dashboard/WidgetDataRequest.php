<?php

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\Concerns\ResolvesLocalCalendar;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * One of the caller's widgets' aggregate data, for the user's calendar day.
 */
class WidgetDataRequest extends FormRequest
{
    use ResolvesLocalCalendar;

    public function authorize(): Response
    {
        return Gate::inspect('view', [$this->route('widget'), $this->route('profile')]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->timezoneRules();
    }
}
