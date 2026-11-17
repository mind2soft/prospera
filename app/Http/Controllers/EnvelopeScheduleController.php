<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnvelopeScheduleRequest;
use App\Jobs\ReconcileScheduleLedgers;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Services\Schedules\ScheduleDeletion;
use App\Services\Schedules\ScheduleOccurrencePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only schedule definitions. Writes queue reconciliation of the
 * affected open ledgers after commit; deletion removes the schedule's
 * open-ledger rows synchronously (docs/scheduled-transactions.md).
 * Reminders are sent by the daily `schedules:remind` run.
 */
class EnvelopeScheduleController extends Controller
{
    public function __construct(private readonly ScheduleOccurrencePreview $preview) {}

    public function index(EnvelopeScheduleRequest $request, Envelope $envelope): JsonResponse
    {
        return DB::transaction(function () use ($request, $envelope): JsonResponse {
            $locked = $this->lockedEnvelope($envelope, readOnly: true);
            $page = $locked->schedules()->orderBy('id')->paginate($request->integer('per_page', 50), ['*'], 'page', $request->integer('page', 1));
            $today = $request->calendar()->today()->toDateString();
            $entries = $this->openLedgerEntries($page->getCollection()->map(fn (EnvelopeSchedule $schedule): int => $schedule->id)->all());

            return response()->json([
                'schedules' => $page->getCollection()->map(fn (EnvelopeSchedule $schedule): array => $this->serialize($schedule, $today, $entries[$schedule->id] ?? 0))->all(),
                'meta' => [
                    'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(), 'total' => $page->total(),
                ],
            ]);
        });
    }

    public function store(EnvelopeScheduleRequest $request, Envelope $envelope): JsonResponse
    {
        return DB::transaction(function () use ($request, $envelope): JsonResponse {
            $locked = $this->lockedEnvelope($envelope);
            $schedule = $locked->schedules()->create($request->attributesFor($locked));
            dispatch(ReconcileScheduleLedgers::forEnvelope($locked->id));

            // Reconciliation runs after commit: nothing is generated yet.
            return response()->json(['schedule' => $this->serialize($schedule, $request->calendar()->today()->toDateString(), 0)], 201);
        });
    }

    public function update(EnvelopeScheduleRequest $request, Envelope $envelope, EnvelopeSchedule $schedule): JsonResponse
    {
        return DB::transaction(function () use ($request, $envelope, $schedule): JsonResponse {
            $locked = $this->lockedEnvelope($envelope);
            $fresh = $locked->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $attributes = $request->attributesFor($locked, $fresh);
            if ($attributes !== []) {
                $fresh->fill($attributes)->save();
                dispatch(ReconcileScheduleLedgers::forEnvelope($locked->id));
            }

            $entries = $this->openLedgerEntries([$fresh->id]);

            return response()->json(['schedule' => $this->serialize($fresh, $request->calendar()->today()->toDateString(), $entries[$fresh->id] ?? 0)]);
        });
    }

    public function destroy(EnvelopeScheduleRequest $request, Envelope $envelope, EnvelopeSchedule $schedule, ScheduleDeletion $deletion): Response
    {
        $deletion->delete($envelope->id, $schedule->id, fn (Envelope $locked) => $this->authorize('manageSchedules', $locked));

        return response()->noContent();
    }

    private function lockedEnvelope(Envelope $envelope, bool $readOnly = false): Envelope
    {
        $query = Envelope::query()->whereKey($envelope->id);
        $locked = ($readOnly ? $query->sharedLock() : $query->lockForUpdate())->firstOrFail();
        // A transfer may have committed after FormRequest authorization.
        $this->authorize('manageSchedules', $locked);

        return $locked;
    }

    /**
     * Generated rows per schedule in open ledgers — the entries an edit or a
     * deletion rewrites. A count of provenance ids only (no amounts), read
     * without ledger locks, hence approximate under concurrent
     * reconciliation; the UI presents it as "~N".
     *
     * @param  array<int, int>  $scheduleIds
     * @return array<int, int>
     */
    private function openLedgerEntries(array $scheduleIds): array
    {
        if ($scheduleIds === []) {
            return [];
        }

        return Transaction::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->whereIn('ledger_id', Ledger::query()->whereNull('closed_at')->select('id'))
            ->groupBy('schedule_id')
            ->selectRaw('schedule_id, count(*) as entries')
            ->pluck('entries', 'schedule_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(EnvelopeSchedule $schedule, string $today, int $openLedgerEntries): array
    {
        return [
            'id' => $schedule->id, 'envelope_id' => $schedule->envelope_id,
            'amount' => $schedule->amount, 'period' => $schedule->period,
            'start_date' => $schedule->start_date, 'end_date' => $schedule->end_date,
            'weekdays' => $schedule->weekdays, 'day_of_month' => $schedule->day_of_month,
            'month' => $schedule->month, 'reminder_days' => $schedule->reminder_days,
            'created_at' => $schedule->created_at?->toISOString(), 'updated_at' => $schedule->updated_at?->toISOString(),
            'next_occurrences' => $this->preview->next($schedule->rule(), $today),
            'open_ledger_entries' => $openLedgerEntries,
        ];
    }
}
