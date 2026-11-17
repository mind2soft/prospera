# Financial write serialization

This is the locking prerequisite for scheduled-transaction materialization
([#15](https://github.com/mind2soft/prospera/issues/15)). It also protects
existing account deletion, profile lifecycle, amount-key maintenance, and
manual money writes. It does **not** enable generated transactions or a new
retention policy.

## Root order

When an operation needs several of these roots, acquire the needed set in
this order, with globally ascending IDs within each kind:

1. Financial profiles.
2. Ledgers.
3. Budgets.
4. The account being deleted.
5. Envelopes.
6. Child transaction/assignment/permission rows.

Not every writer needs every root. Manual transaction writers serialize on
their ledger before the envelope and transaction; draft assignment writers
serialize on the budget before the envelope and assignment. A writer must
not acquire a previously missing ledger beneath an envelope/transaction lock.
Future reconciliation follows the existing ledger-first transaction protocol,
not a new envelope-first protocol.

Profile key rotation and pruning lock the profile, **all its ledger roots,
then all its budget roots**, before changing key inventory or locking money
rows. Root queries select IDs in chunks of 500; they do not decrypt amounts.
This prevents key maintenance from holding a transaction row while waiting
for a manual writer's ledger root, and prevents budget-edit inversions.
Envelope key maintenance retains its existing envelope-root protocol and
does not wait for ledger/budget roots.

`Ledger::deleteWithTransactions()` takes the ledger lock itself, even when
called outside the ledger controller. Direct profile resource destruction
takes its profile, ledger, and budget roots before deleting children. Profile
membership removal and ownership transfer take the profile root and re-read
membership/ownership; transfer locks the draft budget before changing its
ownership. Their HTTP callers also recheck permissions beneath that root.
Budget create/clone/publish also reload and reauthorize beneath the profile
root, rather than trusting permission relations loaded before a lock wait.
Empty-profile deletion checks for budgets/ledgers beneath the profile lock,
so a concurrent resource insertion cannot slip between its check and delete.

## Account deletion and conditional pruning

Self-service deletion and stale-unverified-account pruning share the same
transaction owner: `DeleteAccount::deleteIf()` (`handle()` is unconditional).
The prune command must not wrap this in an earlier user-first transaction.

`AccountDeletionLocks` establishes the root set before any deletion effect:

1. Discover profile IDs from the account's memberships **and** every
   draft/published budget assignment or active/soft-deleted transaction
   referencing an account-owned envelope. References outside the account's
   memberships are included. Only plaintext metadata/IDs are queried.
2. Lock those profiles, then all their ledgers in global ID order, then all
   their budgets in global ID order. This happens before any envelope,
   transaction, or permission mutation, not one profile at a time during
   deletion.
3. Lock and reload the user; lock all currently owned envelopes in ID order.
4. Rediscover profile references. If any required profile was not locked,
   roll back the entire attempt and restart discovery. Never extend the
   profile/ledger lock set underneath user/envelope locks.

PostgreSQL's parent FK locks prevent new ledger/budget inserts under locked
profiles, and new user-referencing envelopes/memberships/ownership transfers
under the user lock. Envelope locks exclude new assignment/reference writers.
The closure check catches references committed between the discovery and
these barriers. Production PostgreSQL uses **READ COMMITTED**, so rediscovery
sees those commits; this protocol must not be assumed safe under a repeatable
snapshot without adapting that check. SQLite serializes actual writes and
does not provide PostgreSQL row-lock concurrency proofs.

Only after the root set is stable does an optional eligibility callback run
against the **fresh user and fresh resource queries**. Pruning still requires
the existing age, verification, no-envelope, and empty-solo-profile criteria;
none of those retention rules change. A newly verified account or newly
created financial data is not deleted based on the earlier candidate scan.

Root-set expansion has three bounded attempts, each with full rollback.
Database deadlocks use the outer production transaction's three-attempt retry
boundary. Exhaustion fails without partial account deletion or blacklisting.
Both production entry points enter this boundary without pre-acquired roots.
Tests' encompassing fixture transactions are not production entry points.

Invite acceptance follows profile → fresh user → invite, never invite →
profile. Invite creation/replacement locks and reauthorizes the fresh profile
before checking membership or locking previous invites, with whole-transaction
deadlock retries. Reserved completion discovers the union of membership/reservation
profiles, locks those profiles in ID order, then the fresh verified user and
invite. It checks profile-set closure before consuming or granting anything
and retries a changed set from the start. Existing membership profiles must
also be locked before user/default-profile work. Expiry, reservation, email,
membership and verification are rechecked from fresh data after waiting.

## Key freshness after a wait

A row lock does not invalidate `AmountKeyRing`'s request/job memo. A writer
that cached v1 before waiting through rotation/pruning must not encrypt a new
value with cached v1 after its database key row was pruned.

- After obtaining a ledger serialization lock, manual transaction writers
  forget that profile's key memo before encrypted persistence.
- After obtaining a draft-budget serialization lock, assignment writers do
  the same. Budget creation/cloning/publication flush beneath the profile root.
- New ledger creation takes a **profile share lock before preparing the
  INSERT ciphertext**, rechecks management permission, and flushes the memo.
  Waiting only on the INSERT's eventual FK check would be too late.
- Key maintenance flushes beneath its complete root set on every attempt.
- Envelope schedule saves already flush their envelope key memo beneath the
  envelope lock. Future reconciliation must flush profile keys after its
  ledger lock and envelope keys after its envelope locks, on every attempt.

Amounts still pass through encrypted Eloquent persistence; this is not
permission to write money in SQL. Internal callers adding new money-writing
paths must acquire the relevant serialization root and refresh key material,
not merely rely on a scoped ring or transaction isolation.

## Verification

`FinancialLockProtocolTest` covers root order, external/pending references,
closure rollback/retry/exhaustion, fresh account metadata, conditional prune
checks, profile ownership/membership, and primed stale-key writes through the
real transaction, ledger, assignment, and budget-clone APIs.

`PostgresFinancialLockProtocolTest` uses a private generated schema in an
explicitly disposable test database. Two independent connections verify
blocked FK insertion/budget mutation, an external reference that survives the
first attempt's rollback and appears in rediscovery, and the actual ledger
deletion method's ledger-before-child order. It runs in the existing
PostgreSQL release gate and skips locally when the driver/server is absent.

```bash
php artisan test --filter='FinancialLockProtocolTest|AccountDeletionTest|UnverifiedAccountsTest|AmountKeyCommandsTest|SharingLifecycleTest'
composer ci:check
```
