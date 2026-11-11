<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * Error state for a failed server read: names what failed and offers a
 * local retry (docs/design-system/components.md → Content states). Never
 * fall through to an empty state on error — it would invite the user to
 * re-create data that exists.
 */
defineProps<{
    message: string;
    retrying?: boolean;
}>();

const emit = defineEmits<{ retry: [] }>();
</script>

<template>
    <Card class="border-destructive/30" data-test="query-error">
        <CardContent
            class="flex flex-col items-center gap-3 py-10 text-center"
            role="alert"
        >
            <AlertCircle class="size-6 text-destructive" aria-hidden="true" />
            <p class="text-sm">{{ message }}</p>
            <Button
                variant="outline"
                size="sm"
                :disabled="retrying"
                @click="emit('retry')"
            >
                {{ $t('common.actions.retry') }}
            </Button>
        </CardContent>
    </Card>
</template>
