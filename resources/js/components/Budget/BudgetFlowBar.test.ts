// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h } from 'vue';
import BudgetFlowBar from '@/components/Budget/BudgetFlowBar.vue';
import { i18n } from '@/i18n';

/**
 * Only the currency values receive the currency typeface; the adjacent
 * section title and envelope names stay in the interface font.
 */
describe('BudgetFlowBar', () => {
    const fixtures: Array<{ root: HTMLElement; unmount: () => void }> = [];

    afterEach(() => {
        for (const fixture of fixtures) {
            fixture.unmount();
        }
        fixtures.length = 0;
    });

    function mount() {
        const root = document.createElement('div');
        document.body.append(root);

        const app = createApp({
            render: () =>
                h(BudgetFlowBar, {
                    title: 'Spending',
                    max: 100,
                    currency: 'CAD',
                    segments: [
                        {
                            id: 1,
                            name: 'Groceries',
                            color: '#ff0000',
                            amount: 40,
                        },
                    ],
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

    it('uses the currency font for the total and segment amount only', () => {
        const root = mount();
        const heading = root.querySelector('h2');
        const amounts = root.querySelectorAll('span.font-currency');

        expect(heading?.classList.contains('font-currency')).toBe(false);
        expect(heading?.textContent).toContain('Spending');
        expect(amounts).toHaveLength(2);
        expect(amounts[0]?.textContent).toContain('$40.00');
        expect(amounts[1]?.textContent).toContain('$40.00');

        const names = [...root.querySelectorAll('li span')].filter(
            (span) => span.textContent === 'Groceries',
        );
        expect(names).toHaveLength(1);
        expect(names[0]?.classList.contains('font-currency')).toBe(false);
    });
});
