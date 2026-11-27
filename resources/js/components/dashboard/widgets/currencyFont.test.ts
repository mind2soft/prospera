// @vitest-environment happy-dom

import { afterEach, describe, expect, it, vi } from 'vitest';
import { type Component, createApp, h } from 'vue';
import EnvelopeSpendingWidget from '@/components/dashboard/widgets/EnvelopeSpendingWidget.vue';
import IncomeExpensesWidget from '@/components/dashboard/widgets/IncomeExpensesWidget.vue';
import RadialWidget from '@/components/dashboard/widgets/RadialWidget.vue';
import { i18n } from '@/i18n';

// Render the text equivalents instead of the Unovis SVG, which needs layout.
vi.mock('@/lib/ssr', () => ({ isClient: false }));

const fixtures: Array<{ root: HTMLElement; unmount: () => void }> = [];

afterEach(() => {
    for (const fixture of fixtures) {
        fixture.unmount();
    }
    fixtures.length = 0;
});

function mount(component: Component, props: Record<string, unknown>) {
    const root = document.createElement('div');
    document.body.append(root);

    const app = createApp({ render: () => h(component, props) });
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

describe('IncomeExpensesWidget currency typography', () => {
    it('uses the currency role for data-table amounts but not period labels', () => {
        const root = mount(IncomeExpensesWidget, {
            viewType: 'stacked_bars',
            currency: 'CAD',
            data: {
                type: 'income_expenses',
                masked: false,
                series: [
                    {
                        bucket: '2026-11',
                        masked: false,
                        revenue: 2400,
                        expenses: 860.45,
                    },
                ],
            },
        });

        const cells = [...root.querySelectorAll('tbody td')];
        expect(cells).toHaveLength(2);
        for (const cell of cells) {
            expect(cell.classList.contains('font-currency')).toBe(true);
        }

        const period = root.querySelector('tbody th');
        expect(period?.textContent?.trim()).not.toBe('');
        expect(period?.classList.contains('font-currency')).toBe(false);
    });

    it('does not give the currency role to a masked amount placeholder', () => {
        const root = mount(IncomeExpensesWidget, {
            viewType: 'stacked_bars',
            currency: 'CAD',
            data: {
                type: 'income_expenses',
                masked: false,
                series: [
                    {
                        bucket: '2026-11',
                        masked: true,
                        revenue: null,
                        expenses: null,
                    },
                ],
            },
        });

        const cells = [...root.querySelectorAll('tbody td')];
        expect(cells).toHaveLength(2);
        for (const cell of cells) {
            expect(cell.classList.contains('font-currency')).toBe(false);
            expect(cell.textContent).toContain('hidden');
        }
    });
});

describe('RadialWidget currency typography', () => {
    it('uses the currency role for visible legend amounts only', () => {
        const root = mount(RadialWidget, {
            viewType: 'donut',
            currency: 'CAD',
            data: {
                type: 'radial',
                rows: [
                    {
                        key: 'groceries',
                        kind: 'envelope',
                        name: 'Groceries',
                        masked: false,
                        value: 430,
                    },
                    {
                        key: 'rent',
                        kind: 'envelope',
                        name: 'Rent',
                        masked: true,
                        value: null,
                    },
                ],
            },
        });

        const amount = root.querySelector(
            'li span.font-currency',
        ) as HTMLSpanElement;
        expect(amount.textContent).toBe('$430.00');

        // Masked marker stays in the interface font and is not a currency label.
        const maskedMarker = [...root.querySelectorAll('li span')].find(
            (span) => span.textContent === '•••',
        );
        expect(maskedMarker).toBeTruthy();
        expect(maskedMarker?.classList.contains('font-currency')).toBe(false);

        // Shares and names are not currency labels.
        const share = [...root.querySelectorAll('li span')].find((span) =>
            span.textContent?.includes('·'),
        );
        expect(share?.classList.contains('font-currency')).toBe(false);
    });
});

describe('EnvelopeSpendingWidget currency typography', () => {
    it('uses the currency role for spent/allocated but not the masked marker', () => {
        const root = mount(EnvelopeSpendingWidget, {
            currency: 'CAD',
            data: {
                type: 'envelope_spending',
                rows: [
                    {
                        key: 'groceries',
                        kind: 'envelope',
                        name: 'Groceries',
                        masked: false,
                        spent: 430,
                        allocated: 500,
                    },
                    {
                        key: 'rent',
                        kind: 'envelope',
                        name: 'Rent',
                        masked: true,
                        spent: null,
                        allocated: null,
                    },
                ],
            },
        });

        const currencySpans = [...root.querySelectorAll('span.font-currency')];
        expect(currencySpans.map((span) => span.textContent)).toEqual([
            '$430.00',
            '$500.00',
        ]);

        const maskedMarker = [...root.querySelectorAll('span')].find(
            (span) => span.textContent === '•••',
        );
        expect(maskedMarker).toBeTruthy();
        expect(maskedMarker?.classList.contains('font-currency')).toBe(false);
    });
});
