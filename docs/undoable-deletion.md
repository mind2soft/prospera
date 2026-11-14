# Undoable deletion

Some deletions are easy to trigger by mistake and costly to redo. For those,
the record is **soft-deleted** first: it disappears from the app at once, stays
restorable for a short **undo window**, and is then **purged** for good. The
user gets an **Undo** button on the toast that confirms the deletion.

Transactions are the first resource to use this pattern (issue #48). This page
describes the pattern so other resources can use it too, and lists the rules
that keep it consistent.

## Transactions

| Setting                   | Value                                                            |
| ------------------------- | ---------------------------------------------------------------- |
| Undo window (server)      | `Transaction::UNDO_WINDOW_SECONDS` = **3 minutes**               |
| Undo toast (client)       | **30 seconds** (`UNDO_TOAST_DURATION_MS` in `pages/Ledger.vue`)  |
| Purge                     | `php artisan transactions:purge-deleted`, scheduled every minute |
| Restore endpoint          | `POST /api/transactions/{transaction}/restore`                   |
| Who may delete or restore | The same rules ([Ledger → Permissions](ledger.md#permissions))   |

Workflow:

1. **Delete.** After the user confirms, `DELETE /api/transactions/{id}` sets
   `transactions.deleted_at`. From then on, the row is excluded from every
   Eloquent query: the ledger list, the totals and "remaining allocated", the
   dashboard aggregates, the onboarding progress and the envelope reference
   checks.
2. **Undo.** The success toast stays up for 30 seconds and has an **Undo**
   action. It calls the restore endpoint, which clears `deleted_at`. The
   transaction comes back unchanged: same ID, amount, date and author.
3. **Purge.** Every minute, `transactions:purge-deleted` permanently deletes
   the rows deleted more than 3 minutes ago. Depending on when the scheduler
   runs, a row is purged between 3 and 4 minutes after it was deleted.

The server window is longer than the toast, so an Undo clicked at the last
second still works on a slow connection. The server alone decides whether a
restore is allowed. It does not depend on the purge having run: past the
window, a restore is refused with `422`, even if the row still exists.

Restore responses:

| Case                                                  | Response                                 |
| ----------------------------------------------------- | ---------------------------------------- |
| Pending deletion, within the window                   | `204`, restored                          |
| Not deleted (for example, a double click on Undo)     | `204`, no change                         |
| Past the window                                       | `422` on `transaction`                   |
| Ledger closed in the meantime                         | `422` on `ledger` (closed is read-only)  |
| Author lost contributor access to the envelope        | `422` on `envelope_id`                   |
| Already purged                                        | `404` (the UI says it can no longer be restored) |
| Caller may not delete it (viewer, others' entries)    | `403`, or `404` without ledger access    |

## Rules for any soft-deleted resource

Soft deletion keeps rows in tables where the rest of the code assumes they are
gone. Every new use of the pattern must check the following:

- **Route binding.** Only the restore route resolves trashed rows
  (`->withTrashed()` on the route). Every other route returns `404` for a
  pending deletion, as if it were already gone.
- **Raw queries.** The `SoftDeletes` global scope only covers Eloquent. Every
  `DB::table()` query on the table needs `whereNull('deleted_at')`. For
  transactions, this is the referencing-profile lookup in
  `App\Actions\DeleteAccount`.
- **Existence checks that drive decisions.** Eloquent relationship checks
  (`whereHas`, `exists()`) now ignore pending deletions. When such a check
  decides ownership or access, a row restored afterwards can contradict the
  decision. Purge the pending rows the decision depends on first. For
  transactions: when a member leaves a profile (or is removed, or deletes
  their account), `FinancialProfile::removeMember()` purges the pending
  deletions in the profile's ledgers on the member's envelopes. It does this
  before it hands the envelopes still in use over to the profile owner and
  prunes the envelope grants.
- **Encryption at rest.** Key rotation, key pruning and `amounts:verify` must
  include pending deletions (`withTrashed()` in `AmountKeyManager::sources()`).
  Otherwise, a key version could be pruned while a restorable row still uses
  it, and the row would no longer decrypt once restored
  ([Encryption](encryption.md)).
- **Restrictive foreign keys.** A pending deletion must never block the parent
  that the user sees as unused. Purge the pending rows in the same database
  transaction before deleting the parent: deleting an envelope, or deleting an
  account and the envelopes that go with it.
- **Invariants a restore would break.** Purge the pending rows when a change
  would make them invalid once restored. Changing an envelope's direction
  purges its pending transaction deletions, because their stored sign follows
  the old direction ([Envelope → Integrity rules](envelope.md#integrity-rules)).
  The restore reads the envelope under a shared lock, so it cannot race that
  purge.
- **Cascading deletes.** A parent deleted on purpose has nothing left to undo.
  Deleting a ledger force-deletes its transactions, pending deletions included.
- **Re-check on restore.** A restore is a write. It re-runs the delete
  authorization and the read-only checks, such as a closed ledger. Authors
  restoring their own entry must still be allowed to record it, so they need
  contributor+ on the envelope.
- **Privacy.** Pending deletions are never listed or exported, and the purge
  removes them for good within minutes. They are not a recycle bin.

## Frontend

- The deletion confirmation dialog stays in place. The undo is a second safety
  net and does not replace it.
- The undo toast uses vue-sonner's `action` (`toast.success(message,
  { duration, action })`).
- The toaster is app-wide, so the toast can outlive the page that raised it.
  TanStack Query only runs the callbacks passed to `mutate()` while the
  component is mounted. Put the restore feedback (toast, cache invalidation)
  in the mutation-level `onSuccess` and `onError`, as `useRestoreTransaction`
  does, so the feedback still appears after the user navigates away.
- On success, invalidate the same queries as the deletion: transactions,
  onboarding progress and dashboard data.
- vue-sonner pauses the toast timer on hover and in hidden tabs, so the Undo
  button can outlive the server window. A `404` (already purged) shows "This
  transaction can no longer be restored."; a `422` shows the server's message.
