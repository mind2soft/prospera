<?php

namespace App\Http\Requests;

use App\Models\Envelope;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Envelope creation (any user — the creator owns it) and edition (owner
 * only, authorized before the payload is validated).
 */
class EnvelopeRequest extends FormRequest
{
    public function authorize(): Response
    {
        $envelope = $this->route('envelope');

        return $envelope instanceof Envelope
            ? Gate::inspect('update', $envelope)
            : Response::allow();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('envelopes')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('envelope')),
            ],
            // The envelope's direction (exposed as `direction` in API payloads).
            'type' => ['required', 'string', Rule::in(Envelope::DIRECTIONS)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('You already have an envelope with this name.'),
        ];
    }
}
