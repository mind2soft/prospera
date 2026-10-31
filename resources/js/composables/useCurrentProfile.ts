import { computed, ref, watch } from 'vue';
import type { FinancialProfile } from '@/types';
import { useProfiles } from '@/composables/profileQueries';

const STORAGE_KEY = 'prospera.current_profile_id';

/**
 * Module-level singleton: every page and component shares the same selected
 * profile (docs/shared-finances.md — switching profile). The id persists in
 * localStorage, client-side only; on boot it is resolved against the user's
 * profiles and falls back to their owned profile when the stored profile no
 * longer exists or membership was lost.
 */
const selectedId = ref<number | null>(null);
let initialized = false;

const readStoredId = (): number | null => {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed = raw === null ? Number.NaN : Number(raw);
        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    } catch {
        return null;
    }
};

const writeStoredId = (id: number | null): void => {
    try {
        if (id === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, String(id));
        }
    } catch {
        // localStorage unavailable (private mode, etc.) — selection stays in memory.
    }
};

export function useCurrentProfile() {
    const profilesQuery = useProfiles();
    const profiles = computed(() => profilesQuery.data.value ?? []);

    if (!initialized) {
        initialized = true;

        // Resolve once the profiles load: stored profile when the user is
        // still a member, else the owned profile (else the first one).
        watch(
            profiles,
            (list) => {
                const match = list.find((p) => p.id === readStoredId());
                const fallback =
                    list.find((p) => p.is_owner) ?? list[0] ?? null;
                const next = match?.id ?? fallback?.id ?? null;

                if (next !== selectedId.value) {
                    selectedId.value = next;
                    writeStoredId(next);
                }
            },
            { immediate: true },
        );
    }

    const currentProfile = computed<FinancialProfile | null>(
        () => profiles.value.find((p) => p.id === selectedId.value) ?? null,
    );

    const select = (id: number): void => {
        if (!profiles.value.some((p) => p.id === id)) {
            return;
        }
        selectedId.value = id;
        writeStoredId(id);
    };

    return {
        profiles,
        isLoading: profilesQuery.isLoading,
        profileId: computed(() => selectedId.value),
        currentProfile,
        select,
    };
}
