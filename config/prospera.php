<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository
    |--------------------------------------------------------------------------
    |
    | Public URL of the project's repository, shown on the landing page and
    | shared to the frontend (issue #17). Self-hosted deployments can point
    | it at their own instance via GITHUB_REPO_URL.
    |
    */

    'github_repo_url' => env('GITHUB_REPO_URL', 'https://github.com/mind2soft/prospera'),

    /*
    |--------------------------------------------------------------------------
    | Contact Form
    |--------------------------------------------------------------------------
    |
    | Retention of the contact-submission log, in days (issue #17). Old rows
    | are deleted daily by `contact:prune`.
    |
    */

    'contact_retention_days' => (int) env('CONTACT_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Unverified Accounts
    |--------------------------------------------------------------------------
    |
    | Days an account may stay unverified before `users:prune-unverified`
    | (scheduled daily) deletes it — only accounts holding no data are
    | pruned (docs/shared-finances.md — Account termination).
    |
    */

    'unverified_account_retention_days' => (int) env('UNVERIFIED_ACCOUNT_RETENTION_DAYS', 30),

];
