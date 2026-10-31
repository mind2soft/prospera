<script setup lang="ts">
import type { IconProps } from '@iconify/vue';
import { Icon as IconifyIcon } from '@iconify/vue';

const props = withDefaults(
    defineProps<{
        name: string;
        width?: IconProps['width'];
        height?: IconProps['height'];
        iconCollection?: string;
    }>(),
    {
        iconCollection: 'material-symbols',
    },
);

const iconName = props.name.includes(':')
    ? props.name
    : `${props.iconCollection}:${props.name}`;
</script>

<template>
    <IconifyIcon :icon="iconName" :width="width" :height="height" />
</template>
