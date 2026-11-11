import {
    type DefaultError,
    type QueryKey,
    type UseQueryOptions,
    useQuery as useTanstackQuery,
} from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, type Ref, toValue } from 'vue';
import { isClient } from '@/lib/ssr';

export type { QueryKey } from '@tanstack/vue-query';
export { useMutation } from '@tanstack/vue-query';

type QueryOptions<
    TQueryFnData,
    TError,
    TData,
    TQueryKey extends QueryKey,
> = Omit<
    Exclude<
        UseQueryOptions<TQueryFnData, TError, TData, TQueryFnData, TQueryKey>,
        Ref<unknown>
    >,
    'enabled'
> & {
    enabled?: MaybeRefOrGetter<boolean>;
};

/**
 * `useQuery` from TanStack Query, disabled during the SSR pass.
 *
 * The SSR pass can never fetch successfully: there is no session cookie, no
 * CSRF token, and Node's fetch cannot resolve relative URLs. Every query is
 * disabled server-side so the SSR markup is the deterministic "no data yet"
 * state (`status: 'pending'`) — the same state the client renders on its
 * synchronous hydration pass. Templates that switch between skeletons and
 * content must branch on `isPending` (no data yet), never on `isLoading` (a
 * fetch is in flight): `isLoading` depends on fetch status, which diverges
 * between the async SSR render and the hydration render.
 */
export function useQuery<
    TQueryFnData,
    TError = DefaultError,
    TData = TQueryFnData,
    TQueryKey extends QueryKey = QueryKey,
>(options: QueryOptions<TQueryFnData, TError, TData, TQueryKey>) {
    return useTanstackQuery<TQueryFnData, TError, TData, TQueryKey>({
        ...options,
        enabled: computed(() => isClient && toValue(options.enabled ?? true)),
    });
}
