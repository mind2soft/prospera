{!! __('mail.payment_reminder.intro', ['envelope' => $envelopeName, 'date' => $dateLabel]) !!}
@if ($amountLabel !== null)
{!! __('mail.payment_reminder.amount', ['amount' => $amountLabel]) !!}
@endif

{!! __('mail.payment_reminder.next_steps') !!}
{!! $ledgerUrl !!}
---
{!! __('mail.payment_reminder.manage_notifications') !!}
{!! route('notifications.edit') !!}
