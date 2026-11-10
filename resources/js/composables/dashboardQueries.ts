import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { useQueryClient } from 'vue-query';
import { useMutation, useQuery } from '@/composables/query';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { dashboardApi } from '@/lib/dashboardApi';
import type { DashboardWidgetConfig, DashboardWidgetData } from '@/types';

export const dashboardKeys = {
    root: ['dashboard'] as const,
    widgets: (profileId: number) =>
        ['dashboard', profileId, 'widgets'] as const,
    data: (profileId: number, widgetId: number, config: string) =>
        ['dashboard', profileId, 'data', widgetId, config] as const,
};

/** Auto-refresh every 15 minutes, paused while the tab is hidden. */
const REFRESH_INTERVAL_MS = 15 * 60 * 1000;

const refreshInterval = (): number | false =>
    typeof document === 'undefined' || document.visibilityState === 'visible'
        ? REFRESH_INTERVAL_MS
        : false;

/** The caller's widgets for the current profile, in display order. */
export function useDashboardWidgets() {
    const { profileId } = useCurrentProfile();

    return useQuery({
        queryKey: computed(() =>
            dashboardKeys.widgets(toValue(profileId) ?? 0),
        ),
        queryFn: () => dashboardApi.widgets(toValue(profileId) ?? 0),
        enabled: computed(() => toValue(profileId) !== null),
        select: (data) => data.widgets,
    });
}

/**
 * Full layout save (the dashboard autosaves; there is no partial update).
 * The profile id is part of the mutation variables — resolving it in the
 * callbacks would let an in-flight save land on the wrong profile's cache
 * when the user switches profiles mid-request.
 *
 * The widget data endpoint computes from the SAVED configuration, so every
 * successful save invalidates the data queries — a config edit refetches
 * its data immediately (query key) but the response raced against the
 * pending autosave and must be re-fetched once the save landed.
 */
export function useSaveDashboardLayout() {
    const queryClient = useQueryClient();

    return useMutation(
        (input: { profileId: number; widgets: DashboardWidgetConfig[] }) =>
            dashboardApi.save(input.profileId, { widgets: input.widgets }),
        {
            onSuccess: (data, { profileId }) => {
                queryClient.setQueryData(
                    dashboardKeys.widgets(profileId),
                    data,
                );
                void queryClient.invalidateQueries({
                    queryKey: ['dashboard', profileId, 'data'],
                });
            },
        },
    );
}

export function useRemoveDashboardWidget() {
    const queryClient = useQueryClient();
    const { profileId } = useCurrentProfile();

    return useMutation(
        (widgetId: number) =>
            dashboardApi.remove(toValue(profileId) ?? 0, widgetId),
        {
            onSuccess: () => {
                void queryClient.invalidateQueries(
                    dashboardKeys.widgets(toValue(profileId) ?? 0),
                );
            },
        },
    );
}

/**
 * The server-computed aggregate for one widget. The configuration (period +
 * selection) is part of the query key, so editing the widget refetches
 * automatically; the endpoint is polled every 15 minutes while the tab is
 * visible.
 */
export function useWidgetData(
    widget: MaybeRefOrGetter<DashboardWidgetConfig | null>,
) {
    const { profileId } = useCurrentProfile();

    return useQuery({
        queryKey: computed(() => {
            const value = toValue(widget);

            return dashboardKeys.data(
                toValue(profileId) ?? 0,
                value?.id ?? 0,
                value === null
                    ? ''
                    : JSON.stringify([value.period, value.selection]),
            );
        }),
        queryFn: () => {
            const value = toValue(widget);

            return dashboardApi.data(
                toValue(profileId) ?? 0,
                value?.id ?? 0,
            ) as Promise<DashboardWidgetData>;
        },
        enabled: computed(
            () => toValue(profileId) !== null && toValue(widget) !== null,
        ),
        refetchInterval: refreshInterval,
    });
}

/**
 * Hook data mutations from other pages (ledger, budget, envelopes) to the
 * dashboard's aggregate queries: their mutation handlers call this alongside
 * invalidating their own keys, so dashboard widgets refetch on the next
 * mount/poll without any special casing on the dashboard itself.
 */
export function invalidateDashboardData(
    queryClient: ReturnType<typeof useQueryClient>,
): void {
    void queryClient.invalidateQueries({ queryKey: dashboardKeys.root });
}
