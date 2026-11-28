import type {
    MutationOptions,
    QueryClient,
    QueryKey,
} from '@tanstack/vue-query';

/**
 * A typed, opt-in optimistic cache update (issue #127).
 *
 * An optimistic target describes one cached query (or one query-key family)
 * to snapshot and update while a mutation is in flight. `update` receives the
 * raw cached value — never a component's `select` projection — and returns the
 * next value. Returning `undefined` means "leave this entry unchanged"; it
 * never deletes an entry. An absent exact key is only created when the
 * updater explicitly returns a value.
 *
 * Annotate `previous` at the call site (`(previous: Ledger[] | undefined) =>
 * ...`); the `never` parameter keeps the helper generic while still allowing
 * that annotation. Updates must be immutable: never mutate the array/object
 * held by an observer.
 */
export type OptimisticTarget<TVariables = void, TData = unknown> = {
    /** The exact cache key, or the prefix of a query-key family. */
    queryKey: QueryKey;
    /** Match the key exactly (default) or every cached key under the prefix. */
    exact?: boolean;
    update: (previous: never, variables: TVariables, data?: TData) => unknown;
};

/**
 * The declarative optimistic configuration of a mutation.
 *
 * `reconcile` is optional: when the server response can replace the
 * optimistic value, return the canonical cache writes from it. Without it the
 * affected query filters are invalidated/refetched after success. Set
 * `invalidateOnSuccess` to `true` to invalidate even when a reconcile updater
 * exists, or to `false` when the mutation's own `onSuccess` already performs
 * the feature-specific invalidation (avoiding a duplicate refetch).
 */
export type OptimisticConfig<TData, TVariables> = {
    targets:
        | readonly OptimisticTarget<TVariables, TData>[]
        | ((
              variables: TVariables,
          ) => readonly OptimisticTarget<TVariables, TData>[]);
    reconcile?: (
        data: TData,
        variables: TVariables,
    ) => readonly OptimisticTarget<TVariables, TData>[];
    invalidateOnSuccess?: boolean;
};

/** Internal: one updater bound to a concrete cached key. */
type ResolvedTarget = {
    queryKey: QueryKey;
    exact: boolean;
    update: (previous: unknown, variables: unknown, data?: unknown) => unknown;
};

/** Internal: one pending optimistic write on one cached key. */
type KeyOperation = {
    variables: unknown;
    update: (previous: unknown, variables: unknown, data?: unknown) => unknown;
    reconcile?: (
        data: unknown,
        variables: unknown,
    ) => readonly OptimisticTarget<unknown, unknown>[];
    /** The target's filter, used for default invalidation after success. */
    filter: { queryKey: QueryKey; exact: boolean };
    invalidateOnSuccess: boolean | undefined;
    status: 'pending' | 'success';
    settledOrder?: number;
    data?: unknown;
};

/**
 * Internal: the snapshot and ordered operation stack of one concrete cache
 * key. The cache value is always `operations` replayed on `base`, so a failed
 * operation can be removed without erasing a later operation's update.
 */
type KeyState = {
    key: QueryKey;
    base: unknown;
    existed: boolean;
    restoreExisting?: () => void;
    operations: KeyOperation[];
};

export type OptimisticPlan = {
    client: QueryClient;
    entries: Array<{ state: KeyState; operation: KeyOperation }>;
    /** Filters with no concrete cached entry (most often an empty family). */
    emptyFilters: Array<{ queryKey: QueryKey; exact: boolean }>;
};

/**
 * Per-client registry of keys with optimistic operations in flight. Kept
 * outside the hook so independent mutation hooks targeting the same key share
 * one ordered stack.
 */
const registries = new WeakMap<QueryClient, Map<string, KeyState>>();
let nextSettledOrder = 0;

function registryFor(client: QueryClient): Map<string, KeyState> {
    let registry = registries.get(client);

    if (registry === undefined) {
        registry = new Map();
        registries.set(client, registry);
    }

    return registry;
}

function keyHash(key: QueryKey): string {
    return JSON.stringify(key, (_key, value: unknown) => {
        if (
            value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value) &&
            Object.getPrototypeOf(value) === Object.prototype
        ) {
            return Object.keys(value)
                .sort()
                .reduce<Record<string, unknown>>((sorted, property) => {
                    sorted[property] = (value as Record<string, unknown>)[
                        property
                    ];

                    return sorted;
                }, {});
        }

        return value;
    });
}

function keyStartsWith(key: QueryKey, prefix: QueryKey): boolean {
    if (prefix.length > key.length) {
        return false;
    }

    return prefix.every((part, index) => partiallyMatches(key[index], part));
}

/** Match TanStack's partial key semantics, including object-prefix values. */
function partiallyMatches(candidate: unknown, filter: unknown): boolean {
    if (candidate === filter) {
        return true;
    }

    if (
        candidate === null ||
        filter === null ||
        typeof candidate !== typeof filter
    ) {
        return false;
    }

    if (typeof candidate === 'object' && typeof filter === 'object') {
        return Object.keys(filter).every((key) =>
            partiallyMatches(
                (candidate as Record<string, unknown>)[key],
                (filter as Record<string, unknown>)[key],
            ),
        );
    }

    return false;
}

function resolveTargets<TData, TVariables>(
    config: OptimisticConfig<TData, TVariables>,
    variables: TVariables,
): ResolvedTarget[] {
    const targets =
        typeof config.targets === 'function'
            ? config.targets(variables)
            : config.targets;

    return targets.map((target) => ({
        queryKey: target.queryKey,
        exact: target.exact ?? true,
        update: target.update as ResolvedTarget['update'],
    }));
}

function resolveReconcileTargets(operation: KeyOperation): ResolvedTarget[] {
    if (operation.reconcile === undefined) {
        return [];
    }

    return operation
        .reconcile(operation.data, operation.variables)
        .map((target) => ({
            queryKey: target.queryKey,
            exact: target.exact ?? true,
            update: target.update as ResolvedTarget['update'],
        }));
}

/** The concrete cached keys a target affects (an exact key may be absent). */
function concreteKeys(client: QueryClient, target: ResolvedTarget): QueryKey[] {
    if (target.exact) {
        return [target.queryKey];
    }

    return client
        .getQueryCache()
        .findAll({ queryKey: target.queryKey, exact: false })
        .map((query) => query.queryKey);
}

function stateFor(client: QueryClient, key: QueryKey): KeyState {
    const registry = registryFor(client);
    const hash = keyHash(key);
    const existing = registry.get(hash);

    if (existing !== undefined) {
        return existing;
    }

    const query = client.getQueryCache().find({ queryKey: key, exact: true });
    const previousQueryState =
        query === undefined ? undefined : { ...query.state };
    const state: KeyState = {
        key,
        base: client.getQueryData(key),
        existed: query !== undefined,
        restoreExisting:
            query === undefined || previousQueryState === undefined
                ? undefined
                : () => query.setState(previousQueryState),
        operations: [],
    };
    registry.set(hash, state);

    return state;
}

/** Recompute one key's cache value from its base and remaining operations. */
function replay(client: QueryClient, state: KeyState): void {
    let value = state.base;
    let touched = false;

    for (const operation of state.operations) {
        const next = operation.update(value, operation.variables);

        if (next !== undefined) {
            value = next;
            touched = true;
        }
    }

    if (touched) {
        client.setQueryData(state.key, value);
    } else if (state.existed && state.restoreExisting !== undefined) {
        // setQueryData would reset dataUpdatedAt and clear invalidation/error
        // state. Restore the whole prior QueryState when no remaining
        // optimistic operation writes a value.
        state.restoreExisting();
    } else if (state.base !== undefined) {
        client.setQueryData(state.key, state.base);
    } else {
        // setQueryData(key, undefined) is intentionally a no-op in TanStack.
        // Remove a newly created entry, or fall back to removal if a prior
        // query could not be restored for some reason.
        client.removeQueries({ queryKey: state.key, exact: true });
    }
}

/** Remove a partially-applied plan when an updater throws. */
function discard(
    client: QueryClient,
    entries: OptimisticPlan['entries'],
): void {
    const registry = registryFor(client);
    const states = new Set<KeyState>();

    for (const { state, operation } of entries) {
        state.operations = state.operations.filter(
            (candidate) => candidate !== operation,
        );
        states.add(state);
    }

    for (const state of states) {
        replay(client, state);

        if (state.operations.length === 0) {
            const hash = keyHash(state.key);

            if (registry.get(hash) === state) {
                registry.delete(hash);
            }
        }
    }
}

/**
 * Cancel in-flight fetches, snapshot the affected entries, and apply the
 * optimistic updates. Awaiting this before the mutation function runs keeps a
 * stale response from overwriting the optimistic value.
 */
export async function beginOptimisticUpdate<TData, TVariables>(
    client: QueryClient,
    config: OptimisticConfig<TData, TVariables>,
    variables: TVariables,
): Promise<OptimisticPlan> {
    const targets = resolveTargets(config, variables);

    await Promise.all(
        targets.map((target) =>
            client.cancelQueries({
                queryKey: target.queryKey,
                exact: target.exact,
            }),
        ),
    );

    const entries: OptimisticPlan['entries'] = [];
    const emptyFilters: OptimisticPlan['emptyFilters'] = [];

    try {
        for (const target of targets) {
            const keys = concreteKeys(client, target);

            if (keys.length === 0 && config.invalidateOnSuccess !== false) {
                emptyFilters.push({
                    queryKey: target.queryKey,
                    exact: target.exact,
                });
            }

            for (const key of keys) {
                const state = stateFor(client, key);
                const operation: KeyOperation = {
                    variables,
                    update: target.update,
                    reconcile: config.reconcile as unknown as
                        | KeyOperation['reconcile']
                        | undefined,
                    filter: {
                        queryKey: target.queryKey,
                        exact: target.exact,
                    },
                    invalidateOnSuccess: config.invalidateOnSuccess,
                    status: 'pending',
                };

                state.operations.push(operation);
                entries.push({ state, operation });

                const next = target.update(
                    client.getQueryData(key) as never,
                    variables,
                );

                if (next !== undefined) {
                    client.setQueryData(key, next);
                }
            }
        }
    } catch (error) {
        discard(client, entries);
        throw error;
    }

    return { client, entries, emptyFilters };
}

/**
 * Mark the plan's operations as successful and, once every operation on an
 * affected key has settled, reconcile from the server response or invalidate
 * the affected query filters. Overlapping operations stay in the replay stack
 * until they settle, so an earlier failure cannot erase a later update.
 */
export async function commitOptimisticUpdate<TData>(
    plan: OptimisticPlan,
    data: TData,
): Promise<void> {
    const states = new Set<KeyState>();

    for (const { state, operation } of plan.entries) {
        operation.status = 'success';
        operation.settledOrder = ++nextSettledOrder;
        operation.data = data;
        states.add(state);
    }

    await settle(plan.client, states);

    // A query-key family may have no cached members at mutation start. Its
    // filter still needs invalidating on success so the configured target
    // semantics remain consistent if a matching query was registered while
    // the mutation was in flight.
    if (plan.emptyFilters.length > 0) {
        await Promise.all(
            invalidate(plan.client, dedupeFilters(plan.emptyFilters)),
        );
    }
}

/** Remove the plan's operations and restore every affected key. */
export async function rollbackOptimisticUpdate(
    plan: OptimisticPlan,
): Promise<void> {
    const states = new Set<KeyState>();

    for (const { state, operation } of plan.entries) {
        state.operations = state.operations.filter(
            (candidate) => candidate !== operation,
        );
        states.add(state);
    }

    for (const state of states) {
        replay(plan.client, state);
    }

    await settle(plan.client, states);
}

async function settle(
    client: QueryClient,
    states: Iterable<KeyState>,
): Promise<void> {
    const registry = registryFor(client);
    const invalidationFilters: Array<{ queryKey: QueryKey; exact: boolean }> =
        [];

    // All cache mutation and registry cleanup below is synchronous. A new
    // mutation can therefore only start once the settled state is no longer
    // registered, and it snapshots the reconciled/invalidated value instead of
    // sharing a half-settled operation stack.
    for (const state of states) {
        if (
            state.operations.some((operation) => operation.status === 'pending')
        ) {
            continue;
        }

        const succeeded = state.operations.some(
            (operation) => operation.status === 'success',
        );

        if (succeeded) {
            invalidationFilters.push(...finalize(client, state));
        }

        const hash = keyHash(state.key);

        if (registry.get(hash) === state) {
            registry.delete(hash);
        }
    }

    await Promise.all(invalidate(client, dedupeFilters(invalidationFilters)));
}

/** Apply reconciliation or collect the invalidations the key needs. */
function finalize(
    client: QueryClient,
    state: KeyState,
): Array<{ queryKey: QueryKey; exact: boolean }> {
    const successes = state.operations.filter(
        (operation) => operation.status === 'success',
    );
    const latest = successes.reduce<KeyOperation | undefined>(
        (current, operation) =>
            current === undefined ||
            (operation.settledOrder ?? 0) > (current.settledOrder ?? 0)
                ? operation
                : current,
        undefined,
    );

    if (latest === undefined) {
        return [];
    }

    const filters = dedupeFilters(successes);

    // An explicit `true` means "always refetch", even with a reconcile
    // updater; `false` means the caller's own onSuccess owns invalidation.
    if (successes.some((operation) => operation.invalidateOnSuccess === true)) {
        return filters;
    }

    const reconcileTarget = resolveReconcileTargets(latest).find(
        (candidate) =>
            (candidate.exact &&
                keyHash(candidate.queryKey) === keyHash(state.key)) ||
            (!candidate.exact && keyStartsWith(state.key, candidate.queryKey)),
    );

    if (reconcileTarget !== undefined) {
        const next = reconcileTarget.update(
            client.getQueryData(state.key) as never,
            latest.variables as never,
            latest.data,
        );

        if (next !== undefined) {
            client.setQueryData(state.key, next);

            return [];
        }
    }

    if (
        successes.every((operation) => operation.invalidateOnSuccess === false)
    ) {
        return [];
    }

    return filters;
}

function dedupeFilters(
    operations:
        | readonly KeyOperation[]
        | Array<{ queryKey: QueryKey; exact: boolean }>,
): Array<{ queryKey: QueryKey; exact: boolean }> {
    const seen = new Set<string>();
    const filters: Array<{ queryKey: QueryKey; exact: boolean }> = [];

    for (const item of operations) {
        const filter = 'filter' in item ? item.filter : item;
        const id = `${filter.exact ? 'exact' : 'prefix'}:${keyHash(filter.queryKey)}`;

        if (!seen.has(id)) {
            seen.add(id);
            filters.push(filter);
        }
    }

    return filters;
}

function invalidate(
    client: QueryClient,
    filters: ReadonlyArray<{ queryKey: QueryKey; exact: boolean }>,
): Promise<unknown>[] {
    return filters.map((filter) =>
        client.invalidateQueries({
            queryKey: filter.queryKey,
            exact: filter.exact,
        }),
    );
}

/** A caller's `onMutate` context, preserved across the helper's lifecycle. */
export type OptimisticMutationContext<TContext> = {
    plan: OptimisticPlan;
    user: TContext | undefined;
};

/** TanStack's per-mutation execution context, retained for caller callbacks. */
export type MutationFunctionContext = Parameters<
    NonNullable<MutationOptions<unknown, unknown, unknown, unknown>['onMutate']>
>[1];

export type OptimisticMutationOptions<TData, TError, TVariables, TContext> =
    Omit<
        MutationOptions<
            TData,
            TError,
            TVariables,
            OptimisticMutationContext<TContext>
        >,
        'onMutate' | 'onSuccess' | 'onError' | 'onSettled'
    > & {
        optimistic: OptimisticConfig<TData, TVariables>;
        onMutate?: (
            variables: TVariables,
            mutationContext: MutationFunctionContext,
        ) => Promise<TContext> | TContext;
        onSuccess?: (
            data: TData,
            variables: TVariables,
            context: TContext | undefined,
            mutationContext: MutationFunctionContext,
        ) => Promise<unknown> | unknown;
        onError?: (
            error: TError,
            variables: TVariables,
            context: TContext | undefined,
            mutationContext: MutationFunctionContext,
        ) => Promise<unknown> | unknown;
        onSettled?: (
            data: TData | undefined,
            error: TError | null,
            variables: TVariables,
            context: TContext | undefined,
            mutationContext: MutationFunctionContext,
        ) => Promise<unknown> | unknown;
    };

/**
 * Compose the optimistic lifecycle with the caller's callbacks.
 *
 * Order (documented in docs/frontend-query.md):
 * 1. cancel in-flight fetches, snapshot, apply optimistic updates;
 * 2. caller `onMutate` (awaited, context preserved);
 * 3. on success: reconcile/invalidate, then caller `onSuccess`;
 * 4. on failure: roll back, then caller `onError`;
 * 5. caller `onSettled` last.
 */
export function optimisticMutationOptions<TData, TError, TVariables, TContext>(
    client: QueryClient,
    options: OptimisticMutationOptions<TData, TError, TVariables, TContext>,
): MutationOptions<
    TData,
    TError,
    TVariables,
    OptimisticMutationContext<TContext>
> {
    const { optimistic, onMutate, onSuccess, onError, onSettled, ...rest } =
        options;

    return {
        ...rest,
        onMutate: async (variables, mutationContext) => {
            const plan = await beginOptimisticUpdate(
                client,
                optimistic,
                variables,
            );

            try {
                const user = await onMutate?.(variables, mutationContext);

                return { plan, user };
            } catch (error) {
                await rollbackOptimisticUpdate(plan);
                throw error;
            }
        },
        onSuccess: async (data, variables, context, mutationContext) => {
            try {
                if (context?.plan !== undefined) {
                    await commitOptimisticUpdate(context.plan, data);
                }
            } finally {
                await onSuccess?.(
                    data,
                    variables,
                    context?.user,
                    mutationContext,
                );
            }
        },
        onError: async (error, variables, context, mutationContext) => {
            try {
                if (context?.plan !== undefined) {
                    await rollbackOptimisticUpdate(context.plan);
                }
            } finally {
                await onError?.(
                    error,
                    variables,
                    context?.user,
                    mutationContext,
                );
            }
        },
        onSettled: async (data, error, variables, context, mutationContext) => {
            await onSettled?.(
                data,
                error,
                variables,
                context?.user,
                mutationContext,
            );
        },
    };
}
