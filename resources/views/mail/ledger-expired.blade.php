{!! __('mail.ledger_expired.intro', ['ledger' => $ledgerName, 'profile' => $profileName, 'start' => $startLabel, 'end' => $endLabel]) !!}

{!! $ledgerUrl !!}

{!! $isOwner
    ? __('mail.ledger_expired.next_steps_owner')
    : __('mail.ledger_expired.next_steps_member') !!}
---
{!! __('mail.ledger_expired.manage_notifications') !!}
{!! route('notifications.edit') !!}
