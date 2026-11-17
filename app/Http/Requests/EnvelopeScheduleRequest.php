<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesLocalCalendar;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Rules\ScheduleCalendarDate;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Authorize before reporting payload errors; merge PATCH definitions only under the envelope lock. */
class EnvelopeScheduleRequest extends FormRequest
{
    use ResolvesLocalCalendar;

    public function authorize(): Response
    {
        $envelope = $this->route('envelope');

        return $envelope instanceof Envelope
            ? Gate::inspect('manageSchedules', $envelope)
            : Response::denyAsNotFound();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return $this->timezoneRules() + [
                'page' => ['sometimes', 'integer', 'between:1,1000000'],
                'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ];
        }
        if ($this->isMethod('DELETE')) {
            return [];
        }

        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return $this->timezoneRules() + [
            // JSON minor-unit magnitudes; the server, not the caller, signs
            // them from the envelope direction. Same maximum as transactions.
            'amount' => [$required, 'required', 'integer:strict', 'between:0,99999999999'],
            'period' => [$required, 'required', 'string', Rule::in(['once', 'weekly', 'biweekly', 'monthly', 'yearly'])],
            'start_date' => [$required, 'required', new ScheduleCalendarDate],
            'end_date' => ['sometimes', 'nullable', new ScheduleCalendarDate],
            'weekdays' => ['sometimes', 'array', 'list', 'max:7'],
            'weekdays.*' => ['integer:strict', 'between:1,7', 'distinct'],
            'day_of_month' => ['sometimes', 'nullable', 'integer:strict', 'between:1,31'],
            'month' => ['sometimes', 'nullable', 'integer:strict', 'between:1,12'],
            'reminder_days' => ['sometimes', 'nullable', 'integer:strict', 'between:0,'.EnvelopeSchedule::MAX_REMINDER_DAYS],
            'reminder_unit' => ['sometimes', 'nullable', 'string', Rule::in([EnvelopeSchedule::REMINDER_UNIT_DAY, EnvelopeSchedule::REMINDER_UNIT_MONTH])],
            'id' => ['missing'],
            'envelope_id' => ['missing'],
            'profile_id' => ['missing'],
            'user_id' => ['missing'],
            'deleted_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }

    /**
     * Called after re-resolving the schedule and owner under locks. A partial
     * PATCH cannot overwrite unrelated concurrent edits with stale values.
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Envelope $envelope, ?EnvelopeSchedule $schedule = null): array
    {
        $input = Arr::only($this->validated(), [
            'amount', 'period', 'start_date', 'end_date', 'weekdays', 'day_of_month', 'month', 'reminder_days', 'reminder_unit',
        ]);
        $definition = array_replace([
            'period' => $schedule?->period,
            'start_date' => $schedule?->start_date,
            'end_date' => $schedule?->end_date,
            'weekdays' => $schedule === null ? [] : $schedule->weekdays,
            'day_of_month' => $schedule?->day_of_month,
            'month' => $schedule?->month,
            'reminder_days' => $schedule?->reminder_days,
            'reminder_unit' => $schedule?->reminder_unit,
        ], Arr::except($input, ['amount']));

        $weekly = in_array($definition['period'], ['weekly', 'biweekly'], true);
        $monthly = in_array($definition['period'], ['monthly', 'yearly'], true);
        if ($definition['period'] !== $schedule?->period) {
            // Changing periods discards omitted, now-irrelevant old fields;
            // explicitly supplied irrelevant fields still produce a 422.
            if (! $weekly && ! array_key_exists('weekdays', $input)) {
                $definition['weekdays'] = [];
            }
            if (! $monthly && ! array_key_exists('day_of_month', $input)) {
                $definition['day_of_month'] = null;
            }
            if ($definition['period'] !== 'yearly' && ! array_key_exists('month', $input)) {
                $definition['month'] = null;
            }
        }

        // A reminder without days has no unit; never leave a dangling one.
        if ($definition['reminder_days'] === null) {
            $definition['reminder_unit'] = null;
        }

        // A month unit counts calendar months and is bounded tighter than days.
        $unit = $definition['reminder_unit'] ?? EnvelopeSchedule::REMINDER_UNIT_DAY;
        Validator::make($definition, [
            'end_date' => ['nullable', 'after_or_equal:start_date'],
            'weekdays' => $weekly ? ['required', 'array', 'min:1'] : ['array', 'size:0'],
            'day_of_month' => $monthly ? ['required'] : ['prohibited'],
            'month' => $definition['period'] === 'yearly' ? ['required'] : ['prohibited'],
            'reminder_days' => $unit === EnvelopeSchedule::REMINDER_UNIT_MONTH
                ? ['nullable', 'integer', 'between:0,'.EnvelopeSchedule::MAX_REMINDER_MONTHS]
                : [],
        ])->validate();

        $attributes = Arr::only($definition, array_keys(Arr::except($input, ['amount'])));
        if ($schedule === null || $definition['period'] !== $schedule->period) {
            $attributes = $definition;
        }
        if ($definition['reminder_days'] === null && array_key_exists('reminder_days', $input)) {
            // Clearing the reminder clears its unit, even when the client omits it.
            $attributes['reminder_unit'] = null;
        }
        if (array_key_exists('amount', $input)) {
            $magnitude = $this->integer('amount');
            $attributes['amount'] = $envelope->type === Envelope::DIRECTION_EXPENSE ? -$magnitude : $magnitude;
        } elseif ($schedule !== null && $definition['start_date'] !== $schedule->start_date) {
            // Explicitly re-bind the existing amount when its AAD date changes.
            $attributes['amount'] = $schedule->amount;
        }

        return $attributes;
    }
}
