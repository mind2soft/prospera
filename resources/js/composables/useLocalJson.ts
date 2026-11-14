import {
    type MaybeRefOrGetter,
    onMounted,
    type Ref,
    ref,
    toValue,
    watch,
} from 'vue';

const read = <T>(key: string): T | undefined => {
    try {
        const raw = window.localStorage.getItem(key);
        return raw === null ? undefined : (JSON.parse(raw) as T);
    } catch {
        return undefined;
    }
};

const write = (key: string, value: unknown): void => {
    try {
        if (value === undefined) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // localStorage unavailable (private mode, quota): the value stays in memory.
    }
};

/**
 * A JSON value remembered in this browser under `key` (pledge, collapse
 * state). The SSR pass and the hydration pass both render `undefined`; the
 * stored value is read once mounted, so markup never diverges.
 */
export function useLocalJson<T>(key: MaybeRefOrGetter<string | null>): {
    value: Ref<T | undefined>;
    loaded: Ref<boolean>;
    set: (value: T | undefined) => void;
} {
    const value = ref<T | undefined>() as Ref<T | undefined>;
    const loaded = ref(false);
    let mounted = false;

    const load = (): void => {
        const k = toValue(key);
        value.value = k === null ? undefined : read<T>(k);
        loaded.value = k !== null;
    };

    onMounted(() => {
        mounted = true;
        load();
    });

    watch(
        () => toValue(key),
        () => {
            if (mounted) {
                load();
            }
        },
    );

    const set = (next: T | undefined): void => {
        value.value = next;
        const k = toValue(key);
        if (k !== null) {
            write(k, next);
        }
    };

    return { value, loaded, set };
}
