import { api } from '@/lib/api';
import type {
    DashboardWidget,
    DashboardWidgetConfig,
    DashboardWidgetData,
} from '@/types';

export type SaveLayoutInput = {
    widgets: DashboardWidgetConfig[];
};

export const dashboardApi = {
    widgets: (profileId: number): Promise<{ widgets: DashboardWidget[] }> =>
        api(`/api/dashboard/${profileId}/widgets`),

    save: (
        profileId: number,
        input: SaveLayoutInput,
    ): Promise<{ widgets: DashboardWidget[] }> =>
        api(`/api/dashboard/${profileId}/widgets`, {
            method: 'PUT',
            body: input,
        }),

    remove: (profileId: number, widgetId: number): Promise<void> =>
        api(`/api/dashboard/${profileId}/widgets/${widgetId}`, {
            method: 'DELETE',
        }),

    data: (profileId: number, widgetId: number): Promise<DashboardWidgetData> =>
        api(`/api/dashboard/${profileId}/widgets/${widgetId}/data`),
};
