import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';

/**
 * Chart animation duration honoring `prefers-reduced-motion`: Unovis
 * animates with JavaScript (d3 transitions), out of reach of the global CSS
 * reduced-motion rule. `0` disables the transitions; `undefined` keeps the
 * library default.
 */
export function useChartDuration() {
    const reduce = useMediaQuery('(prefers-reduced-motion: reduce)');

    return computed(() => (reduce.value ? 0 : undefined));
}
