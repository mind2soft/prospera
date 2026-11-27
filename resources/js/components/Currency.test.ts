// @vitest-environment happy-dom

import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h } from 'vue';
import Currency from '@/components/Currency.vue';
import { i18n } from '@/i18n';
import { formatMoney } from '@/lib/budget';

/**
 * Currency labels use the dedicated currency typeface without changing the
 * locale-formatted value, sign presentation or numeric alignment.
 */
describe('Currency', () => {
    const fixtures: Array<{ root: HTMLElement; unmount: () => void }> = [];

    afterEach(() => {
        i18n.global.locale.value = 'en';
        for (const fixture of fixtures) {
            fixture.unmount();
        }
        fixtures.length = 0;
    });

    function mount(amount: number, currency = 'CAD') {
        const root = document.createElement('div');
        document.body.append(root);

        const app = createApp({
            render: () => h(Currency, { amount, currency }),
        });
        app.use(i18n);
        app.mount(root);

        const fixture = {
            root,
            unmount: () => {
                app.unmount();
                root.remove();
            },
        };
        fixtures.push(fixture);

        return root.querySelector('span') as HTMLSpanElement;
    }

    it('applies the currency font role and tabular numerals', () => {
        const span = mount(1234.56);

        expect(span.classList.contains('font-currency')).toBe(true);
        expect(span.classList.contains('tabular-nums')).toBe(true);
    });

    it('keeps the locale-aware formatted value unchanged', () => {
        const span = mount(1234.56);

        expect(span.textContent).toBe(formatMoney(1234.56, 'CAD', 'en'));
        expect(span.textContent).toBe('$1,234.56');
    });

    it('keeps the negative parentheses and destructive tone', () => {
        const span = mount(-42);

        expect(span.textContent).toBe('($42.00)');
        expect(span.classList.contains('text-destructive')).toBe(true);
    });

    it('keeps locale formatting and sign presentation in French', () => {
        i18n.global.locale.value = 'fr';

        const positive = mount(1234.56);
        const negative = mount(-42);

        expect(positive.textContent).toBe(formatMoney(1234.56, 'CAD', 'fr'));
        expect(positive.textContent).not.toBe(
            formatMoney(1234.56, 'CAD', 'en'),
        );
        expect(negative.textContent).toBe(`(${formatMoney(42, 'CAD', 'fr')})`);
        expect(negative.classList.contains('font-currency')).toBe(true);
    });
});
