New contact form submission.

Submitted by: {{ $email }}
@if ($accountName !== null && $accountId !== null)
Account: {{ $accountName }} (user id {{ $accountId }})
@endif
Locale: {{ $locale }}

---
{!! $message !!}
