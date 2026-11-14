<script setup lang="ts">
import { useId } from 'vue';
import Icon from '@/components/Icon.vue';

defineProps<{ dos: string[]; avoids: string[] }>();

const id = useId();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <section
            class="rounded-xl border bg-card p-5"
            :aria-labelledby="`${id}-do`"
        >
            <h3
                :id="`${id}-do`"
                class="inline-flex items-center gap-2 text-base font-medium"
            >
                <Icon
                    name="check-circle"
                    class="size-5 text-success"
                    aria-hidden="true"
                />
                {{ $t('help.common.do') }}
            </h3>
            <ul class="mt-3 grid gap-2 text-sm leading-5">
                <li v-for="item in dos" :key="item" class="flex gap-2">
                    <Icon
                        name="check"
                        class="mt-0.5 size-4 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    {{ item }}
                </li>
            </ul>
        </section>
        <section
            class="rounded-xl border bg-card p-5"
            :aria-labelledby="`${id}-avoid`"
        >
            <h3
                :id="`${id}-avoid`"
                class="inline-flex items-center gap-2 text-base font-medium"
            >
                <Icon
                    name="do-not-disturb-on-outline"
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ $t('help.common.avoid') }}
            </h3>
            <ul class="mt-3 grid gap-2 text-sm leading-5">
                <li v-for="item in avoids" :key="item" class="flex gap-2">
                    <Icon
                        name="close"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ item }}
                </li>
            </ul>
        </section>
    </div>
</template>
