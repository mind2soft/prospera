@if ($acceptUrl === null)
{{ $inviterName }} ({{ $inviterEmail }}) shared their financial profile "{{ $profileName }}" with you on Prospera, as a {{ $role }}.

To join them, create your account using the link below — your email address is already filled in, and the shared profile becomes yours to explore as soon as your account is verified.

{{ $inviteUrl }}
@else
{{ $inviterName }} ({{ $inviterEmail }}) shared their financial profile "{{ $profileName }}" with you, as a {{ $role }}. To accept, click below; if you do not wish to join, simply ignore this message — ignoring the request declines the share.

{{ $acceptUrl }}

If the link above signs you in as someone else, open {{ $inviteUrl }} instead.
@endif
@if ($expiresAt !== null)
This invitation expires on {{ $expiresAt->format('Y-m-d H:i T') }} (48 hours after it was sent).
@endif
@if ($acceptUrl !== null)
---
Manage your notifications:
{{ route('notifications.edit') }}
@endif
