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

    // Ledger expired (issue #50): an open ledger whose end date has passed,
    // sent once per ledger when the daily run first detects it.
    'ledger_expired' => [
        'subject' => '[Prospera] Ledger ":ledger" has reached its end date',
        'subject_generic' => '[Prospera] A ledger has reached its end date',
        'intro' => 'The open ledger ":ledger" of your financial profile ":profile" has reached its end date (:end — it ran from :start). Its end date has passed: transactions dated after :end can no longer be recorded on it.',
        'next_steps_owner' => 'As the ledger owner, you can close it now, or create the next period\'s ledger first and close this one once you are done reviewing it.',
        'next_steps_member' => 'The ledger owner can close it, or create the next period\'s ledger to keep recording transactions.',
        'manage_notifications' => 'Manage your notifications:',
    ],

];
