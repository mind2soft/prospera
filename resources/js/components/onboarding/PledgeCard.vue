<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import Postmark from '@/components/onboarding/Postmark.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import {
    PLEDGE_ITEMS,
    type PledgeItem,
    usePledge,
} from '@/composables/usePledge';
import { formatCalendarDate } from '@/lib/calendar';

/** "Make it official": a small commitment device, kept in this browser. */
const props = defineProps<{ firstName: string }>();

const { locale } = useLocale();
const { pledge, sign } = usePledge();

const id = useId();
const editing = ref(false);
const justSigned = ref(false);
const selected = ref<PledgeItem[]>([]);

watch(
    pledge,
    (value) => {
        if (value !== null && !editing.value) {
            selected.value = [...value.items];
        }
    },
    { immediate: true },
);

const showForm = computed(() => pledge.value === null || editing.value);

const toggle = (item: PledgeItem, checked: boolean | 'indeterminate'): void => {
    selected.value =
        checked === true
            ? [...new Set([...selected.value, item])]
            : selected.value.filter((value) => value !== item);
};

const today = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const submit = (): void => {
    if (selected.value.length === 0) {
        return;
    }
    sign(
        PLEDGE_ITEMS.filter((item) => selected.value.includes(item)),
        today(),
    );
    editing.value = false;
    justSigned.value = true;
};

const signedOn = computed(() =>
    pledge.value
        ? formatCalendarDate(pledge.value.signedAt, locale.value, {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '',
);
</script>

<template>
    <div class="rounded-xl border bg-card p-5 sm:p-6">
        <h2 id="pledge-title" class="text-xl leading-7 font-semibold">
            {{ $t('onboarding.pledge.title') }}
        </h2>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ $t('onboarding.pledge.description') }}
        </p>

        <form v-if="showForm" class="mt-4" @submit.prevent="submit">
            <fieldset>
                <legend class="sr-only">
                    {{ $t('onboarding.pledge.legend') }}
                </legend>
                <div
                    v-for="item in PLEDGE_ITEMS"
                    :key="item"
                    class="flex min-h-11 items-center gap-3"
                >
                    <Checkbox
                        :id="`${id}-${item}`"
                        :model-value="selected.includes(item)"
                        @update:model-value="toggle(item, $event)"
                    />
                    <Label
                        :for="`${id}-${item}`"
                        class="py-2 text-sm leading-5 font-normal"
                    >
                        {{ $t(`onboarding.pledge.items.${item}`) }}
                    </Label>
                </div>
            </fieldset>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    class="gap-2"
                    :disabled="selected.length === 0"
                    :aria-describedby="
                        selected.length === 0 ? `${id}-helper` : undefined
                    "
                >
                    <Icon name="draw" class="size-4" aria-hidden="true" />
                    {{ $t('onboarding.pledge.sign') }}
                </Button>
                <p
                    v-if="selected.length === 0"
                    :id="`${id}-helper`"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('onboarding.pledge.helper') }}
                </p>
            </div>
        </form>

        <div v-else-if="pledge" class="mt-4 grid gap-4">
            <ul class="grid gap-2">
                <li
                    v-for="item in pledge.items"
                    :key="item"
                    class="flex items-start gap-2.5 text-sm"
                >
                    <Icon
                        name="check"
                        class="mt-0.5 size-4 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    {{ $t(`onboarding.pledge.items.${item}`) }}
                </li>
            </ul>
            <div class="flex items-center gap-4">
                <Postmark icon="draw" size="md" :animate="justSigned" />
                <div class="min-w-0">
                    <p class="font-display text-2xl leading-8">
                        — {{ props.firstName }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('onboarding.pledge.signedOn', { date: signedOn })
                        }}
                    </p>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ $t('onboarding.pledge.savedHere') }}
            </p>
            <Button
                variant="ghost"
                class="justify-self-start"
                @click="editing = true"
            >
                {{ $t('onboarding.pledge.change') }}
            </Button>
        </div>
    </div>
</template>
