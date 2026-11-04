import { computed, toValue, type Ref } from 'vue';
import {
    useMutation as useVueMutation,
    useQuery as useVueQuery,
} from 'vue-query';
import { isClient } from '@/lib/ssr';

export type QueryKey = readonly unknown[];

export type QueryFnContext = {
    readonly signal?: AbortSignal;
};

export type Query<TData, TError = Error> = {
    data: Ref<TData | undefined>;
    error: Ref<TError | null>;
    isPending: Ref<boolean>;
    isError: Ref<boolean>;
    isLoading: Ref<boolean>;
};

export type MutateOptions<TData, TError, TVariables> = {
    onSuccess?: (data: TData, variables: TVariables) => void;
    onError?: (error: TError, variables: TVariables) => void;
    onSettled?: (data: TData | undefined, error: TError | null) => void;
};

export type Mutation<TData, TError, TVariables> = {
    data: Ref<TData | undefined>;
    error: Ref<TError | null>;
    isLoading: Ref<boolean>;
    mutate: (
        variables: TVariables,
        options?: MutateOptions<TData, TError, TVariables>,
    ) => void;
};

type QueryOptions<TQueryFnData, TData> = {
    queryKey: QueryKey | Ref<QueryKey>;
    queryFn: (context: QueryFnContext) => Promise<TQueryFnData>;
    enabled?: boolean | Ref<boolean>;
    select?: (data: TQueryFnData) => TData;
};

/**
 * Typed wrapper around vue-query's `useQuery`. vue-query's own result types
 * degrade to `any` (its generics are too weak to type `data`), so this
 * wrapper declares explicit result types and keeps every call site fully
 * inferred and `any`-free.
 */
export function useQuery<TQueryFnData, TError = Error, TData = TQueryFnData>(
    options: QueryOptions<TQueryFnData, TData> & {
        select: (data: TQueryFnData) => TData;
    },
): Query<TData, TError>;
export function useQuery<TQueryFnData, TError = Error>(
    options: Omit<QueryOptions<TQueryFnData, TQueryFnData>, 'select'>,
): Query<TQueryFnData, TError>;
export function useQuery<TQueryFnData, TError, TData>(
    options: QueryOptions<TQueryFnData, TData>,
): Query<TData, TError> {
    const query = useVueQuery<TQueryFnData, TError, TData>({
        queryKey: options.queryKey,
        queryFn: options.queryFn,
        select: options.select,
        // The SSR pass can never fetch successfully: there is no session
        // cookie, no CSRF token, and Node's fetch cannot resolve relative
        // URLs. Disable every query server-side so the SSR markup is the
        // deterministic "no data yet" state — the same state the client
        // renders on its synchronous hydration pass. Templates that switch
        // between skeletons and content must branch on `isPending` (no data
        // yet), never on `isLoading` (a fetch is in flight): `isLoading`
        // depends on fetch status, which diverges between the async SSR
        // render and the hydration render and was the source of the
        // skeleton/comment hydration mismatches.
        enabled: computed(() => isClient && toValue(options.enabled ?? true)),
    });

    return {
        data: query.data,
        error: query.error,
        // vue-query wraps TanStack v4, which has no `isPending`. Derive the
        // hydration-safe flag locally: "no data and no error", independent
        // of fetch status. `status` here is 'idle' (never fetched), 'loading'
        // (fetch in flight), 'success' or 'error' — only data presence is
        // identical between the SSR render and the client's hydration pass.
        isPending: computed(() => {
            const status = query.status.value;
            return status !== 'success' && status !== 'error';
        }),
        isError: query.isError,
        isLoading: query.isLoading,
    };
}

/**
 * Typed wrapper around vue-query's `useMutation` with a `mutate` that
 * contextually types inline `onSuccess` / `onError` / `onSettled` callbacks.
 */
export function useMutation<TData, TVariables = void, TError = Error>(
    mutationFn: (variables: TVariables) => Promise<TData>,
    options?: MutateOptions<TData, TError, TVariables>,
): Mutation<TData, TError, TVariables> {
    const mutation = useVueMutation<TData, TError, TVariables, unknown>(
        mutationFn,
        options,
    );

    return {
        data: mutation.data,
        error: mutation.error,
        isLoading: mutation.isLoading,
        mutate: (variables, mutateOptions) =>
            mutation.mutate(variables, {
                onSuccess: mutateOptions?.onSuccess,
                onError: mutateOptions?.onError,
                onSettled: mutateOptions?.onSettled,
            }),
    };
}
