// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest';
import { createApp, defineComponent, nextTick } from 'vue';
import {
    type LeaveReason,
    useFlushOnLeave,
} from '@/composables/useFlushOnLeave';

/**
 * The composable is called from a real (tiny) component: `onBeforeUnmount`
 * and `useEventListener` only register within a component's lifecycle.
 *
 * The listeners attach during the post-flush (vueuse's `useEventListener`
 * watches its targets with `flush: 'post'`), so every fixture awaits a
 * tick once before events can reach them.
 */
function mountFlushOnLeave() {
    const flushes: LeaveReason[] = [];
    const root = document.createElement('div');
    document.body.append(root);

    const app = createApp(
        defineComponent({
            setup() {
                useFlushOnLeave((reason) => flushes.push(reason));

                return () => null;
            },
        }),
    );

    app.mount(root);

    return {
        flushes,
        unmount: () => {
            app.unmount();
            root.remove();
        },
    };
}

describe('useFlushOnLeave', () => {
    const fixtures: ReturnType<typeof mountFlushOnLeave>[] = [];

    afterEach(() => {
        for (const fixture of fixtures) {
            fixture.unmount();
        }
        fixtures.length = 0;
    });

    const mount = () => {
        const fixture = mountFlushOnLeave();
        fixtures.push(fixture);

        return fixture;
    };

    it('flushes nothing while the component is alive', async () => {
        const fixture = mount();
        await nextTick();

        expect(fixture.flushes).toEqual([]);
    });

    it('flushes once with the pagehide reason when the page is hidden', async () => {
        const fixture = mount();
        await nextTick();

        window.dispatchEvent(new Event('pagehide'));

        expect(fixture.flushes).toEqual(['pagehide']);
    });

    it('flushes once with the unmount reason when the component unmounts', async () => {
        const fixture = mount();
        await nextTick();

        fixture.unmount();

        expect(fixture.flushes).toEqual(['unmount']);
    });

    it('detaches the pagehide listener after the component unmounted', async () => {
        const fixture = mount();
        await nextTick();

        fixture.unmount();
        window.dispatchEvent(new Event('pagehide'));

        expect(fixture.flushes).toEqual(['unmount']);
    });

    it('flushes on each route to leaving, the reason distinguishing the transports', async () => {
        const fixture = mount();
        await nextTick();

        window.dispatchEvent(new Event('pagehide'));
        fixture.unmount();

        expect(fixture.flushes).toEqual(['pagehide', 'unmount']);
    });
});
