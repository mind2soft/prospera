{!! __('mail.contributor_transaction.intro', [
    'actor' => $actorName,
    'ledger' => $ledgerName,
    'profile' => $profileName,
    'envelope' => $envelopeName,
    'direction' => __('mail.directions.'.$direction),
    'date' => $eventDateLabel,
]) !!}
@if ($amountLabel !== null)
{!! __('mail.contributor_transaction.amount', ['amount' => $amountLabel]) !!}
@endif

{!! __('mail.contributor_transaction.next_steps') !!}
{!! $ledgerUrl !!}
---
{!! __('mail.contributor_transaction.manage_notifications') !!}
{!! route('notifications.edit') !!}
