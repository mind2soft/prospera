import type { FunctionalComponent } from 'vue';
import { h } from 'vue';
import Icon from '@/components/Icon.vue';

export const materialSymbol = (name: string): FunctionalComponent => {
    return () => h(Icon, { name });
};
