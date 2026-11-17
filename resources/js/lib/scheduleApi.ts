import { api } from '@/lib/api';
import { browserTimeZone } from '@/lib/calendar';
import * as schedules from '@/routes/api/envelopes/schedules';
import type { EnvelopeSchedule, SchedulePeriod } from '@/types';

/**
 * A complete schedule definition. `amount` is the unsigned minor-unit
 * magnitude: the server signs it from the envelope direction. Fields the
 * period does not use are sent empty (`[]` / `null`).
 */
export type ScheduleInput = {
    amount: number;
    period: SchedulePeriod;
    start_date: string;
    end_date: string | null;
    weekdays: number[];
    day_of_month: number | null;
    month: number | null;
    reminder_days: number | null;
};

type SchedulePage = {
    schedules: EnvelopeSchedule[];
    meta: { current_page: number; last_page: number };
};

// The API's maximum page size; an envelope rarely has more than a handful.
const PER_PAGE = 100;

export const scheduleApi = {
    /** Every schedule of the envelope, previews in the user's calendar. */
    async list(envelopeId: number): Promise<EnvelopeSchedule[]> {
        const all: EnvelopeSchedule[] = [];
        for (let page = 1; ; page += 1) {
            const result = await api<SchedulePage>(
                schedules.index(envelopeId, {
                    query: {
                        page,
                        per_page: PER_PAGE,
                        timezone: browserTimeZone(),
                    },
                }),
            );
            all.push(...result.schedules);
            if (result.meta.current_page >= result.meta.last_page) {
                return all;
            }
        }
    },

    create: (
        envelopeId: number,
        input: ScheduleInput,
    ): Promise<{ schedule: EnvelopeSchedule }> =>
        api(schedules.store(envelopeId), {
            body: { ...input, timezone: browserTimeZone() },
        }),

    update: (
        envelopeId: number,
        scheduleId: number,
        input: ScheduleInput,
    ): Promise<{ schedule: EnvelopeSchedule }> =>
        api(schedules.update({ envelope: envelopeId, schedule: scheduleId }), {
            body: { ...input, timezone: browserTimeZone() },
        }),

    destroy: (envelopeId: number, scheduleId: number): Promise<void> =>
        api(schedules.destroy({ envelope: envelopeId, schedule: scheduleId })),
};
