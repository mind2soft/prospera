import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocalJson } from '@/composables/useLocalJson';

export const PLEDGE_ITEMS = ['record', 'weekly', 'budget'] as const;
export type PledgeItem = (typeof PLEDGE_ITEMS)[number];

export type Pledge = { items: PledgeItem[]; signedAt: string };

/**
 * The getting-started pledge: per user, remembered in this browser only
 * (docs/onboarding.md — pledge). A commitment device, not a setting.
 */
export function usePledge() {
    const page = usePage();
    const userId = computed(() => page.props.auth?.user?.id ?? null);
    const stored = useLocalJson<Pledge>(() =>
        userId.value === null
            ? null
            : `prospera.onboarding.pledge.${userId.value}`,
    );

    const pledge = computed(() => {
        const value = stored.value.value;
        return value !== undefined &&
            Array.isArray(value.items) &&
            value.items.length > 0
            ? value
            : null;
    });

    return {
        pledge,
        loaded: stored.loaded,
        has: (item: PledgeItem): boolean =>
            pledge.value?.items.includes(item) === true,
        sign: (items: PledgeItem[], today: string): void =>
            stored.set({ items, signedAt: today }),
    };
}
