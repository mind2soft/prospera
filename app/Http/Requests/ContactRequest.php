<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contact form submission (guests and signed-in users). Maximum lengths are
 * mirrored client-side (issue #17).
 */
class ContactRequest extends FormRequest
{
    public const SUBJECT_MAX = 100;

    public const MESSAGE_MAX = 4000;

    /**
     * The signed-in account email always wins over the (read-only) form
     * field.
     */
    protected function prepareForValidation(): void
    {
        if ($this->user()) {
            $this->merge(['email' => $this->user()->email]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
            'subject' => ['required', 'string', 'max:'.self::SUBJECT_MAX],
            'message' => ['required', 'string', 'max:'.self::MESSAGE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('Please enter your email address.'),
            'email.email' => __('Please enter a valid email address.'),
            'subject.required' => __('Please enter a subject.'),
            'subject.max' => __('The subject must not exceed :max characters.'),
            'message.required' => __('Please enter a message.'),
            'message.max' => __('The message must not exceed :max characters.'),
        ];
    }
}
