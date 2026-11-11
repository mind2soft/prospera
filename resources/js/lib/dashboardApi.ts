import { api } from '@/lib/api';
import * as widgets from '@/routes/api/dashboard/widgets';
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
        api(widgets.index(profileId)),

    save: (
        profileId: number,
        input: SaveLayoutInput,
    ): Promise<{ widgets: DashboardWidget[] }> =>
        api(widgets.save(profileId), { body: input }),

    remove: (profileId: number, widgetId: number): Promise<void> =>
        api(widgets.destroy({ profile: profileId, widget: widgetId })),

    data: (profileId: number, widgetId: number): Promise<DashboardWidgetData> =>
        api(widgets.data({ profile: profileId, widget: widgetId })),
};
