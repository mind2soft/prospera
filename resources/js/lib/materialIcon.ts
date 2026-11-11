import type { FunctionalComponent } from 'vue';
import { h } from 'vue';
import Icon from '@/components/Icon.vue';
import type { MaterialSymbol } from '@/lib/materialSymbols';

export const materialSymbol = (name: MaterialSymbol): FunctionalComponent => {
    return () => h(Icon, { name });
};
