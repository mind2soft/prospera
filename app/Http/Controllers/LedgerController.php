<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesShares;
use App\Http\Requests\Ledger\LedgerTransactionsRequest;
use App\Http\Requests\Ledger\StoreLedgerRequest;
use App\Http\Requests\Ledger\StoreTransactionRequest;
use App\Http\Requests\Ledger\TransactionNoteSuggestionsRequest;
use App\Jobs\CheckEnvelopeLimit;
use App\Jobs\ReconcileLedgerSchedules;
use App\Mail\ContributorTransaction;
use App\Models\Budget;
use App\Models\BudgetEnvelope;
use App\Models\Envelope;
use App\Models\FinancialProfile;
use App\Models\Ledger;
use App\Models\LedgerPermission;
use App\Models\Transaction;
use App\Models\User;
use App\Security\Amounts\AmountKeyRing;
use App\Services\OnboardingProgress;
use App\Services\ResourcePermissions;
use App\Services\ResourcePermissionService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use LogicException;

class LedgerController extends Controller
{
    /** @use ManagesShares<Ledger> */
    use ManagesShares;

    public function __construct(
        private readonly ResourcePermissionService $permissionService,
    ) {}

    /**
     * List a profile's ledgers, newest first. The current profile is tracked
     * client-side, so callers pass `profile_id`; without it, the ledgers of
     * all the caller's profiles are listed.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($request->filled('profile_id')) {
            $this->authorizeProfileId((int) $request->query('profile_id'));

            $query = Ledger::query()->where('profile_id', (int) $request->query('profile_id'));
        } else {
            $query = Ledger::query()->visibleTo($user);
        }

        // Only the caller's share row resolves their permission.
        $ledgers = $query
            ->with(['permissions' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'ledgers' => $ledgers
                // Both branches are scoped to the caller's profiles.
                ->map(fn (Ledger $ledger) => $this->serializeLedger($ledger, $user, isMember: true))
                ->values(),
        ]);
    }

    /**
     * Create a ledger in the given profile (profile owner only; the creator
     * becomes the ledger owner).
     * The starting balance payload is always a positive integer;
     * starting_balance_type selects the stored sign (cash | debt).
     */
    public function store(StoreLedgerRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $profile = $request->profile();
        $user = $this->currentUser($request);

        if (! app(OnboardingProgress::class)->forProfile($profile)['can_create_ledger']) {
            throw ValidationException::withMessages([
                'profile_id' => __('Create at least one revenue envelope and three expense envelopes before creating a ledger.'),
            ]);
        }

        $ledger = DB::transaction(function () use ($user, $profile, $validated): Ledger {
            // Serialize key selection with rotation/pruning before preparing
            // the INSERT ciphertext; waiting at the later FK check is too late.
            $lockedProfile = FinancialProfile::query()->whereKey($profile->id)->sharedLock()->firstOrFail();
            $this->authorize('manage', $lockedProfile);
            app(AmountKeyRing::class)->forget($lockedProfile->id);

            $ledger = Ledger::create([
                'profile_id' => $profile->id,
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'starting_balance' => $validated['starting_balance_type'] === 'debt'
                    ? -$validated['starting_balance']
                    : $validated['starting_balance'],
            ]);

            $ledger->permissions()->create([
                'user_id' => $user->id,
                'permission' => ResourcePermissions::OWNER,
            ]);

            // Materialize covered schedule occurrences once committed.
            dispatch(new ReconcileLedgerSchedules($ledger->id));

            return $ledger;
        });

        return response()->json([
            'ledger' => $this->serializeLedger($ledger, $user),
        ], 201);
    }

    /**
     * Delete a ledger and ALL of its transactions (cascade). The deletion
     * takes the ledger's serialization lock, consistent with the other
     * ledger state changes (docs/ledger.md).
     */
    public function destroy(Ledger $ledger): JsonResponse
    {
        $this->authorize('delete', $ledger);

        DB::transaction(function () use ($ledger): void {
            $this->lockLedger($ledger->id)->deleteWithTransactions();
        });

        return response()->json(status: 204);
    }

    /**
     * Close a ledger: it becomes read-only.
     *
     * Close serializes on the same ledger lock as transaction writers: a
     * write in flight commits before the close is applied, and a close
     * committed first rejects subsequent writes (docs/ledger.md).
     */
    public function close(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize('update', $ledger);
        $user = $this->currentUser($request);

        $locked = DB::transaction(function () use ($ledger): Ledger {
            $locked = $this->lockLedger($ledger->id);

            $locked->update(['closed_at' => now()]);

            return $locked;
        });

        return response()->json([
            'ledger' => $this->serializeLedger($locked, $user),
        ]);
    }

    /**
     * Re-open a closed ledger. Re-opening takes the same serialization lock
     * as close and the transaction writes (docs/ledger.md).
     */
    public function reopen(Request $request, Ledger $ledger): JsonResponse
    {
        $this->authorize('update', $ledger);
        $user = $this->currentUser($request);

        $locked = DB::transaction(function () use ($ledger): Ledger {
            $locked = $this->lockLedger($ledger->id);

            $locked->update(['closed_at' => null]);

            // Reconcile what changed while closed (deleted schedules, coverage).
            dispatch(new ReconcileLedgerSchedules($locked->id));

            return $locked;
        });

        return response()->json([
            'ledger' => $this->serializeLedger($locked, $user),
        ]);
    }

    /**
     * Transactions for the ledger within a from_date/to_date range. Rows on
     * envelopes the requester lacks envelope-viewer+ permission on are
     * masked server-side: amount null + masked true (metadata only).
     */
    public function transactions(LedgerTransactionsRequest $request, Ledger $ledger): JsonResponse
    {
        $validated = $request->validated();

        $from = Carbon::parse($validated['from_date'])->startOfDay();
        $to = Carbon::parse($validated['to_date'])->endOfDay();

        $user = $this->currentUser($request);

        // The ledger's profile is known: no lookup query when decrypting.
        app(AmountKeyRing::class)->rememberLedgerProfiles([$ledger->id], $ledger->profile_id);

        $transactions = $ledger->transactions()
            ->with([
                // The requester's share rows resolve the masking below
                // without a second envelopes query.
                'envelope.permissions' => fn ($query) => $query->where('user_id', $user->id),
                'user',
            ])
            ->whereBetween('event_at', [$from, $to])
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        // "Remaining allocated" is a per-month concept (docs/ledger.md —
        // Allocation reporting): each row is measured against the published
        // budget of its own month, minus the transactions recorded against
        // that envelope within that whole calendar month. Envelopes without
        // an allocation (or months without a published budget) report as
        // unallocated.
        //
        // Exact minor-unit totals per (month, envelope), computed in PHP —
        // amounts are encrypted at rest (docs/encryption.md). Months fully
        // covered by the queried range reuse the rows loaded above; a
        // partial month is completed with one bounded query.
        $monthlyTotals = $this->monthlyTotals($ledger, $transactions, $from, $to);

        $months = $transactions
            ->map(fn (Transaction $transaction): string => $transaction->event_at->format('Y-m'))
            ->unique()
            ->values();

        $allocations = $this->monthlyAllocations($ledger->profile_id, $months, $transactions
            ->map(fn (Transaction $transaction): int => $transaction->envelope_id)
            ->unique()
            ->values());

        // Resolved once for every row's permission check (stale grants).
        $coMemberIds = FinancialProfile::coMemberIdsOf($user->id);

        return response()->json([
            'transactions' => $transactions
                ->map(function (Transaction $transaction) use ($user, $coMemberIds, $allocations, $monthlyTotals) {
                    // The envelope_id FK restricts deletes: a persisted
                    // transaction always has its envelope — a missing one
                    // is an invariant breach (same convention as
                    // serializeTransaction below).
                    $envelope = $transaction->envelope;

                    if ($envelope === null) {
                        throw new LogicException('The transaction has no envelope.');
                    }

                    return $this->serializeTransaction(
                        $transaction,
                        $envelope->permissionFor($user, $coMemberIds),
                        $this->remainingAllocated($transaction, $allocations, $monthlyTotals),
                    );
                })
                ->values(),
        ]);
    }

    /**
     * Exact minor-unit totals per envelope, accumulated in PHP over each
     * returned row's full calendar month (docs/ledger.md — Allocation
     * reporting). Months entirely covered by the queried range are summed
     * from the already-loaded rows; a month only partially covered by the
     * range is completed with one bounded query — the encrypted amounts
     * need their context columns (ledger_id, envelope_id, event_at).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<string, Collection<string, int>> totals keyed by
     *                                                     "Y-m", then envelope id
     */
    private function monthlyTotals(Ledger $ledger, Collection $transactions, Carbon $from, Carbon $to): Collection
    {
        $totals = collect();
        $partialMonths = collect();

        // Only the returned rows' envelopes are ever reported on.
        $envelopeIds = $transactions
            ->map(fn (Transaction $transaction): int => $transaction->envelope_id)
            ->unique()
            ->values();

        foreach ($transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->event_at->format('Y-m'))
            ->sortKeys() as $month => $rows) {
            $monthStart = Carbon::parse($month.'-01')->startOfDay();
            $monthEnd = (clone $monthStart)->endOfMonth()->endOfDay();

            if ($from->lte($monthStart) && $to->gte($monthEnd)) {
                $totals[$month] = $this->envelopeTotals($rows);
            } else {
                $partialMonths->push([$month, $monthStart, $monthEnd]);
            }
        }

        foreach ($partialMonths as [$month, $monthStart, $monthEnd]) {
            $totals[$month] = $this->envelopeTotals(
                $ledger->transactions()
                    ->select(['id', 'ledger_id', 'envelope_id', 'event_at', 'amount'])
                    ->whereIn('envelope_id', $envelopeIds->all())
                    ->whereBetween('event_at', [$monthStart, $monthEnd])
                    ->get(),
            );
        }

        return $totals;
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @return Collection<string, int>
     */
    private function envelopeTotals(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (Transaction $transaction): string => (string) $transaction->envelope_id)
            ->map(fn (Collection $group): int => $group->sum(
                fn (Transaction $transaction): int => Money::toMinorUnits($transaction->amount),
            ));
    }

    /**
     * Allocations from the published budgets of the given months, keyed by
     * "Y-m:envelope". A missing key means the envelope is unallocated for
     * that month (no published budget, or no allocation on it). The
     * decryption context of every budget is remembered up front.
     *
     * @param  Collection<int, non-falsy-string>  $months
     * @param  Collection<int, int>  $envelopeIds
     * @return Collection<string, int>
     */
    private function monthlyAllocations(int $profileId, Collection $months, Collection $envelopeIds): Collection
    {
        if ($months->isEmpty() || $envelopeIds->isEmpty()) {
            return collect();
        }

        $budgets = Budget::query()
            ->where('profile_id', $profileId)
            ->whereNotNull('published_at')
            ->where(function ($query) use ($months): void {
                // Months are canonical "Y-m" keys (see monthlyTotals).
                foreach ($months as $month) {
                    [$year, $monthNumber] = array_map(intval(...), explode('-', $month));

                    $query->orWhere(function ($query) use ($year, $monthNumber): void {
                        $query
                            ->where('effective_year', $year)
                            ->where('effective_month', $monthNumber);
                    });
                }
            })
            ->get()
            ->keyBy(fn (Budget $budget): string => sprintf(
                '%04d-%02d', $budget->effective_year, $budget->effective_month,
            ));

        if ($budgets->isNotEmpty()) {
            app(AmountKeyRing::class)
                ->rememberBudgetProfiles($budgets->pluck('id')->all(), $profileId);
        }

        // budget id → "Y-m" month key, resolved from the budgets above
        // (no per-allocation lazy load).
        $monthKeyByBudgetId = $budgets
            ->mapWithKeys(fn (Budget $budget, string $monthKey): array => [$budget->id => $monthKey]);

        return BudgetEnvelope::query()
            ->whereIn('budget_id', $budgets->pluck('id'))
            ->whereIn('envelope_id', $envelopeIds->all())
            ->get()
            ->mapWithKeys(fn (BudgetEnvelope $allocation): array => [
                $monthKeyByBudgetId[$allocation->budget_id].':'.$allocation->envelope_id => $allocation->amount,
            ]);
    }

    /**
     * The envelope's allocated amount on the row's month's published budget,
     * minus the transactions recorded against that envelope within that
     * month ("remaining allocated"). Null when the envelope has no
     * allocation for the month (or the month has no published budget).
     *
     * @param  Collection<string, int>  $allocations
     * @param  Collection<string, Collection<string, int>>  $monthlyTotals
     */
    private function remainingAllocated(
        Transaction $transaction,
        Collection $allocations,
        Collection $monthlyTotals,
    ): int|float|null {
        $allocation = $allocations->get(
            $transaction->event_at->format('Y-m').':'.$transaction->envelope_id,
        );

        if ($allocation === null) {
            return null;
        }

        return Money::fromMinorUnits(
            Money::toMinorUnits($allocation)
                - $monthlyTotals->get(
                    $transaction->event_at->format('Y-m'),
                    collect(),
                )->get((string) $transaction->envelope_id, 0),
        );
    }

    /**
     * Record a transaction against one of the user's envelopes — envelopes
     * are the only dependency; no budget is involved. Ledger owners and
     * contributors can record, but only against envelopes on which they are
     * also contributor (or owner). The amount is signed from the envelope's
     * direction.
     */
    public function addTransaction(StoreTransactionRequest $request, Ledger $ledger): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->currentUser($request);

        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }

        $envelope = Envelope::query()->find((int) $validated['envelope_id']);

        if ($envelope === null || $user->cannot('use', $envelope)) {
            throw ValidationException::withMessages([
                'envelope_id' => __('You need contributor access to this envelope to record transactions.'),
            ]);
        }

        $eventAt = Carbon::parse($validated['event_at'])->startOfDay();
        $note = isset($validated['note']) ? trim((string) $validated['note']) : null;
        $note = $note === '' ? null : $note;

        if (! $ledger->acceptsEventDate($eventAt)) {
            throw ValidationException::withMessages([
                'event_at' => __('Transactions can only be recorded between :start and :end.', [
                    'start' => $ledger->start_date->toDateString(),
                    'end' => $ledger->end_date->toDateString(),
                ]),
            ]);
        }

        $transaction = DB::transaction(function () use ($user, $ledger, $envelope, $validated, $eventAt, $note): Transaction {
            // The ledger's read-only state is re-read under the ledger's
            // serialization lock: the route-bound model is only an
            // authorization entry point, and a close committed in the
            // meantime must win over this write (docs/ledger.md).
            $lockedLedger = $this->lockLedger($ledger->id);
            $this->ensureOpen($lockedLedger);

            // The sign comes from the direction read under a shared lock, so
            // a concurrent direction change (blocked once the envelope is
            // referenced) cannot slip in between (docs/envelope.md). The
            // envelope lock is acquired after the ledger lock — the
            // documented lock order (docs/ledger.md).
            $direction = Envelope::query()->whereKey($envelope->id)->sharedLock()->firstOrFail();

            return $lockedLedger->transactions()->create([
                'user_id' => $user->id,
                'envelope_id' => $envelope->id,
                'amount' => $direction->signedAmount((float) $validated['amount']),
                'event_at' => $eventAt,
                'note' => $note,
            ])->setRelation('envelope', $direction);
        });

        // "Contributor transactions" notification (issue #51): a recording
        // by anyone but the ledger owner queues one email to the ledger's
        // current owner. Dispatched after the `DB::transaction` above
        // returned — i.e. after the commit — so a rolled-back insert never
        // notifies; the recipient, the ownership and every setting gate are
        // re-resolved at send time (App\Mail\ContributorTransaction).
        // Schedule-generated rows never pass through here: nothing was
        // recorded by "another user", so they send no such mail.
        $ownerId = LedgerPermission::query()
            ->where('ledger_id', $ledger->id)
            ->where('permission', LedgerPermission::PERMISSION_OWNER)
            ->value('user_id');

        if ($ownerId !== null && (int) $ownerId !== $transaction->user_id) {
            Mail::queue(new ContributorTransaction(
                transactionId: $transaction->id,
                ledgerId: $ledger->id,
            ));
        }

        // "Envelope limit exceeded" notification (issue #52): the
        // allocation-crossing check runs off the request path, in the
        // queue — dispatched here, after the `DB::transaction` above
        // returned (i.e. after the commit; the job is also marked
        // afterCommit), so a rolled-back insert never notifies and the
        // month's totals are read from committed rows only. The reusable
        // check itself lives in App\Services\EnvelopeLimitChecker — both
        // this endpoint and schedule reconciliation (per month/envelope
        // group) call it; revenue envelopes and every other precondition
        // are the checker's business, not the caller's.
        dispatch(new CheckEnvelopeLimit($transaction->id));

        return response()->json([
            'transaction' => $this->serializeTransaction(
                $transaction,
                // Contributor+ (the `use` check above): the amount is never
                // masked, so the permission is not resolved a second time.
                ResourcePermissions::CONTRIBUTOR,
                null,
                withRemaining: false,
            ),
        ], 201);
    }

    /**
     * Delete a transaction (open ledgers only; ledger owner: any; ledger
     * contributor: own entries only; viewers: none). The row is soft-deleted:
     * it disappears everywhere at once, but stays restorable during the undo
     * window before `transactions:purge-deleted` removes it for good
     * (docs/undoable-deletion.md).
     */
    public function destroyTransaction(Transaction $transaction): JsonResponse
    {
        $this->authorizeTransactionChange($transaction, 'delete');

        DB::transaction(function () use ($transaction): void {
            // Recheck the read-only state under the ledger's serialization
            // lock: the route-bound ledger may be stale (docs/ledger.md).
            $this->ensureOpen($this->lockLedger($transaction->ledger_id));

            $transaction->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * Undo a transaction deletion, within the undo window. Same rules as the
     * deletion; restoring an entry that is not deleted is a no-op. Authors
     * restoring their own entry must still be allowed to record against the
     * envelope, as if they entered it again.
     */
    public function restoreTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransactionChange($transaction, 'restore');
        $user = $this->currentUser($request);

        DB::transaction(function () use ($user, $transaction): void {
            // The ledger's read-only state is re-read under the ledger's
            // serialization lock before the restore proceeds
            // (docs/ledger.md).
            $lockedLedger = $this->lockLedger($transaction->ledger_id);
            $this->ensureOpen($lockedLedger);

            // The shared lock serializes with the envelope's deletion or
            // direction change, which purge its pending deletions: the row
            // is re-read under it (docs/undoable-deletion.md). The envelope
            // and transaction locks follow the ledger lock — the documented
            // lock order (docs/ledger.md).
            $envelope = Envelope::query()->whereKey($transaction->envelope_id)->sharedLock()->first();

            $pending = Transaction::onlyTrashed()->whereKey($transaction->id)->lockForUpdate()->first();

            if ($pending === null) {
                // Already restored, or purged in the meantime.
                abort_if(! Transaction::query()->whereKey($transaction->id)->exists(), 404);

                return;
            }

            if (! $pending->isRestorable()) {
                throw ValidationException::withMessages([
                    'transaction' => __('This transaction can no longer be restored.'),
                ]);
            }

            if ($pending->user_id === $user->id
                && ($envelope === null || $user->cannot('use', $envelope))) {
                throw ValidationException::withMessages([
                    'envelope_id' => __('You need contributor access to this envelope to record transactions.'),
                ]);
            }

            $pending->restore();
        });

        return response()->json(status: 204);
    }

    /** Update or clear the optional note on a manually recorded transaction. */
    public function updateTransactionNote(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransactionChange($transaction, 'update');
        $user = $this->currentUser($request);
        $this->authorizeTransactionNoteVisibility($transaction, $user);

        $validated = $request->validate([
            'note' => ['present', 'nullable', 'string', 'max:'.Transaction::NOTE_MAX_LENGTH],
        ]);
        $note = is_string($validated['note']) ? trim($validated['note']) : null;
        $note = $note === '' ? null : $note;
        $updated = DB::transaction(function () use ($transaction, $note, $user): Transaction {
            $ledger = $this->lockLedger($transaction->ledger_id);
            $this->ensureOpen($ledger);

            // Lock in the documented ledger → envelope → transaction order,
            // and recheck note visibility against the current envelope grant.
            $envelope = Envelope::query()
                ->whereKey($transaction->envelope_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureCanViewTransactionNote($envelope, $user);

            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->isGenerated()) {
                throw ValidationException::withMessages([
                    'transaction' => __('Scheduled transactions are managed by their schedule.'),
                ]);
            }

            $this->authorize('update', $locked);
            $locked->note = $note;
            $locked->save();

            return $locked;
        });

        $updated->loadMissing('envelope');
        $envelope = $updated->envelope;
        if ($envelope === null) {
            throw new LogicException('The transaction has no envelope.');
        }
        $permission = $envelope->permissionFor($user, FinancialProfile::coMemberIdsOf($user->id));

        return response()->json([
            'transaction' => $this->serializeTransaction($updated, $permission, null, withRemaining: false),
        ]);
    }

    /**
     * The most recent distinct notes recorded on an envelope across the
     * ledger's profile, offered as autocomplete while recording. The caller
     * must be able to record on the ledger and on the envelope; profile
     * members already see every ledger of the profile, and the note of a row
     * is visible to anyone who may use its envelope, so nothing is disclosed
     * beyond what the ledger view shows. Notes are encrypted at rest, so the
     * newest noted rows are decrypted (bounded) and deduplicated in PHP.
     */
    public function transactionNoteSuggestions(TransactionNoteSuggestionsRequest $request, Ledger $ledger): JsonResponse
    {
        $user = $this->currentUser($request);
        $envelope = Envelope::query()->find((int) $request->validated('envelope_id'));

        if ($envelope === null || $user->cannot('use', $envelope)) {
            throw ValidationException::withMessages([
                'envelope_id' => __('You need contributor access to this envelope to record transactions.'),
            ]);
        }

        $notes = Transaction::query()
            ->whereIn('ledger_id', Ledger::query()->where('profile_id', $ledger->profile_id)->select('id'))
            ->where('envelope_id', $envelope->id)
            ->whereNull('schedule_id')
            ->whereNotNull('note')
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->limit(Transaction::NOTE_SUGGESTION_SCAN)
            ->get(['id', 'note'])
            ->map(fn (Transaction $transaction): string => trim((string) $transaction->note))
            ->filter(fn (string $note): bool => $note !== '')
            ->unique()
            ->take(Transaction::NOTE_SUGGESTIONS)
            ->values();

        return response()->json(['notes' => $notes]);
    }

    /**
     * Deleting and restoring entries: the ledger must be visible (404
     * otherwise) and open, then the transaction policy decides. The state
     * checks here are a fast path against the route-bound model; the
     * authoritative read-only recheck happens under the ledger's
     * serialization lock inside the write transaction (docs/ledger.md).
     */
    private function authorizeTransactionChange(Transaction $transaction, string $ability): void
    {
        $ledger = $transaction->ledger;

        // The ledger_id FK cascades: a persisted transaction always has its
        // ledger; a null one means the row is orphaned — invisible.
        abort_if($ledger === null, 404);

        $this->authorize('view', $ledger);

        $this->ensureOpen($ledger);

        // Generated rows are system-managed: edit the schedule instead.
        if ($transaction->isGenerated()) {
            throw ValidationException::withMessages([
                'transaction' => __('Scheduled transactions are managed by their schedule.'),
            ]);
        }

        $this->authorize($ability, $transaction);
    }

    private function authorizeTransactionNoteVisibility(Transaction $transaction, User $user): void
    {
        $envelope = $transaction->envelope;

        if ($envelope === null) {
            abort(404);
        }

        $this->ensureCanViewTransactionNote($envelope, $user);
    }

    private function ensureCanViewTransactionNote(Envelope $envelope, User $user): void
    {
        abort_if(
            $envelope->permissionFor($user, FinancialProfile::coMemberIdsOf($user->id)) === ResourcePermissions::NONE,
            403,
        );
    }

    /**
     * The ledger's serialization lock: every close/reopen and transaction
     * create/delete/restore acquires the ledger row lock first, then
     * re-reads the read-only state under it — the route-bound model is only
     * an ID and authorization entry point, not a state snapshot
     * (docs/ledger.md).
     *
     * Documented lock order, lowest first: ledger row (exclusive, here) →
     * envelope row (shared, direction changes and restore integrity) →
     * transaction row (exclusive, restore's pending re-read). Operations that
     * also need profile roots take those first, then prelock their complete
     * ledger/budget root sets before child mutations (financial-locking.md).
     */
    private function lockLedger(int $ledgerId): Ledger
    {
        $ledger = Ledger::query()->whereKey($ledgerId)->lockForUpdate()->firstOrFail();
        app(AmountKeyRing::class)->forget($ledger->profile_id);

        return $ledger;
    }

    /**
     * Rejects a write on a ledger that is closed as of the moment the
     * serialization lock is held (docs/ledger.md).
     */
    private function ensureOpen(Ledger $ledger): void
    {
        if ($ledger->isClosed()) {
            throw ValidationException::withMessages([
                'ledger' => __('This ledger is closed and read-only.'),
            ]);
        }
    }

    protected function sharedResource(Model $resource): Ledger
    {
        if (! $resource instanceof Ledger) {
            throw new LogicException('The share routes of this controller must bind a ledger.');
        }

        return $resource;
    }

    /**
     * @return array{string, string}
     */
    protected function shareTable(): array
    {
        return ['ledger_permissions', 'ledger_id'];
    }

    protected function viewSharesAbility(): string
    {
        return 'view';
    }

    /**
     * The profile's members with their permission on the ledger.
     *
     * @param  Ledger  $resource
     * @return array<int, array<string, int|string|bool>>
     */
    protected function shareList(Model $resource, User $caller): array
    {
        [$table, $column] = $this->shareTable();

        return $this->permissionService->profilePermissions($resource->profile, $table, $column, $resource->id);
    }

    /**
     * @param  Ledger  $resource
     */
    protected function shareTarget(Model $resource, int $userId): User
    {
        return $this->permissionService->profileMember($resource->profile, $userId);
    }

    protected function alreadySharedMessage(): string
    {
        return __('This ledger is already shared with this user.');
    }

    /**
     * Transfer ownership to a member; the previous owner becomes a
     * contributor.
     *
     * @param  Ledger  $resource
     * @return array<string, mixed>
     */
    protected function transferOwnershipOf(Model $resource, User $caller, int $userId): array
    {
        $target = $this->permissionService->ensureNotOwner(
            $this->permissionService->profileMember($resource->profile, $userId),
            $caller,
            __('You already own this ledger.'),
        );

        $resource->transferOwnershipTo($target);

        return ['ledger' => $this->serializeLedger($resource->refresh(), $caller)];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function serializeLedger(Ledger $ledger, User $user, ?bool $isMember = null): array
    {
        return [
            'id' => $ledger->id,
            'profile_id' => $ledger->profile_id,
            'name' => $ledger->name,
            'start_date' => $ledger->start_date->toDateString(),
            'end_date' => $ledger->end_date->toDateString(),
            'starting_balance' => $ledger->starting_balance,
            'closed_at' => $ledger->closed_at?->toISOString(),
            'status' => $ledger->isClosed() ? 'closed' : 'open',
            'created_at' => $ledger->created_at?->toISOString(),
            'permission' => $ledger->permissionFor($user, $isMember),
        ];
    }

    /**
     * Transaction payload with the envelope's display info and the entering
     * user ("Former member" for deleted users). Rows on envelopes the user
     * lacks envelope-viewer+ permission on are masked server-side.
     *
     * @return array<string, string|int|float|null|bool|array<string, string|int|float|null>>
     */
    private function serializeTransaction(
        Transaction $transaction,
        string $permission,
        int|float|null $remainingAllocated,
        bool $withRemaining = true,
    ): array {
        $transaction->loadMissing(['envelope', 'user']);

        // The envelope_id FK restricts deletes: a persisted transaction
        // always has its envelope.
        $envelope = $transaction->envelope;

        if ($envelope === null) {
            throw new LogicException('The transaction has no envelope.');
        }

        $masked = $permission === ResourcePermissions::NONE;

        $payload = [
            'id' => $transaction->id,
            'ledger_id' => $transaction->ledger_id,
            'envelope_id' => $transaction->envelope_id,
            // System-generated by a schedule: read-only, badge in the UI.
            'scheduled' => $transaction->isGenerated(),
            'name' => $envelope->name,
            'color' => $envelope->color,
            'direction' => $envelope->type,
            'amount' => $masked ? null : $transaction->amount,
            'masked' => $masked,
            'note' => $masked ? null : $transaction->note,
            'event_at' => $transaction->event_at->toDateString(),
            'created_at' => $transaction->created_at?->toISOString(),
            'user' => [
                'id' => $transaction->user_id,
                // user_id is nulled when the account is deleted
                // (nullOnDelete): "Former member" covers a null relation.
                'name' => $transaction->isGenerated()
                    ? __('System')
                    : ($transaction->user->name ?? __('Former member')),
            ],
        ];

        // remaining_allocated is a range-view concept; the create response
        // omits it.
        if ($withRemaining) {
            $payload['remaining_allocated'] = $masked ? null : $remainingAllocated;
        }

        return $payload;
    }
}
