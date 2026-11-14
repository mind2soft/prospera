<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from '@/components/Icon.vue';
import { STARTER_ENVELOPES, type StarterDirection } from '@/lib/helpGuides';

const props = withDefaults(
    defineProps<{
        /** Names the owner already uses: shown as created, not offered. */
        existingNames?: string[];
        /** `link` opens the Envelopes page prefilled; `emit` lets the page do it. */
        mode?: 'link' | 'emit' | 'static';
        /** Only the first `limit` suggestions not created yet (compact). */
        limit?: number;
        grouped?: boolean;
        /** Compact mode lists this direction's suggestions first. */
        prefer?: StarterDirection;
    }>(),
    {
        existingNames: () => [],
        mode: 'link',
        limit: undefined,
        grouped: true,
        prefer: undefined,
    },
);

const emit = defineEmits<{
    pick: [suggestion: { name: string; direction: StarterDirection }];
}>();

const { t } = useI18n();

const owned = computed(
    () => new Set(props.existingNames.map((name) => name.trim().toLowerCase())),
);

type Chip = {
    key: string;
    name: string;
    direction: StarterDirection;
    owned: boolean;
};

const groups = computed(() => {
    const all = (Object.keys(STARTER_ENVELOPES) as StarterDirection[]).map(
        (direction) => ({
            direction,
            chips: STARTER_ENVELOPES[direction].map((key): Chip => {
                const name = t(`onboarding.starter.${direction}.${key}`);
                return {
                    key,
                    name,
                    direction,
                    owned: owned.value.has(name.toLowerCase()),
                };
            }),
        }),
    );

    if (props.limit === undefined) {
        return all;
    }

    // Compact: the missing direction first, only suggestions not created yet.
    const flat = all
        .flatMap((group) => group.chips)
        .filter((chip) => !chip.owned)
        .sort(
            (a, b) =>
                Number(b.direction === props.prefer) -
                Number(a.direction === props.prefer),
        )
        .slice(0, props.limit);

    return [{ direction: null, chips: flat }];
});

const href = (chip: Chip): string =>
    `/envelopes?new=${chip.direction}&name=${encodeURIComponent(chip.name)}`;

const chipClass =
    'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-sm font-medium';
const interactiveClass =
    'bg-background text-foreground outline-none transition-colors hover:border-brand hover:bg-brand-container hover:text-brand-container-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
</script>

<template>
    <div class="grid gap-4">
        <div
            v-for="group in groups"
            :key="group.direction ?? 'compact'"
            class="grid gap-2"
        >
            <p
                v-if="grouped && group.direction"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
            >
                <Icon
                    :name="group.direction === 'revenue' ? 'north' : 'south'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{
                    group.direction === 'revenue'
                        ? $t('onboarding.starter.comingIn')
                        : $t('onboarding.starter.goingOut')
                }}
            </p>
            <ul class="flex flex-wrap gap-2">
                <li v-for="chip in group.chips" :key="chip.key">
                    <span
                        v-if="chip.owned"
                        :class="chipClass"
                        class="border-transparent bg-muted text-muted-foreground"
                    >
                        <Icon
                            name="check"
                            class="size-4 text-success"
                            aria-hidden="true"
                        />
                        <span class="sr-only">{{
                            $t('onboarding.starter.owned', { name: chip.name })
                        }}</span>
                        <span aria-hidden="true">{{ chip.name }}</span>
                    </span>
                    <span
                        v-else-if="mode === 'static'"
                        :class="chipClass"
                        class="bg-background text-foreground"
                    >
                        <Icon
                            :name="
                                chip.direction === 'revenue' ? 'north' : 'south'
                            "
                            class="size-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ chip.name }}
                    </span>
                    <button
                        v-else-if="mode === 'emit'"
                        type="button"
                        :class="[chipClass, interactiveClass]"
                        :aria-label="
                            $t('onboarding.starter.add', { name: chip.name })
                        "
                        @click="
                            emit('pick', {
                                name: chip.name,
                                direction: chip.direction,
                            })
                        "
                    >
                        <Icon name="add" class="size-4" aria-hidden="true" />
                        {{ chip.name }}
                    </button>
                    <Link
                        v-else
                        :href="href(chip)"
                        :class="[chipClass, interactiveClass]"
                        :aria-label="
                            $t('onboarding.starter.add', { name: chip.name })
                        "
                    >
                        <Icon name="add" class="size-4" aria-hidden="true" />
                        {{ chip.name }}
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
