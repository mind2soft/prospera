<?php

namespace App\Http\Requests\Settings;

use App\Settings\NotificationSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The full notification catalog, the master switch included (autosave).
 */
class NotificationSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            NotificationSettings::MASTER => ['required', 'boolean'],
            NotificationSettings::PAYMENT_REMINDERS => ['required', 'boolean'],
            NotificationSettings::LEDGER_EXPIRED => ['required', 'boolean'],
            NotificationSettings::CONTRIBUTOR_TRANSACTIONS => ['required', 'boolean'],
            NotificationSettings::ENVELOPE_LIMIT_EXCEEDED => ['required', 'boolean'],
            NotificationSettings::PROFILE_INVITE => ['required', 'boolean'],
        ];
    }
}
