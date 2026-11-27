// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, h } from 'vue';
import BudgetEnvelopeTable from '@/components/Budget/BudgetEnvelopeTable.vue';
import { i18n } from '@/i18n';
import type { BudgetEnvelope } from '@/types';

// Reka UI's PrimitiveProps cannot be resolved by the Vitest SFC compiler;
// the button's behaviour is irrelevant to this typography assertion.
vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Button: defineComponent({
            name: 'Button',
            setup:
                (_, { slots }) =>
                () =>
                    h('button', slots.default?.()),
        }),
    };
});

const envelopes: BudgetEnvelope[] = [
    {
        envelope_id: 1,
        name: 'Groceries',
        color: '#22c55e',
        direction: 'expense',
        amount: -500,
        type: 'fixed',
        revision: 1,
    },
];

const fixtures: Array<{ root: HTMLElement; unmount: () => void }> = [];

afterEach(() => {
    for (const fixture of fixtures) {
        fixture.unmount();
    }
    fixtures.length = 0;
});

function mount(editable: boolean) {
    const root = document.createElement('div');
    document.body.append(root);

    const app = createApp({
        render: () =>
            h(BudgetEnvelopeTable, {
                envelopes,
                currency: 'CAD',
                editable,
            }),
    });
    app.use(i18n);
    app.mount(root);

    fixtures.push({
        root,
        unmount: () => {
            app.unmount();
            root.remove();
        },
    });

    return root;
}

describe('BudgetEnvelopeTable currency typography', () => {
    it('uses the currency role for the read-only amount, not the envelope name', () => {
        const root = mount(false);
        const amount = root.querySelector(
            'tbody tr td:nth-child(3) .font-currency',
        );

        expect(amount?.textContent?.trim()).toBe('$500.00');
        expect(
            root
                .querySelector('tbody tr td:first-child')
                ?.querySelector('.font-currency'),
        ).toBeNull();
    });

    it('uses the currency role for the editable amount input', () => {
        const root = mount(true);
        const input = root.querySelector(
            'tbody tr td:nth-child(3) input',
        ) as HTMLInputElement;

        expect(input).toBeTruthy();
        expect(input.classList.contains('font-currency')).toBe(true);
        expect(input.classList.contains('tabular-nums')).toBe(true);
    });
});
