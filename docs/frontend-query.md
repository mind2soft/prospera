# Frontend query and mutation data

Prospera uses TanStack Vue Query for server state. `useQuery` from
`resources/js/composables/query.ts` remains disabled during SSR; mutations use
the same cache on the client. Use feature query-key factories instead of
repeating keys in components.

## Optimistic mutations

Use `useOptimisticMutation` from the shared query module only when the full
temporary cache state can be derived from the current raw cache and mutation
variables. Ordinary `useMutation` calls remain unchanged and are still the
right choice when the result depends on server-generated fields, permission
masking, revision protocols, undo behavior, or other state the client cannot
predict.

```ts
return useOptimisticMutation({
    mutationFn: ({ profileId, name }: { profileId: number; name: string }) =>
        profileApi.rename(profileId, name),
    optimistic: {
        targets: [
            {
                queryKey: profileKeys.all,
                update: (
                    previous: { profiles: FinancialProfile[] } | undefined,
                    { profileId, name },
                ) =>
                    previous === undefined
                        ? undefined
                        : {
                              ...previous,
                              profiles: previous.profiles.map((profile) =>
                                  profile.id === profileId
                                      ? { ...profile, name }
                                      : profile,
                              ),
                          },
            },
        ],
        reconcile: () => [
            {
                queryKey: profileKeys.all,
                update: (
                    previous: { profiles: FinancialProfile[] } | undefined,
                    _variables,
                    canonical,
                ) =>
                    previous === undefined || canonical === undefined
                        ? undefined
                        : {
                              ...previous,
                              profiles: previous.profiles.map((profile) =>
                                  profile.id === canonical.profile.id
                                      ? canonical.profile
                                      : profile,
                              ),
                          },
            },
        ],
    },
});
```

`targets` is a list or a function of mutation variables. Each target is an exact
query key by default; set `exact: false` to update every currently cached key
under a query-key prefix. The updater receives the **raw cache value**, not a
query's `select` projection. It must return a new immutable value. Returning
`undefined` leaves that cache entry unchanged; it does not create data for an
absent entry. A successful exact target can be seeded by an explicit
`reconcile` updater. Without a reconcile updater, the target filter is
invalidated/refetched after success.

Set `invalidateOnSuccess: false` when the mutation's own `onSuccess` already
invalidates the relevant key(s), for example when it also refreshes related
dashboard or ledger data. Leave the default when the helper should own
invalidation. Set it to `true` to request invalidation even when a reconcile
updater is present.

### Lifecycle ordering

The helper composes rather than replaces mutation-level callbacks, preserving
the caller's variables, `onMutate` result, and TanStack mutation-function
context:

1. Await cancellation of affected queries, snapshot each cached entry and
   apply the optimistic updates.
2. Await the caller's `onMutate`; its returned context is preserved.
3. On success, reconcile or invalidate first, then await the caller's
   `onSuccess`.
4. On failure, roll back first, then await the caller's `onError`; the original
   mutation error is not swallowed.
5. Run the caller's `onSettled` last.

Per-call callbacks passed to `mutate`/`mutateAsync` retain the caller's
`onMutate` context. They run after the mutation-level callbacks, following
TanStack Query's normal per-call callback behavior.

### Overlapping updates

Operations against the same concrete cache key are kept in an ordered replay
stack over the initial snapshot. A failed operation is removed and the
remaining operations are replayed, so an earlier failure cannot erase a later
pending or successful optimistic change. Once no operation for that key is
pending, if at least one succeeded, the most recently successful response is
reconciled when configured; otherwise the affected filter is invalidated. If
every operation failed, the original query state is restored without
invalidation. Different keys have independent snapshots.

The updater should be replay-safe and deterministic: derive the replacement
from its `previous` value and variables, rather than mutating a closed-over
object or appending non-idempotent side effects.

## Current migration and exclusions

Optimistic updates are enabled for profile rename, default-profile selection,
invite revocation, ledger deletion, transaction-note edits, budget deletion,
budget-envelope removal, and schedule deletion. Each is a deterministic list
or field change and still rolls back on server rejection.

Other mutation composables intentionally remain non-optimistic:

- **Create operations** (`useCreateProfile`, `useCreateLedger`,
  `useCreateBudget`, `useAddTransaction`, and `useCreateInvite`) rely on
  server-generated IDs, timestamps, permissions, or response fields.
- **Budget publication, cloning, envelope additions/edits, and ownership or
  share changes** (`usePublishBudget`, `useCloneBudget`, `useAddEnvelope`,
  `useUpdateEnvelope`, `useTransferBudgetOwnership`, `useSetBudgetShare`, and
  `useRemoveBudgetShare`) depend on server publication state, generated budget
  data, envelope revisions, save-queue ordering, or permissions. In
  particular, `budgetSaveQueue` and expected-revision handling remain
  authoritative. Budget deletion and budget-envelope removal are migrated.
- **Ledger close/reopen, transaction creation/deletion/restore, and sharing**
  (`useCloseLedger`, `useReopenLedger`, `useAddTransaction`,
  `useDeleteTransaction`, `useRestoreTransaction`, `useSetLedgerShare`,
  `useRemoveLedgerShare`, and `useTransferLedgerOwnership`) depend on
  server status, schedule reconciliation, undo-window state, generated report
  values, or permissions. Undoable transaction deletion must remain a
  server-backed protocol.
- **Profile creation/deletion, membership removal, ownership transfer, and
  invite creation/acceptance** (`useCreateProfile`, `useDeleteProfile`,
  `useRemoveMember`, `useTransferProfileOwnership`, `useCreateInvite`, and
  `useAcceptInvite`) change relationships or related defaults across multiple
  caches; broad existing invalidation is safer than guessing all effects.
- **Envelope sharing and ownership transfer** (`useSetEnvelopeShare`,
  `useRemoveEnvelopeShare`, and `useTransferEnvelopeOwnership`) affect
  effective permission and masking data. Do not optimistically alter
  access-control state.
- **Schedule creation/editing** returns timezone-aware previews and may rewrite
  generated ledger entries (`useSaveSchedule`). Schedule deletion only removes
  the known list row; its success still invalidates reports and dashboard
  aggregates.
- **Dashboard layout save/removal** (`useSaveDashboardLayout` and
  `useRemoveDashboardWidget`) uses revision checks, autosave queues, and
  conflict handling. Those concurrency protocols remain authoritative.
- **Notifications settings and contact submission** do not update a cached
  server-state query; their existing form/submission flows remain unchanged.

When adding a migration, document why the entire temporary value is
predictable, include rollback and overlap tests where relevant, and preserve
feature-specific invalidation and callbacks.
