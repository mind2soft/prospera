<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnvelopeScheduleRequest;
use App\Models\Envelope;
use App\Models\EnvelopeSchedule;
use App\Services\Schedules\ScheduleOccurrencePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Definition management only. Automatic ledger posting/reminders are not enabled yet. */
class EnvelopeScheduleController extends Controller
{
    public function __construct(private readonly ScheduleOccurrencePreview $preview) {}

    public function index(EnvelopeScheduleRequest $request, Envelope $envelope): JsonResponse
    {
        return DB::transaction(function () use ($request, $envelope): JsonResponse {
            $locked = $this->lockedEnvelope($envelope, readOnly: true);
            $page = $locked->schedules()->orderBy('id')->paginate($request->integer('per_page', 50), ['*'], 'page', $request->integer('page', 1));
            $today = $request->calendar()->today()->toDateString();

            return response()->json([
                'schedules' => $page->getCollection()->map(fn (EnvelopeSchedule $schedule): array => $this->serialize($schedule, $today))->all(),
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

            return response()->json(['schedule' => $this->serialize($schedule, $request->calendar()->today()->toDateString())], 201);
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
            }

            return response()->json(['schedule' => $this->serialize($fresh, $request->calendar()->today()->toDateString())]);
        });
    }

    public function destroy(EnvelopeScheduleRequest $request, Envelope $envelope, EnvelopeSchedule $schedule): Response
    {
        return DB::transaction(function () use ($envelope, $schedule): Response {
            $locked = $this->lockedEnvelope($envelope);
            $locked->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail()->delete();

            return response()->noContent();
        });
    }

    private function lockedEnvelope(Envelope $envelope, bool $readOnly = false): Envelope
    {
        $query = Envelope::query()->whereKey($envelope->id);
        $locked = ($readOnly ? $query->sharedLock() : $query->lockForUpdate())->firstOrFail();
        // A transfer may have committed after FormRequest authorization.
        $this->authorize('manageSchedules', $locked);

        return $locked;
    }

    /** @return array<string, mixed> */
    private function serialize(EnvelopeSchedule $schedule, string $today): array
    {
        return [
            'id' => $schedule->id, 'envelope_id' => $schedule->envelope_id,
            'amount' => $schedule->amount, 'period' => $schedule->period,
            'start_date' => $schedule->start_date, 'end_date' => $schedule->end_date,
            'weekdays' => $schedule->weekdays, 'day_of_month' => $schedule->day_of_month,
            'month' => $schedule->month, 'reminder_days' => $schedule->reminder_days,
            'created_at' => $schedule->created_at?->toISOString(), 'updated_at' => $schedule->updated_at?->toISOString(),
            'next_occurrences' => $this->preview->next($schedule->rule(), $today),
        ];
    }
}
