<?php

// Email copy (subjects and plain-text bodies). Mails are rendered in the
// recipient's locale (users.locale, fallback en) — docs/notifications.md.
return [

    'roles' => [
        'owner' => 'owner',
        'collaborator' => 'collaborator',
        'viewer' => 'viewer',
    ],

    'invite' => [
        'subject' => '[Prospera] :inviter shared their financial profile ":profile" with you',
        'subject_generic' => '[Prospera] A financial profile was shared with you',
        'new_account' => [
            'intro' => ':inviter (:email) shared their financial profile ":profile" with you on Prospera, as a :role.',
            'action' => 'To join them, create your account using the link below — your email address is already filled in, and the shared profile becomes yours to explore as soon as your account is verified.',
        ],
        'existing_account' => [
            'intro' => ':inviter (:email) shared their financial profile ":profile" with you, as a :role. To accept, click below; if you do not wish to join, simply ignore this message — ignoring the request declines the share.',
            'other_account' => 'If the link above signs you in as someone else, open :url instead.',
        ],
        'expires' => 'This invitation expires on :date (48 hours after it was sent).',
        'manage_notifications' => 'Manage your notifications:',
    ],

];
