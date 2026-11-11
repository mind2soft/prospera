# Encryption at rest

Money amounts are encrypted in the database (issue #4). A leaked database
dump, backup or read-only SQL access reveals no income, spending or debt
figure; tampered or swapped values are detected instead of being displayed.

Encrypted columns:

| Column                     | Bound context (AAD)                             |
| -------------------------- | ----------------------------------------------- |
| `transactions.amount`      | profile, ledger, envelope, event date (`Y-m-d`) |
| `budget_envelopes.amount`  | profile, budget, envelope                       |
| `ledgers.starting_balance` | profile                                         |

Everything happens on the server: models decrypt the amounts for authorized
users only, and the API and Inertia payloads still carry plain integers over
TLS. The frontend, TypeScript types and Wayfinder routes are unaffected.

## Threat model

Protects against:

- **Database dumps and backups** — `pg_dump`, snapshots, a copied
  `database.sqlite`, a stolen disk.
- **Read-only SQL access** — SQL injection reads or database credentials
  without the application environment (`APP_KEY`).
- **Hosting and DBA staff** browsing tables, query and slow-query logs, and
  analytics replicas.
- **Tampering and ciphertext swapping** by someone able to write to the
  database: a value moved to another row context, profile, column or table
  fails to decrypt, loudly.

Does **not** protect against:

- **Full server compromise** — whoever holds `APP_KEY` and the database can
  decrypt everything. Moving the key-encryption key to a KMS/HSM is future
  work.
- **Authorized users**, including collaborators — access control stays with
  the permission model ([Shared finances](shared-finances.md)).
- **XSS** — decrypted amounts reach the browsers of authorized users.
- **Metadata** — row counts, dates, envelope names and colors, ledger and
  budget names stay in plaintext.
- **Swaps within an identical context** — two transactions of the same
  ledger, envelope and day can trade ciphertexts, a row can be rolled back to
  an older ciphertext of itself, and ledgers of one profile can swap starting
  balances (the row id is not known at insert time, so it is not bound).
- **Old backups** — they contain the wrapped keys, which `APP_KEY` unwraps;
  deleting a key row does not shred backed-up data.

## Key hierarchy

```
APP_KEY (key-encryption key; Laravel Crypt, AES-256-CBC + HMAC-SHA256)
  └─ financial_profile_keys.wrapped_key   one 32-byte data key per profile and version
       └─ amount ciphertext                AES-256-GCM, one per stored amount
```

- Every financial profile gets a random data key (version 1) on creation
  (`FinancialProfile::booted`). Keys are stored **wrapped** with `APP_KEY`
  only; the wrapped payload binds the profile id and version, which are
  checked when unwrapping.
- Plaintext keys live in memory only, memoized per request/job by the scoped
  `AmountKeyRing` — never cached, logged, serialized or sent to clients.
- Collaborators never receive keys: the server decrypts after authorization.

## Ciphertext format

```
pa1.<keyVersion>.<base64url(iv[12] || ciphertext[8] || tag[16])>
```

- `pa1` — format version: AES-256-GCM (`openssl`), random 96-bit IV, 128-bit
  tag. The plaintext is the amount as a signed 64-bit big-endian integer
  (`pack('J')`), so every payload has the same length (54–62 characters,
  stored in `varchar(80)`) whatever the value or sign.
- Additional authenticated data (AAD):

    ```
    prospera/amount/pa1|v=<keyVersion>|t=<table>.<column>|p=<profileId>|<bindings>
    ```

    with the bindings in order: `l=<ledger>|e=<envelope>|d=<Y-m-d>`
    (transactions), `b=<budget>|e=<envelope>` (budget assignments), none
    (ledgers).

- Parsing is strict (anchored format, strict base64url, exact length). A
  malformed payload, unknown key version, wrong key, context mismatch or
  legacy plaintext value throws `AmountDecryptionException` (HTTP 500): an
  amount **never** silently reads as `0` or `null`.

## Development rules

- Every new money column uses the `EncryptsAmounts` trait and implements
  `HasEncryptedAmounts`.
- **Never aggregate, sort or filter money in SQL** (`SUM`, `ORDER BY`,
  `WHERE amount …`, `pluck('amount')`): load the models and compute in PHP.
  Envelope totals and the ledger's "remaining allocated" are computed this
  way.
- Write amounts through Eloquent models (`create`, `save`, `update`,
  `saveQuietly` — encryption does not rely on model events). Bulk Eloquent
  writes go through `GuardedBuilder`: `update`, `updateFrom`,
  `updateOrInsert` and `insert*` accept nothing but ciphertext in these
  columns; `upsert`, `increment`/`decrement`, `touch` and `insertUsing` on
  them are refused, and so is `fillAndInsert*` (its models hold plaintext).
  Multi-row inserts must give every row the same columns. `DB::table()` and `toBase()` writes
  bypass Eloquent and must never touch them.
- Changing an attribute bound into the context (a transaction's ledger,
  envelope or date; an assignment's budget or envelope) requires assigning
  the amount in the same save — a time change within the same day does not.
  Bulk updates of those columns are refused unless every amount column is
  re-written in the same statement.
- Ledgers and budgets cannot move to another profile (`profile_id` is
  immutable, for saves and bulk writes alike).
- `replicate()` copies carry the plaintext amounts and are encrypted afresh in
  their own context.
- A transaction's date is bound as the `Y-m-d` of `event_at` as stored.
  PostgreSQL (`timestamp without time zone`, production) and SQLite store it
  without conversion; on a database converting timestamps with the session
  time zone (MySQL `timestamp`), changing that time zone would make
  transaction amounts fail to decrypt.

## Operations

### Scheduler

Data keys are rotated monthly and retired versions pruned daily. The
production host must run the scheduler:

```cron
* * * * * cd /path/to/current && php artisan schedule:run >> /dev/null 2>&1
```

### Commands

| Command                              | Purpose                                                                                                                                                                                                  |
| ------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `amounts:rotate-keys --all`          | Scheduled monthly. Per profile, in one transaction with the profile and rows locked: retire the active key, create the next version, re-encrypt every amount. A failure rolls the profile back entirely. |
| `amounts:rotate-keys --profile=<id>` | Rotate given profiles (repeatable), e.g. after a suspected leak.                                                                                                                                         |
| `amounts:prune-keys [--grace=24]`    | Scheduled daily. Deletes key versions retired more than `--grace` hours ago, after re-encrypting any amount still using them; refuses to delete a version still in use.                                  |
| `amounts:rewrap-keys`                | Re-wraps every data key with the current `APP_KEY` (see below).                                                                                                                                          |
| `amounts:verify [--profile=<id>]`    | Decrypts every amount and lists failures by table and id; non-zero exit on any failure. Runs on every deploy and daily via the scheduler.                                                                |

Retired versions stay readable for the grace period so a request that loaded
the previous key just before a rotation can still write; pruning re-encrypts
those stragglers. A request that loaded a row just before pruning deletes its
key version may fail once (HTTP 500) when decrypting it; writes are not
affected.

### Backing up `APP_KEY`

**`APP_KEY` is now required to read every amount.** Losing it loses all
amounts, permanently. Escrow it (password manager or secret store) and keep it
**separate** from database backups — storing them together defeats the
encryption. Never run `php artisan key:generate` on an existing installation
without following the rotation procedure below.

### Detecting a broken `APP_KEY`

A rotated `APP_KEY` without `APP_PREVIOUS_KEYS` breaks every wrapped amount
key at once (e.g. `composer setup`, which runs `key:generate`
unconditionally). Instead of scattered `AmountDecryptionException` 500s, two
safeguards report it explicitly (issue #36):

- **Boot-time smoke check** — on every production HTTP request, one active
  amount key per profile is unwrapped; a failure aborts the request with an
  explicit, actionable error in the logs: restore the previous `APP_KEY` from
  the backup, or, if the rotation was intentional, list the old key in
  `APP_PREVIOUS_KEYS` and run `amounts:rewrap-keys`. HTTP requests only:
  artisan must keep booting so the recovery itself stays runnable. A broken
  key also fails the deploy's `/up` health check, leaving the previous
  release live or the app in maintenance mode (the deploy's own pre-switch
  `amounts:verify` catches it before the switch).
- **Daily scheduled `amounts:verify`** — so the failure surfaces in the logs
  even when no request touches amounts; the command logs a failure summary
  itself, since the scheduler's own output goes to `/dev/null`.

Neither check decrypts amounts: they only unwrap the data keys (individual
corrupted amounts are caught by `amounts:verify` on deploy).

### `APP_KEY` and `APP_PREVIOUS_KEYS` in production

Both are secrets of the GitHub `Production` environment (Settings →
Environments → Production), not repository secrets. Every deploy renders
`shared/.env` from them (`scripts/ci/render-production-env.sh`): an edit to
the `.env` on the host is overwritten by the next deploy.

| Secret              | Holds                                           | Used                                                       |
| ------------------- | ----------------------------------------------- | ---------------------------------------------------------- |
| `APP_KEY`           | The current key (`base64:…`).                   | Encrypts everything new; tried first when decrypting.      |
| `APP_PREVIOUS_KEYS` | Former keys, comma-separated (each `base64:…`). | Tried in order, only when decrypting with `APP_KEY` fails. |

`APP_PREVIOUS_KEYS` is normally **not defined**: an undefined secret renders
as empty, meaning no former key. It is only needed during a rotation. A key
listed there still decrypts everything it ever protected, so remove it as soon
as the rotation allows.

What `APP_KEY` protects here, and what happens to data still encrypted with a
key that is no longer listed:

| Data                                                           | Re-encrypted with the new key                                                                      | If its key is gone                                            |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Amount data keys (`financial_profile_keys.wrapped_key`)        | By `amounts:rewrap-keys`.                                                                          | **Every amount is lost**, permanently.                        |
| 2FA secrets and recovery codes (`users.two_factor_*`, Fortify) | Only when a user enables 2FA (secret) or uses or regenerates recovery codes; else by step 5 below. | Users with 2FA cannot log in until an admin resets their 2FA. |
| Cookies (session, remember me, CSRF)                           | On the next response.                                                                              | Users are signed out.                                         |
| Signed URLs (email verification links, valid 60 minutes)       | Never; new links use the new key.                                                                  | Pending links stop working.                                   |

Not affected: passwords (bcrypt hashes, no key involved) and password reset
links (stored as hashes too).

### Rotating `APP_KEY`

Follow the steps in order: removing a key before its data is re-encrypted
loses that data (table above).

1. **Get the current key** from the escrow and check that it matches the
   host's: `grep '^APP_KEY=' "$SSH_APP_PATH/shared/.env"`. GitHub secrets
   cannot be read back.
2. **Generate the new key** locally with `php artisan key:generate --show`
   (prints a key, writes no file). Escrow it **before** deploying it.
3. **Update the `Production` secrets.** `gh secret set` prompts for the value,
   which keeps the keys out of the shell history:

    ```sh
    gh secret set APP_PREVIOUS_KEYS --env Production   # the old key
    gh secret set APP_KEY --env Production             # the new key
    ```

    If `APP_PREVIOUS_KEYS` already lists keys, put the old key first:
    `old,older`.

4. **Deploy**: push the next `v*` tag or dispatch the `deploy` workflow
   (which deploys `main`); re-deploying the live release is refused. The
   secrets only reach the host through a deploy. Everything still decrypts
   through the previous key, so the deploy's `amounts:verify` passes.
5. **Re-encrypt with the new key**, on the host in `$SSH_APP_PATH/current`:

    ```sh
    php artisan amounts:rewrap-keys
    php artisan amounts:verify
    php artisan tinker --execute='App\Models\User::whereNotNull("two_factor_secret")->each(function ($u) { $u->forceFill(["two_factor_secret" => encrypt(decrypt($u->two_factor_secret)), "two_factor_recovery_codes" => $u->two_factor_recovery_codes === null ? null : encrypt(decrypt($u->two_factor_recovery_codes))])->saveQuietly(); }); echo "2FA re-encrypted\n";'
    ```

    `amounts:verify` does not cover 2FA. Check it decrypts with the new key
    alone; it must print `2FA OK`:

    ```sh
    php artisan tinker --execute='$e = new Illuminate\Encryption\Encrypter(base64_decode(Str::after(config("app.key"), "base64:")), config("app.cipher")); $bad = App\Models\User::whereNotNull("two_factor_secret")->get()->reject(function ($u) use ($e) { try { $e->decrypt($u->two_factor_secret); $u->two_factor_recovery_codes === null || $e->decrypt($u->two_factor_recovery_codes); return true; } catch (Throwable) { return false; } })->pluck("id"); echo $bad->isEmpty() ? "2FA OK\n" : "2FA still on an old key: users ".$bad->implode(", ")."\n";'
    ```

    Users who enable 2FA after this point already use the new key.

6. **Wait** for the cookies and links issued before step 4 to expire, if
   signing users out matters: at least `SESSION_LIFETIME` (120 minutes by
   default) and the 60-minute verification links. "Remember me" cookies last
   longer; their users have to log in again.
7. **Remove the old key**: `gh secret delete APP_PREVIOUS_KEYS --env
   Production` (or set it without the old key), then deploy again. That
   deploy's `amounts:verify` refuses to go live if an amount key is still
   wrapped with the removed key; fix the secrets and deploy again (see
   [Deployments](#deployments)).

If `APP_KEY` leaked, also run `php artisan amounts:rotate-keys --all` after
step 5: the leaked key unwraps the current data keys from any copy of the
database, so the amounts need new data keys, not just a re-wrap. Skip the
wait at step 6. Backups taken before the rotation stay readable with the
leaked key.

### Restores and failed verification

After restoring a backup, run `php artisan amounts:verify` with the `APP_KEY`
that was live when the backup was taken (or with it in `APP_PREVIOUS_KEYS`).
A failing row was tampered with, corrupted or written outside Eloquent; it
makes the pages reading it fail (HTTP 500). There is no automatic repair:
restore the row from a backup taken with the same `APP_KEY`.

### Deployments

Production migrations are **forward-only**: the app prohibits destructive
commands in production (`DB::prohibitDestructiveCommands()`), so
`migrate:rollback` and `migrate:reset` refuse to run there. A release must
therefore never run on a schema it does not know — for this feature, the
pre-encryption release would read ciphertext and could write plaintext. The
scripts compare releases with the migrations the database actually ran
(`php artisan migrations:ran`, read from the migrations table).

- `scripts/ci/deploy-production.sh` refuses a release that does not ship
  every migration the database ran, a deploy that cannot read them (except a
  first install), a release id that is not a single directory name
  (`A-Za-z0-9._-`, not `.` or `..`; tags must follow it — checked before
  connecting, and again on the host; every argument is quoted for the remote
  shell, since ssh joins them into one command line) and one that is, or
  resolves to, the live or the previous release (it would be rebuilt in
  place before any check; the `current` and `previous` links are resolved
  through themselves, so relative link targets are handled too). The deploy root (`SSH_APP_PATH`) must be an
  absolute path of `A-Za-z0-9._/-` without `.`, `..` or empty components
  nor a trailing `/` (releases are also compared resolved when pruning), and
  `KEEP_RELEASES` and `DEPLOY_SWITCH_WAIT` numbers of at most 4 digits; all
  are checked before connecting and on the host. The `.env` (it holds `APP_KEY`, which unwraps every amount key) is
  rendered owner-only and uploaded with that mode (scp keeps the source
  file's mode, checked against the production host, whose `/tmp` is also
  private to the account). The uploaded `.env` replaces `shared/.env` only
  once these checks passed; a refused deploy leaves it untouched. A deploy
  failing later (e.g. `amounts:verify` after an `APP_PREVIOUS_KEYS` change)
  has already replaced it: the live release keeps its cached configuration,
  but fix the secrets and deploy again before any rollback (which rebuilds
  the configuration from it, then refuses if `amounts:verify` fails).
- Both scripts connect only to a host whose key is pinned in the
  `SSH_KNOWN_HOSTS` variable of the `Production` environment (known_hosts
  lines, e.g. from `ssh-keyscan -H <host>` run once and checked against the
  host's fingerprint out of band). It is mandatory: a blank value fails the
  deploy or rollback before connecting — there is no trust-on-first-use
  fallback, since the deploy uploads the `.env` (with `APP_KEY`) over that
  connection. After a host key change, update the variable.
- It switches to maintenance mode before running pending migrations, so the
  previous release stops accepting requests (ones already in flight may still
  complete). `amounts:verify` must pass before the release goes live. Any
  failure during or after migrating — including a failed health check —
  **leaves the app in maintenance mode**: the previous release is not
  restored on the migrated schema. Recover by deploying a fix: once verified,
  it lifts the maintenance mode the failed deploy set. That maintenance mode
  is identified by `shared/deploy-maintenance`, written right after
  `php artisan down`: it only counts while the down file is not newer than
  it. Lifting maintenance mode by hand and turning it on again (an
  operator's) rewrites the down file, so the marker no longer applies and is
  removed. A failed health check without pending migrations reverts to the
  previous release (and restores the `previous` link) only if it ships
  exactly the migrations the database ran and no failed deploy's maintenance
  was pending; otherwise the new release stays in maintenance mode.
- An operator's maintenance mode (on before the deploy, or not matching that
  marker) is never lifted nor modified, and the health check is skipped: lift
  it once the live release is verified to match the schema and the switch
  wait below has elapsed.
- **PHP's realpath cache.** The web server reaches the app through the
  `current` symlink, and PHP caches resolved paths per worker process for
  `realpath_cache_ttl` seconds (PHP default 120). A long-lived worker can
  therefore keep running the release `current` pointed to before the switch
  for that long (reproduced with a persistent PHP process). After switching
  `current`, both scripts wait `realpath_cache_ttl + 5` seconds (read from
  the host's CLI PHP; 125 if unreadable) before lifting maintenance mode and
  before the health check, so neither happens while the replaced release may
  still serve requests. Set the `DEPLOY_SWITCH_WAIT` variable of the `Production` environment (in
  seconds, at most 9999; `0` disables it) when the web PHP's TTL differs from the CLI's, or
  when the server resolves the symlink itself (nginx:
  `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;` and
  `DOCUMENT_ROOT $realpath_root`). OPcache is not handled: on a host where
  it is enabled for the web PHP, reset it (or reload PHP) as part of the
  wait. On the production host (shared hosting, Apache, CloudLinux alt-php
  8.4), checked on 2026-11-02: the docroot is `current/public`,
  `realpath_cache_ttl` is 120 and OPcache is not loaded; the web handler's
  process model could not be inspected, so the default wait applies.
- After migrating (or failing to), the deploy records the migrations the
  database ran in `shared/schema-migrations`.
- `scripts/ci/rollback-production.sh` only switches to a release shipping
  exactly the migrations the database ran (read live from either release,
  else from that record; without either it refuses) with none pending. It rebuilds the target's
  configuration cache from the current `.env` (e.g. a rotated `APP_KEY`) and
  requires `amounts:verify` to pass with it when the target has that command;
  otherwise it refuses without switching. It refuses the live release (or an
  alias of it) as target and an invalid release id. It lifts a failed
  deploy's maintenance mode once the matching release is live (after the
  switch wait), never an operator's; a failed health check restores both the
  `current` and the `previous` links.
- A legacy layout (the app directly in the deploy root) is moved into
  `releases/` with its storage and `.env` shared, so maintenance mode covers
  it and a rollback to it uses the current `.env`; a pending migration is
  refused if the live release does not use the shared storage.
- Both scripts assume the `file` maintenance driver (the default,
  `APP_MAINTENANCE_DRIVER=file`). Their control flow is tested by
  `scripts/ci/tests/deploy-scripts.test.sh` (part of `composer ci:check`).

Once this feature is deployed, going back to a pre-encryption release is a
deliberate manual operation outside the workflow: the migration's `down()`
(tested: it decrypts every amount back to integers) has to be run on purpose,
which production prohibits by default. Afterwards the rollback workflow sees
the database's actual migrations and accepts the pre-encryption release.

### Migration of existing data

`2026_11_03_000001_encrypt_amounts_at_rest` creates the profiles' keys and
converts the three columns in place (each value round-trip checked before the
plaintext column is dropped). It is reversible: `down()` decrypts back to
integers with whichever key versions exist. On PostgreSQL each direction runs
in one transaction; on SQLite (development) it is not atomic and refuses to
run inside a transaction, because SQLite's table rebuild would otherwise
cascade-delete child rows.

### Performance

Decryption costs one AES-GCM operation per amount plus one key unwrap per
profile and request; the boot-time smoke check adds one more unwrap per
profile (its highest active key only) and one small query. Totals hydrate and
decrypt the rows they sum; rotation and `amounts:verify` scan every amount.

## Why not the original proposal

Issue #4 proposed XOR-masking amounts with a per-profile `amount_mask` shared
to the browser in an `fpp` cookie, refreshed by a Web Worker. It was rejected:

- XOR with a static mask is obfuscation, not encryption: deterministic, one
  known amount (e.g. a `0` starting balance) reveals the mask, high bits leak
  the sign and magnitude, and it has no integrity — flipped bits decode to a
  wrong number silently.
- The mask would sit in every member's browser: an HttpOnly cookie cannot be
  read by scripts, and a script-readable one hands the key to any XSS.
- Dedicated Web Workers cannot intercept requests nor read cookies (only
  Service Workers intercept `fetch`).
- Rotating the mask while clients hold the old one would display wrong
  figures without any error.
- XOR does not keep SQL aggregates working either, so server-side
  computation was required anyway; decrypting on the server costs
  microseconds per amount.

The goals kept from the issue: amounts unreadable in the database, a key per
financial profile, scheduled rotation inside a database transaction.

## Future work

- Key-encryption key in a KMS/HSM instead of `APP_KEY`.
- Confidentiality of metadata (names, dates, counts).
