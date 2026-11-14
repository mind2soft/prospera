<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import HelpArticle from '@/components/help/HelpArticle.vue';
import type { GuideTopic } from '@/lib/helpGuides';

const props = defineProps<{ topic: GuideTopic }>();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'onboarding.title', href: '/onboarding' },
            {
                title: `help.${props.topic}.guideName`,
                href: `/help/${props.topic}`,
            },
        ],
    });
});
</script>

<template>
    <Head :title="$t(`help.${topic}.guideName`)" />
    <HelpArticle :key="topic" :topic="topic" />
</template>
