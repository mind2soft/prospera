<?php

/**
 * Playwright seed for the contact specs: clears the submission log so the
 * 12-hour per-IP cooldown never carries over between suite runs (the log is
 * written by every successful e2e submission — see e2e/contact.spec.ts).
 *
 * Runs against the isolated e2e database (.env.e2e — see e2e/serve.sh):
 * npm run e2e:seed -- seed-contact.php
 */

use Illuminate\Support\Facades\DB;

DB::table('contact_submissions')->delete();
