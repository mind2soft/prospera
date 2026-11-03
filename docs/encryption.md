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
  writes (`update`, `insert`, `upsert`, `increment`, `fillAndInsert`…) refuse
  anything but ciphertext in these columns; `DB::table()` and `toBase()`
  writes bypass Eloquent and must never touch them.
- Changing an attribute bound into the context (a transaction's ledger,
  envelope or date; an assignment's budget or envelope) requires assigning
  the amount in the same save. Ledgers and budgets cannot move to another
  profile.
- `replicate()` copies carry the plaintext amounts and are encrypted afresh in
  their own context.

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
| `amounts:verify [--profile=<id>]`    | Decrypts every amount and lists failures by table and id; non-zero exit on any failure. Runs on every deploy.                                                                                            |

Retired versions stay readable for the grace period so a request that loaded
the previous key just before a rotation can still write; pruning re-encrypts
those stragglers.

### Backing up `APP_KEY`

**`APP_KEY` is now required to read every amount.** Losing it loses all
amounts, permanently. Escrow it (password manager or secret store) and keep it
**separate** from database backups — storing them together defeats the
encryption. Never run `php artisan key:generate` on an existing installation
without following the rotation procedure below.

### Rotating `APP_KEY`

1. Add the current key to `APP_PREVIOUS_KEYS` (comma-separated; GitHub secret
   `APP_PREVIOUS_KEYS` in production) and set the new `APP_KEY`.
2. Deploy. Wrapped keys, sessions and cookies still decrypt through the
   previous key.
3. Run `php artisan amounts:rewrap-keys`, then `php artisan amounts:verify`.
4. Remove the old key from `APP_PREVIOUS_KEYS` and deploy again (once old
   sessions no longer matter).

### Restores and failed verification

After restoring a backup, run `php artisan amounts:verify` with the `APP_KEY`
that was live when the backup was taken (or with it in `APP_PREVIOUS_KEYS`).
A failing row was tampered with, corrupted or written outside Eloquent; it
makes the pages reading it fail (HTTP 500). There is no automatic repair:
restore the row from a backup taken with the same `APP_KEY`.

### Deployments

`scripts/ci/deploy-production.sh` runs pending migrations in maintenance mode,
so the previous release never serves requests against a new schema (for this
feature: it would read ciphertext and could write plaintext). If any step
after migrating fails, or the health check fails, the release's **new**
migrations — and only those — are rolled back before the previous release is
restored. `amounts:verify` must pass before a release goes live. A failed
rollback leaves the app in maintenance mode for manual intervention.

`scripts/ci/rollback-production.sh` likewise rolls back only the migrations
the target release does not ship, in maintenance mode, before switching.

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
profile and request. Totals hydrate and decrypt the rows they sum; rotation
and `amounts:verify` scan every amount.

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
