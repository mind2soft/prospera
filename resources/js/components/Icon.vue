<script setup lang="ts">
import type { IconProps } from '@iconify/vue';
import { Icon as IconifyIcon } from '@iconify/vue';
import { computed } from 'vue';
import type { MaterialSymbol } from '@/lib/materialSymbols';

const props = defineProps<{
    /** A bundled Material Symbols icon (lib/materialSymbols.ts). */
    name: MaterialSymbol;
    width?: IconProps['width'];
    height?: IconProps['height'];
}>();

const iconName = computed(() => `material-symbols:${props.name}`);
</script>

<template>
    <IconifyIcon :icon="iconName" :width="width" :height="height" />
</template>
