import { QueryClient, QueryObserver } from '@tanstack/vue-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    beginOptimisticUpdate,
    commitOptimisticUpdate,
    type OptimisticConfig,
    optimisticMutationOptions,
    rollbackOptimisticUpdate,
} from './optimisticCache';

type Todo = { id: number; title: string };

describe('optimistic cache updates', () => {
    let client: QueryClient;

    beforeEach(() => {
        client = new QueryClient({
            defaultOptions: { queries: { retry: false } },
        });
    });

    afterEach(async () => {
        await client.cancelQueries();
        client.clear();
    });

    const appendConfig = (): OptimisticConfig<string[], { value: string }> => ({
        targets: (variables) => [
            {
                queryKey: ['todos'],
                update: (previous: string[] | undefined) => [
                    ...(previous ?? []),
                    variables.value,
                ],
            },
        ],
    });

    it('applies an optimistic update to an exact key and removes a created entry on rollback', async () => {
        const plan = await beginOptimisticUpdate(client, appendConfig(), {
            value: 'first',
        });

        expect(client.getQueryData(['todos'])).toEqual(['first']);

        await rollbackOptimisticUpdate(plan);

        expect(
            client.getQueryCache().find({ queryKey: ['todos'], exact: true }),
        ).toBeUndefined();
    });

    it('does not create an absent key when the updater returns undefined', async () => {
        const plan = await beginOptimisticUpdate(
            client,
            { targets: [{ queryKey: ['todos'], update: () => undefined }] },
            {},
        );

        expect(
            client.getQueryCache().find({ queryKey: ['todos'], exact: true }),
        ).toBeUndefined();

        await rollbackOptimisticUpdate(plan);
    });

    it('restores a pre-existing query with undefined data after rollback', async () => {
        const query = client.getQueryCache().build(client, {
            queryKey: ['empty'],
            queryFn: async () => 'server',
        });
        expect(query.state.data).toBeUndefined();

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['empty'],
                        update: () => 'optimistic',
                    },
                ],
            },
            {},
        );

        expect(client.getQueryData(['empty'])).toBe('optimistic');

        await rollbackOptimisticUpdate(plan);

        expect(client.getQueryCache().find({ queryKey: ['empty'] })).toBe(
            query,
        );
        expect(query.state.data).toBeUndefined();
    });

    it('can seed a previously absent exact key from canonical success data', async () => {
        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todo', 1],
                        update: () => undefined,
                    },
                ],
                reconcile: () => [
                    {
                        queryKey: ['todo', 1],
                        update: (
                            _previous: Todo | undefined,
                            _variables,
                            data,
                        ) => data,
                    },
                ],
            },
            {},
        );

        expect(client.getQueryData(['todo', 1])).toBeUndefined();

        await commitOptimisticUpdate(plan, { id: 1, title: 'canonical' });

        expect(client.getQueryData<Todo>(['todo', 1])).toEqual({
            id: 1,
            title: 'canonical',
        });
    });

    it('updates every cached key of a family and leaves unrelated keys alone', async () => {
        client.setQueryData(['todos', 1], ['a']);
        client.setQueryData(['todos', 2], ['b']);
        client.setQueryData(['other'], 'untouched');

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todos'],
                        exact: false,
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'x',
                        ],
                    },
                ],
            },
            {},
        );

        expect(client.getQueryData(['todos', 1])).toEqual(['a', 'x']);
        expect(client.getQueryData(['todos', 2])).toEqual(['b', 'x']);
        expect(client.getQueryData(['other'])).toBe('untouched');

        await rollbackOptimisticUpdate(plan);

        expect(client.getQueryData(['todos', 1])).toEqual(['a']);
        expect(client.getQueryData(['todos', 2])).toEqual(['b']);
        expect(client.getQueryData(['other'])).toBe('untouched');
    });

    it('cancels an in-flight fetch so a stale response cannot overwrite the optimistic value', async () => {
        let resolveFetch: ((value: string[]) => void) | undefined;
        let started = false;
        const observer = new QueryObserver(client, {
            queryKey: ['todos'],
            queryFn: ({ signal }) => {
                started = true;

                return new Promise<string[]>((resolve, reject) => {
                    resolveFetch = resolve;
                    signal?.addEventListener('abort', () =>
                        reject(new Error('aborted')),
                    );
                });
            },
        });
        const unsubscribe = observer.subscribe(() => {});

        await vi.waitFor(() => expect(started).toBe(true));

        const plan = await beginOptimisticUpdate(client, appendConfig(), {
            value: 'optimistic',
        });
        expect(client.getQueryData(['todos'])).toEqual(['optimistic']);

        resolveFetch?.(['stale']);
        await vi.waitFor(() =>
            expect(client.getQueryData(['todos'])).toEqual(['optimistic']),
        );

        unsubscribe();
        await commitOptimisticUpdate(plan, ['server']);
    });

    it('reconciles with canonical server data instead of invalidating when configured', async () => {
        client.setQueryData<Todo>(['todo', 1], { id: 1, title: 'before' });

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todo', 1],
                        update: (previous: Todo | undefined) => ({
                            ...(previous ?? { id: 1, title: '' }),
                            title: 'optimistic',
                        }),
                    },
                ],
                reconcile: (data: { title: string }) => [
                    {
                        queryKey: ['todo', 1],
                        update: (previous: Todo | undefined) => ({
                            ...(previous ?? { id: 1, title: '' }),
                            title: data.title,
                        }),
                    },
                ],
            },
            {},
        );

        expect(client.getQueryData<Todo>(['todo', 1])?.title).toBe(
            'optimistic',
        );

        await commitOptimisticUpdate(plan, { title: 'server' });

        expect(client.getQueryData<Todo>(['todo', 1])?.title).toBe('server');
        expect(client.getQueryState(['todo', 1])?.isInvalidated).toBe(false);
    });

    it('reconciles a family key with TanStack partial object-key semantics', async () => {
        const concreteKey = ['todos', { status: 'open', page: 2 }] as const;
        const familyKey = ['todos', { status: 'open' }] as const;
        client.setQueryData(concreteKey, ['before']);

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: familyKey,
                        exact: false,
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'optimistic',
                        ],
                    },
                ],
                reconcile: () => [
                    {
                        queryKey: familyKey,
                        exact: false,
                        update: (
                            _previous: string[] | undefined,
                            _variables,
                            canonical,
                        ) => canonical,
                    },
                ],
            },
            {},
        );

        await commitOptimisticUpdate(plan, ['canonical']);

        expect(client.getQueryData(concreteKey)).toEqual(['canonical']);
        expect(client.getQueryState(concreteKey)?.isInvalidated).toBe(false);
    });

    it('invalidates the affected key after success without a reconcile updater', async () => {
        client.setQueryData(['todos'], ['before']);

        const plan = await beginOptimisticUpdate(client, appendConfig(), {
            value: 'after',
        });
        await commitOptimisticUpdate(plan, undefined);

        expect(client.getQueryState(['todos'])?.isInvalidated).toBe(true);
    });

    it('restores prior values, including prior absence, on rollback', async () => {
        client.setQueryData(['todos'], ['old']);
        client.setQueryData(['other'], 'untouched');

        const plan = await beginOptimisticUpdate(client, appendConfig(), {
            value: 'new',
        });
        await rollbackOptimisticUpdate(plan);

        expect(client.getQueryData(['todos'])).toEqual(['old']);
        expect(client.getQueryData(['other'])).toBe('untouched');
    });

    it('restores the prior freshness and invalidation state on rollback', async () => {
        client.setQueryData(['todos'], ['old']);
        await client.invalidateQueries({
            queryKey: ['todos'],
            refetchType: 'none',
        });
        const previous = client.getQueryState(['todos']);

        const plan = await beginOptimisticUpdate(client, appendConfig(), {
            value: 'optimistic',
        });
        await rollbackOptimisticUpdate(plan);

        const restored = client.getQueryState(['todos']);
        expect(client.getQueryData(['todos'])).toEqual(['old']);
        expect(restored?.isInvalidated).toBe(true);
        expect(restored?.dataUpdatedAt).toBe(previous?.dataUpdatedAt);
        expect(restored?.error).toBe(previous?.error);
    });

    it('restores earlier targets when a later optimistic updater throws', async () => {
        client.setQueryData(['first'], ['before']);
        client.setQueryData(['second'], ['before']);

        await expect(
            beginOptimisticUpdate(
                client,
                {
                    targets: [
                        {
                            queryKey: ['first'],
                            update: (previous: string[] | undefined) => [
                                ...(previous ?? []),
                                'optimistic',
                            ],
                        },
                        {
                            queryKey: ['second'],
                            update: () => {
                                throw new Error('bad updater');
                            },
                        },
                    ],
                },
                {},
            ),
        ).rejects.toThrow('bad updater');

        expect(client.getQueryData(['first'])).toEqual(['before']);
        expect(client.getQueryData(['second'])).toEqual(['before']);
    });

    it('keeps a later pending update when an earlier overlapping update fails', async () => {
        client.setQueryData(['count'], 0);

        const increment = (by: number): OptimisticConfig<number, number> => ({
            targets: [
                {
                    queryKey: ['count'],
                    update: (previous: number | undefined) =>
                        (previous ?? 0) + by,
                },
            ],
        });

        const first = await beginOptimisticUpdate(client, increment(1), 1);
        const second = await beginOptimisticUpdate(client, increment(10), 10);

        expect(client.getQueryData(['count'])).toBe(11);

        await rollbackOptimisticUpdate(first);

        // Base (0) + the still-pending second operation (+10).
        expect(client.getQueryData(['count'])).toBe(10);

        await commitOptimisticUpdate(second, 10);

        expect(client.getQueryData(['count'])).toBe(10);
        expect(client.getQueryState(['count'])?.isInvalidated).toBe(true);
    });

    it('does not let an earlier failure erase a later successful update', async () => {
        client.setQueryData(['count'], 0);

        const increment = (by: number): OptimisticConfig<number, number> => ({
            targets: [
                {
                    queryKey: ['count'],
                    update: (previous: number | undefined) =>
                        (previous ?? 0) + by,
                },
            ],
        });

        const first = await beginOptimisticUpdate(client, increment(1), 1);
        const second = await beginOptimisticUpdate(client, increment(10), 10);

        // The later mutation succeeds first while the earlier is pending.
        await commitOptimisticUpdate(second, 10);
        expect(client.getQueryData(['count'])).toBe(11);

        await rollbackOptimisticUpdate(first);

        // Base (0) + the successful second operation (+10).
        expect(client.getQueryData(['count'])).toBe(10);
    });

    it('updates the raw cache entry behind a select projection', async () => {
        client.setQueryData(['todos'], [{ id: 1, title: 'one' }]);

        const observer = new QueryObserver(client, {
            queryKey: ['todos'],
            select: (todos: Todo[]) => todos.length,
        });
        const unsubscribe = observer.subscribe(() => {});
        await vi.waitFor(() =>
            expect(observer.getCurrentResult().data).toBe(1),
        );

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todos'],
                        update: (previous: Todo[] | undefined) => [
                            ...(previous ?? []),
                            { id: 2, title: 'two' },
                        ],
                    },
                ],
            },
            {},
        );

        expect(client.getQueryData<Todo[]>(['todos'])).toHaveLength(2);
        await vi.waitFor(() =>
            expect(observer.getCurrentResult().data).toBe(2),
        );

        await rollbackOptimisticUpdate(plan);
        unsubscribe();
    });

    it('invalidates every cached key of a family after success', async () => {
        client.setQueryData(['todos', 1], ['a']);
        client.setQueryData(['todos', 2], ['b']);

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todos'],
                        exact: false,
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'x',
                        ],
                    },
                ],
            },
            {},
        );

        await commitOptimisticUpdate(plan, undefined);

        expect(client.getQueryState(['todos', 1])?.isInvalidated).toBe(true);
        expect(client.getQueryState(['todos', 2])?.isInvalidated).toBe(true);
    });

    it('invalidates a family filter even when it had no cached members', async () => {
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['todos'],
                        exact: false,
                        update: () => undefined,
                    },
                ],
            },
            {},
        );

        await commitOptimisticUpdate(plan, undefined);

        expect(invalidate).toHaveBeenCalledWith({
            queryKey: ['todos'],
            exact: false,
        });
    });

    it('skips invalidation when the caller owns the success refetch', async () => {
        client.setQueryData(['todos'], ['before']);

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: appendConfig().targets,
                invalidateOnSuccess: false,
            },
            { value: 'after' },
        );

        await commitOptimisticUpdate(plan, undefined);

        expect(client.getQueryState(['todos'])?.isInvalidated).toBe(false);
    });

    it('does not lose an earlier default-invalidation requirement during overlap', async () => {
        client.setQueryData(['count'], 0);

        const config = (
            invalidateOnSuccess?: boolean,
        ): OptimisticConfig<number, number> => ({
            targets: [
                {
                    queryKey: ['count'],
                    update: (previous: number | undefined) =>
                        (previous ?? 0) + 1,
                },
            ],
            invalidateOnSuccess,
        });

        const defaultInvalidation = await beginOptimisticUpdate(
            client,
            config(),
            1,
        );
        const callerInvalidation = await beginOptimisticUpdate(
            client,
            config(false),
            2,
        );

        await commitOptimisticUpdate(defaultInvalidation, 1);
        await commitOptimisticUpdate(callerInvalidation, 2);

        expect(client.getQueryState(['count'])?.isInvalidated).toBe(true);
    });

    it('invalidates when a reconcile updater declines to change the cache', async () => {
        client.setQueryData(['todos'], ['before']);

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: appendConfig().targets,
                reconcile: () => [
                    {
                        queryKey: ['todos'],
                        update: () => undefined,
                    },
                ],
            },
            { value: 'after' },
        );

        await commitOptimisticUpdate(plan, undefined);

        expect(client.getQueryState(['todos'])?.isInvalidated).toBe(true);
    });

    it('leaves the raw cache value immutable and preserves unrelated fields', async () => {
        type State = { items: Todo[]; meta: { total: number } };
        const original: State = {
            items: [{ id: 1, title: 'one' }],
            meta: { total: 1 },
        };
        client.setQueryData(['state'], original);

        const plan = await beginOptimisticUpdate(
            client,
            {
                targets: [
                    {
                        queryKey: ['state'],
                        update: (previous: State | undefined) =>
                            previous === undefined
                                ? undefined
                                : {
                                      ...previous,
                                      items: [
                                          ...previous.items,
                                          { id: 2, title: 'two' },
                                      ],
                                  },
                    },
                ],
            },
            {},
        );

        const updated = client.getQueryData<State>(['state']);

        expect(updated).not.toBe(original);
        expect(updated?.items).toHaveLength(2);
        expect(updated?.meta).toBe(original.meta);
        expect(original.items).toHaveLength(1);

        await rollbackOptimisticUpdate(plan);
    });

    it('keeps overlapping mutations on different keys independent', async () => {
        client.setQueryData(['left'], 0);
        client.setQueryData(['right'], 0);

        const increment = (
            key: string,
            by: number,
        ): OptimisticConfig<number, number> => ({
            targets: [
                {
                    queryKey: [key],
                    update: (previous: number | undefined) =>
                        (previous ?? 0) + by,
                },
            ],
        });

        const left = await beginOptimisticUpdate(
            client,
            increment('left', 1),
            1,
        );
        const right = await beginOptimisticUpdate(
            client,
            increment('right', 10),
            10,
        );

        await rollbackOptimisticUpdate(left);

        expect(client.getQueryData(['left'])).toBe(0);
        expect(client.getQueryData(['right'])).toBe(10);

        await commitOptimisticUpdate(right, 10);

        expect(client.getQueryData(['right'])).toBe(10);
    });
});

describe('optimistic mutation lifecycle', () => {
    let client: QueryClient;

    beforeEach(() => {
        client = new QueryClient({
            defaultOptions: { queries: { retry: false } },
        });
    });

    afterEach(async () => {
        await client.cancelQueries();
        client.clear();
    });

    it('runs optimistic write, caller onMutate, success, and settled in order', async () => {
        const order: string[] = [];
        client.setQueryData(['todos'], ['before']);

        const options = optimisticMutationOptions(client, {
            mutationFn: async () => ({ title: 'server' }),
            optimistic: {
                targets: [
                    {
                        queryKey: ['todos'],
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'optimistic',
                        ],
                    },
                ],
            },
            onMutate: async (_variables, mutationContext) => {
                // The optimistic value is already visible to the caller.
                expect(client.getQueryData(['todos'])).toEqual([
                    'before',
                    'optimistic',
                ]);
                expect(mutationContext.client).toBe(client);
                order.push('onMutate');

                return { token: 'caller-context' };
            },
            onSuccess: (data, variables, context, mutationContext) => {
                order.push('onSuccess');
                expect(data.title).toBe('server');
                expect(variables).toBeUndefined();
                expect(context).toEqual({ token: 'caller-context' });
                expect(mutationContext.client).toBe(client);
            },
            onSettled: (data, error, variables, context, mutationContext) => {
                order.push('onSettled');
                expect(data?.title).toBe('server');
                expect(error).toBeNull();
                expect(variables).toBeUndefined();
                expect(context).toEqual({ token: 'caller-context' });
                expect(mutationContext.client).toBe(client);
            },
        });

        const mutationContext = { client } as never;
        const context = await options.onMutate?.(undefined, mutationContext);

        if (context === undefined) {
            throw new Error('expected onMutate context');
        }

        await options.onSuccess?.(
            { title: 'server' },
            undefined,
            context,
            mutationContext,
        );
        await options.onSettled?.(
            { title: 'server' },
            null,
            undefined,
            context,
            mutationContext,
        );

        expect(order).toEqual(['onMutate', 'onSuccess', 'onSettled']);
        expect(client.getQueryState(['todos'])?.isInvalidated).toBe(true);
    });

    it('rolls back before the caller onError and preserves its context', async () => {
        const order: string[] = [];
        client.setQueryData(['todos'], ['before']);

        const options = optimisticMutationOptions(client, {
            mutationFn: async () => {
                throw new Error('nope');
            },
            optimistic: {
                targets: [
                    {
                        queryKey: ['todos'],
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'optimistic',
                        ],
                    },
                ],
            },
            onMutate: () => {
                order.push('onMutate');

                return 'caller-context';
            },
            onError: (error, variables, context) => {
                // The optimistic value is gone before the error callback.
                expect(client.getQueryData(['todos'])).toEqual(['before']);
                expect(error).toBeInstanceOf(Error);
                expect((error as Error).message).toBe('nope');
                expect(variables).toBeUndefined();
                expect(context).toBe('caller-context');
                order.push('onError');
            },
            onSettled: () => {
                order.push('onSettled');
            },
        });

        const context = await options.onMutate?.(undefined, {} as never);
        await options.onError?.(
            new Error('nope'),
            undefined,
            context,
            {} as never,
        );
        await options.onSettled?.(
            undefined,
            new Error('nope'),
            undefined,
            context,
            {} as never,
        );

        expect(order).toEqual(['onMutate', 'onError', 'onSettled']);
    });

    it('awaits async caller callbacks before continuing', async () => {
        let released: (() => void) | undefined;
        const gate = new Promise<void>((resolve) => {
            released = resolve;
        });
        let successRan = false;

        const options = optimisticMutationOptions(client, {
            mutationFn: async () => 'data',
            optimistic: { targets: [] },
            onMutate: async () => {
                await gate;

                return 'context';
            },
            onSuccess: async () => {
                successRan = true;
            },
        });

        let mutated = false;
        const mutation = Promise.resolve(
            options.onMutate?.(undefined, {} as never),
        ).then((context) => {
            mutated = true;

            return context;
        });

        await Promise.resolve();
        expect(mutated).toBe(false);

        released?.();
        const context = await mutation;
        expect(mutated).toBe(true);
        expect(context?.user).toBe('context');

        if (context === undefined) {
            throw new Error('expected onMutate context');
        }

        await options.onSuccess?.('data', undefined, context, {} as never);
        expect(successRan).toBe(true);
    });

    it('rolls back if the caller onMutate throws', async () => {
        client.setQueryData(['todos'], ['before']);

        const options = optimisticMutationOptions(client, {
            mutationFn: async () => 'data',
            optimistic: {
                targets: [
                    {
                        queryKey: ['todos'],
                        update: (previous: string[] | undefined) => [
                            ...(previous ?? []),
                            'optimistic',
                        ],
                    },
                ],
            },
            onMutate: () => {
                throw new Error('caller failed');
            },
        });

        await expect(
            options.onMutate?.(undefined, {} as never),
        ).rejects.toThrow('caller failed');
        expect(client.getQueryData(['todos'])).toEqual(['before']);
    });
});
