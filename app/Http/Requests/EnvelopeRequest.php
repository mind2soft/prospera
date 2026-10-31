<?php

namespace App\Http\Requests;

use App\Models\Envelope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
