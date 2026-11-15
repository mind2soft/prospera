import { api } from '@/lib/api';
import { browserTimeZone } from '@/lib/calendar';
import * as widgets from '@/routes/api/dashboard/widgets';
import type {
    DashboardLayoutData,
    DashboardWidgetData,
    DashboardWidgetPayload,
} from '@/types';

export type SaveLayoutInput = {
    widgets: DashboardWidgetPayload[];
    /** The layout revision the payload was captured against (issue #55). */
    expected_revision: number;
};

export const dashboardApi = {
    widgets: (profileId: number): Promise<DashboardLayoutData> =>
        api(widgets.index(profileId)),

    save: (
        profileId: number,
        input: SaveLayoutInput,
    ): Promise<DashboardLayoutData> =>
        // keepalive: the dashboard flushes its autosave while unloading.
        api(widgets.save(profileId), { body: input, keepalive: true }),

    /** The removal is a layout write: it carries and returns the revision (issue #55). */
    remove: (
        profileId: number,
        widgetId: number,
        expectedRevision: number,
    ): Promise<{ revision: number }> =>
        api(
            widgets.destroy(
                { profile: profileId, widget: widgetId },
                { query: { expected_revision: expectedRevision } },
            ),
        ),

    /** "Today" is the browser's day: the request carries its time zone. */
    data: (profileId: number, widgetId: number): Promise<DashboardWidgetData> =>
        api(
            widgets.data(
                { profile: profileId, widget: widgetId },
                { query: { timezone: browserTimeZone() } },
            ),
        ),
};
