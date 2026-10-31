import type { Ref } from 'vue';
import {
    useMutation as useVueMutation,
    useQuery as useVueQuery,
} from 'vue-query';

export type QueryKey = readonly unknown[];

export type QueryFnContext = {
    readonly signal?: AbortSignal;
};

export type Query<TData, TError = Error> = {
    data: Ref<TData | undefined>;
    error: Ref<TError | null>;
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
        enabled: options.enabled,
        select: options.select,
    });

    return {
        data: query.data,
        error: query.error,
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
