import { api } from '@/lib/api';
import { browserTimeZone } from '@/lib/calendar';
import type { UnloadOptions } from '@/lib/unloadTransport';
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
        transport: UnloadOptions = {},
    ): Promise<DashboardLayoutData> =>
        // Only an unload-time flush (a `pagehide` save) may ride keepalive,
        // and even then only while the body fits the browser's keepalive
        // budget (lib/api.ts, issue #54): ordinary saves use a normal
        // request, and an oversized layout is never sent keepalive.
        api(widgets.save(profileId), {
            body: input,
            unload: transport.unload === true,
        }),

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
