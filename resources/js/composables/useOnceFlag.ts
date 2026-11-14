import { computed, type MaybeRefOrGetter } from 'vue';
import { useLocalJson } from '@/composables/useLocalJson';

/**
 * A one-time flag remembered in this browser (milestone dialogs). Stored as
 * `"1"` under `key` — the format the first onboarding dialogs used, so a
 * dialog seen before is never shown again.
 */
export function useOnceFlag(key: MaybeRefOrGetter<string | null>) {
    const flag = useLocalJson<number | string>(key);

    return {
        /** False until the stored state has been read (never during SSR). */
        loaded: flag.loaded,
        seen: computed(() => flag.value.value !== undefined),
        markSeen: (): void => flag.set(1),
    };
}
