@if ($acceptUrl === null)
{!! __('mail.invite.new_account.intro', ['inviter' => $inviterName, 'email' => $inviterEmail, 'profile' => $profileName, 'role' => $roleLabel]) !!}

{!! __('mail.invite.new_account.action') !!}

{!! $inviteUrl !!}
@else
{!! __('mail.invite.existing_account.intro', ['inviter' => $inviterName, 'email' => $inviterEmail, 'profile' => $profileName, 'role' => $roleLabel]) !!}

{!! $acceptUrl !!}

{!! __('mail.invite.existing_account.other_account', ['url' => $inviteUrl]) !!}
@endif
@if ($expiresAt !== null)
{!! __('mail.invite.expires', ['date' => $expiresAt->format('Y-m-d H:i T')]) !!}
@endif
@if ($acceptUrl !== null)
---
{!! __('mail.invite.manage_notifications') !!}
{!! route('notifications.edit') !!}
@endif
