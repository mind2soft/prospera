import {
    type DefaultError,
    type QueryKey,
    type UseMutationReturnType,
    type UseQueryOptions,
    useQueryClient,
    useMutation as useTanstackMutation,
    useQuery as useTanstackQuery,
} from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, type Ref, toValue } from 'vue';
import {
    type MutationFunctionContext,
    type OptimisticMutationContext,
    type OptimisticMutationOptions,
    optimisticMutationOptions,
} from '@/lib/optimisticCache';
import { isClient } from '@/lib/ssr';

export type { QueryKey } from '@tanstack/vue-query';
export { useMutation } from '@tanstack/vue-query';

export type {
    OptimisticConfig,
    OptimisticTarget,
} from '@/lib/optimisticCache';

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

/** Per-call callbacks of an optimistic mutation, with the caller's context. */
export type OptimisticMutateOptions<TData, TError, TVariables, TContext> = {
    onSuccess?: (
        data: TData,
        variables: TVariables,
        context: TContext | undefined,
        mutationContext: MutationFunctionContext,
    ) => void;
    onError?: (
        error: TError,
        variables: TVariables,
        context: TContext | undefined,
        mutationContext: MutationFunctionContext,
    ) => void;
    onSettled?: (
        data: TData | undefined,
        error: TError | null,
        variables: TVariables,
        context: TContext | undefined,
        mutationContext: MutationFunctionContext,
    ) => void;
};

export type OptimisticMutationResult<TData, TError, TVariables, TContext> =
    Omit<
        UseMutationReturnType<TData, TError, TVariables, TContext>,
        'mutate' | 'mutateAsync'
    > & {
        mutate: (
            variables: TVariables,
            options?: OptimisticMutateOptions<
                TData,
                TError,
                TVariables,
                TContext
            >,
        ) => void;
        mutateAsync: (
            variables: TVariables,
            options?: OptimisticMutateOptions<
                TData,
                TError,
                TVariables,
                TContext
            >,
        ) => Promise<TData>;
    };

/**
 * `useMutation` with an opt-in optimistic cache lifecycle (issue #127).
 *
 * The helper cancels in-flight fetches, snapshots and updates the configured
 * cache entries, rolls them back on failure, and reconciles or invalidates
 * them on success. Caller lifecycle callbacks keep their parameters and are
 * composed in the documented order (docs/frontend-query.md). Ordinary
 * `useMutation` calls are unchanged.
 */
export function useOptimisticMutation<
    TData = unknown,
    TError = DefaultError,
    TVariables = void,
    TContext = unknown,
>(
    options: OptimisticMutationOptions<TData, TError, TVariables, TContext>,
): OptimisticMutationResult<TData, TError, TVariables, TContext> {
    const queryClient = useQueryClient();
    const mutation = useTanstackMutation(
        optimisticMutationOptions(queryClient, options),
    );

    type InternalContext = OptimisticMutationContext<TContext>;

    const wrapCallOptions = (
        callOptions?: OptimisticMutateOptions<
            TData,
            TError,
            TVariables,
            TContext
        >,
    ) => {
        if (callOptions === undefined) {
            return undefined;
        }

        return {
            onSuccess: (
                data: TData,
                variables: TVariables,
                context: InternalContext | undefined,
                mutationContext: MutationFunctionContext,
            ) =>
                callOptions.onSuccess?.(
                    data,
                    variables,
                    context?.user,
                    mutationContext,
                ),
            onError: (
                error: TError,
                variables: TVariables,
                context: InternalContext | undefined,
                mutationContext: MutationFunctionContext,
            ) =>
                callOptions.onError?.(
                    error,
                    variables,
                    context?.user,
                    mutationContext,
                ),
            onSettled: (
                data: TData | undefined,
                error: TError | null,
                variables: TVariables,
                context: InternalContext | undefined,
                mutationContext: MutationFunctionContext,
            ) =>
                callOptions.onSettled?.(
                    data,
                    error,
                    variables,
                    context?.user,
                    mutationContext,
                ),
        };
    };

    return {
        ...mutation,
        mutate: (
            variables: TVariables,
            callOptions?: OptimisticMutateOptions<
                TData,
                TError,
                TVariables,
                TContext
            >,
        ) => mutation.mutate(variables, wrapCallOptions(callOptions)),
        mutateAsync: (
            variables: TVariables,
            callOptions?: OptimisticMutateOptions<
                TData,
                TError,
                TVariables,
                TContext
            >,
        ) => mutation.mutateAsync(variables, wrapCallOptions(callOptions)),
    } as OptimisticMutationResult<TData, TError, TVariables, TContext>;
}
