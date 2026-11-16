{!! __('mail.envelope_limit_exceeded.intro', [
    'envelope' => $envelopeName,
    'ledger' => $ledgerName,
    'profile' => $profileName,
    'month' => $monthLabel,
]) !!}
@if ($allocatedLabel !== null)
{!! __('mail.envelope_limit_exceeded.allocated', ['amount' => $allocatedLabel]) !!}
{!! __('mail.envelope_limit_exceeded.spent', ['amount' => $spentLabel]) !!}
{!! __('mail.envelope_limit_exceeded.over', ['amount' => $overLabel]) !!}
@endif

{!! __('mail.envelope_limit_exceeded.next_steps') !!}
{!! $ledgerUrl !!}
---
{!! __('mail.envelope_limit_exceeded.manage_notifications') !!}
{!! route('notifications.edit') !!}
