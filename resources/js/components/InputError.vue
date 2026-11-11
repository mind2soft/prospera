<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { focusFirstInvalidField, linkFieldError } from '@/lib/fieldErrors';

const props = defineProps<{
    message?: string;
    /** Id of the field the message belongs to (linked for assistive tech). */
    for?: string;
}>();

const errorId = computed(() => (props.for ? `${props.for}-error` : undefined));

const sync = (message: string | undefined, previous?: string) => {
    if (!props.for || !errorId.value) {
        return;
    }
    linkFieldError(props.for, errorId.value, Boolean(message));
    if (message && message !== previous) {
        focusFirstInvalidField();
    }
};

onMounted(() => {
    watch(
        () => props.message,
        (message, previous) => sync(message, previous),
        { immediate: true, flush: 'post' },
    );
});

onBeforeUnmount(() => sync(undefined));
</script>

<template>
    <p
        v-if="message"
        :id="errorId"
        class="text-sm text-destructive"
        role="alert"
    >
        {{ message }}
    </p>
</template>
