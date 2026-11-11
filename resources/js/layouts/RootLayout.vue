<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
</script>

<template>
    <slot />
    <!-- Persistent across every page and layout: a toast raised right before
         a visit (or flashed onto an auth page) is never dropped. -->
    <Toaster :container-aria-label="$t('layout.notifications')" />
</template>
