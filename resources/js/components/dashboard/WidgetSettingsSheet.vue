<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useEnvelopeOptions } from '@/composables/envelopeQueries';
import type {
    DashboardWidgetConfig,
    DashboardWidgetType,
    DashboardWidgetViewType,
    Envelope,
} from '@/types';

const props = defineProps<{
    open: boolean;
    widget: DashboardWidgetConfig | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    /** A settings patch to merge into the widget configuration. */
    update: [patch: Partial<DashboardWidgetConfig>];
    remove: [];
}>();

const envelopesQuery = useEnvelopeOptions();
const envelopes = computed(() => envelopesQuery.data.value ?? []);

/** The view types a widget type offers (docs/dashboard.md). */
const VIEW_TYPES: Record<DashboardWidgetType, DashboardWidgetViewType[]> = {
    envelope_spending: ['single'],
    income_expenses: ['stacked_bars', 'line'],
    radial: ['pie', 'donut'],
};

const PERIOD_RANGES: Record<'days' | 'months', number[]> = {
    days: [1, 2, 3, 7, 14],
    months: [1, 2, 3, 6, 12],
};

const viewTypes = computed(() =>
    props.widget ? VIEW_TYPES[props.widget.type] : [],
);

const periodRanges = computed(() =>
    props.widget ? PERIOD_RANGES[props.widget.period.type] : [],
);

const titleValue = computed({
    get: () => props.widget?.title ?? '',
    set: (value: string) => {
        const trimmed = value.trim();

        emit('update', { title: trimmed === '' ? null : trimmed });
    },
});

const onPeriodTypeChange = (value: unknown): void => {
    const type = String(value);

    if (
        !props.widget ||
        (type !== 'days' && type !== 'months') ||
        type === props.widget.period.type
    ) {
        return;
    }

    // Reset the range to the new type's default when the enumeration differs.
    const ranges = PERIOD_RANGES[type];
    const range = ranges.includes(props.widget.period.range)
        ? props.widget.period.range
        : ranges[0];

    emit('update', { period: { type, range } });
};

const onRangeChange = (value: unknown): void => {
    if (!props.widget) {
        return;
    }

    emit('update', {
        period: { type: props.widget.period.type, range: Number(value) },
    });
};

// ---- Selection ------------------------------------------------------------

/** Envelopes not already used anywhere in the widget's selection. */
const unusedEnvelopes = computed<Envelope[]>(() => {
    if (!props.widget) {
        return [];
    }

    const used = new Set<number>();

    for (const item of props.widget.selection) {
        if (item.kind === 'envelope') {
            used.add(item.envelope_id);
        } else {
            for (const id of item.envelope_ids) {
                used.add(id);
            }
        }
    }

    return envelopes.value.filter((envelope) => !used.has(envelope.id));
});

const unusedForGroup = computed<(index: number) => Envelope[]>(
    () => (index) => {
        if (!props.widget) {
            return [];
        }

        const group = props.widget.selection[index];

        if (group?.kind !== 'group') {
            return [];
        }

        const used = new Set(group.envelope_ids);

        return unusedEnvelopes.value.filter(
            (envelope) => !used.has(envelope.id),
        );
    },
);

const envelopeName = (id: number): string =>
    envelopes.value.find((envelope) => envelope.id === id)?.name ?? '?';

const envelopeColor = (id: number): string =>
    envelopes.value.find((envelope) => envelope.id === id)?.color ??
    'var(--muted-foreground)';

const addEnvelopeValue = ref<string>('');

watch(
    () => props.open,
    () => {
        addEnvelopeValue.value = '';
        creatingGroup.value = false;
        groupName.value = '';
        groupMembers.value = [];
    },
);

const addEnvelope = (): void => {
    const id = Number(addEnvelopeValue.value);

    if (!props.widget || !Number.isInteger(id) || id <= 0) {
        return;
    }

    emit('update', {
        selection: [
            ...props.widget.selection,
            { kind: 'envelope', envelope_id: id },
        ],
    });

    addEnvelopeValue.value = '';
};

const removeItem = (index: number): void => {
    if (!props.widget) {
        return;
    }

    const selection = [...props.widget.selection];

    selection.splice(index, 1);

    emit('update', { selection });
};

const updateGroup = (
    index: number,
    patch: { name?: string; envelope_ids?: number[] },
): void => {
    if (!props.widget) {
        return;
    }

    const item = props.widget.selection[index];

    if (item?.kind !== 'group') {
        return;
    }

    const selection = [...props.widget.selection];

    selection[index] = {
        kind: 'group',
        name: patch.name ?? item.name,
        envelope_ids: patch.envelope_ids ?? [...item.envelope_ids],
    };

    emit('update', { selection });
};

const addToGroup = (index: number, envelopeId: number): void => {
    const group = props.widget?.selection[index];

    if (group?.kind !== 'group' || group.envelope_ids.includes(envelopeId)) {
        return;
    }

    updateGroup(index, { envelope_ids: [...group.envelope_ids, envelopeId] });
};

const removeFromGroup = (index: number, envelopeId: number): void => {
    const group = props.widget?.selection[index];

    if (group?.kind !== 'group') {
        return;
    }

    updateGroup(index, {
        envelope_ids: group.envelope_ids.filter((id) => id !== envelopeId),
    });
};

// Group creation (flat, single level — groups cannot contain groups).
const creatingGroup = ref(false);
const groupName = ref('');
const groupMembers = ref<number[]>([]);
/** Per-group "add member" select values, keyed by selection index. */
const groupAddValue = ref<Record<number, string>>({});

const startGroup = (): void => {
    creatingGroup.value = true;
    groupName.value = '';
    groupMembers.value = [];
};

const createGroup = (): void => {
    const name = groupName.value.trim();

    if (!props.widget || name === '' || groupMembers.value.length === 0) {
        return;
    }

    emit('update', {
        selection: [
            ...props.widget.selection,
            { kind: 'group', name, envelope_ids: [...groupMembers.value] },
        ],
    });

    creatingGroup.value = false;
    groupName.value = '';
    groupMembers.value = [];
};

const hasUnusedEnvelopes = computed(() => unusedEnvelopes.value.length > 0);
</script>

<template>
    <Sheet
        :open="open"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <SheetContent
            v-if="widget"
            class="flex flex-col gap-6 overflow-y-auto sm:max-w-md"
        >
            <SheetHeader>
                <SheetTitle>{{ $t('dashboard.widget.settings') }}</SheetTitle>
                <SheetDescription>
                    {{
                        widget.title ??
                        $t(`dashboard.widget.defaultTitle.${widget.type}`)
                    }}
                </SheetDescription>
            </SheetHeader>

            <!-- Common settings (docs/dashboard.md) -->
            <section class="space-y-2">
                <Label for="widget-title">{{
                    $t('dashboard.settings.title')
                }}</Label>
                <Input
                    id="widget-title"
                    v-model="titleValue"
                    :placeholder="$t('dashboard.settings.titlePlaceholder')"
                    :maxlength="60"
                />
            </section>

            <section class="space-y-2">
                <Label>{{ $t('dashboard.settings.viewType') }}</Label>
                <Select
                    :model-value="widget.view_type"
                    @update:model-value="
                        (value: unknown) =>
                            emit('update', {
                                view_type: String(
                                    value,
                                ) as DashboardWidgetViewType,
                            })
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-full"
                        :aria-label="$t('dashboard.settings.viewType')"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="viewType in viewTypes"
                            :key="viewType"
                            :value="viewType"
                        >
                            {{ $t(`dashboard.settings.viewTypes.${viewType}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </section>

            <section class="space-y-2">
                <Label>{{ $t('dashboard.settings.columnWidth') }}</Label>
                <Select
                    :model-value="String(widget.column_width)"
                    @update:model-value="
                        (value: unknown) =>
                            emit('update', { column_width: Number(value) })
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-full"
                        :aria-label="$t('dashboard.settings.columnWidth')"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="width in [1, 2, 3, 4, 5]"
                            :key="width"
                            :value="String(width)"
                        >
                            {{ width }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </section>

            <section class="grid grid-cols-2 gap-3">
                <div class="space-y-2">
                    <Label>{{ $t('dashboard.settings.periodType') }}</Label>
                    <Select
                        :model-value="widget.period.type"
                        @update:model-value="onPeriodTypeChange"
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full"
                            :aria-label="$t('dashboard.settings.periodType')"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="days">
                                {{ $t('dashboard.settings.periodTypes.days') }}
                            </SelectItem>
                            <SelectItem value="months">
                                {{
                                    $t('dashboard.settings.periodTypes.months')
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-2">
                    <Label>{{ $t('dashboard.settings.periodRange') }}</Label>
                    <Select
                        :key="widget.period.type"
                        :model-value="String(widget.period.range)"
                        @update:model-value="onRangeChange"
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full"
                            :aria-label="$t('dashboard.settings.periodRange')"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="range in periodRanges"
                                :key="range"
                                :value="String(range)"
                            >
                                {{
                                    $t(
                                        `dashboard.settings.ranges.${widget.period.type}.${range}`,
                                    )
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </section>

            <Separator />

            <!-- Envelope selection -->
            <section class="space-y-3">
                <Label>{{ $t('dashboard.settings.selection') }}</Label>

                <ul class="space-y-2">
                    <li
                        v-for="(item, index) in widget.selection"
                        :key="index"
                        class="rounded-lg border p-2.5"
                    >
                        <div
                            v-if="item.kind === 'envelope'"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: envelopeColor(
                                        item.envelope_id,
                                    ),
                                }"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate text-sm">
                                {{ envelopeName(item.envelope_id) }}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="
                                    $t('dashboard.settings.removeItem')
                                "
                                @click="removeItem(index)"
                            >
                                <Icon
                                    name="delete"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </div>

                        <div v-else class="space-y-2">
                            <div class="flex items-center gap-2">
                                <Icon
                                    name="check-indeterminate-small"
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    :model-value="item.name"
                                    class="h-8 text-sm"
                                    :maxlength="60"
                                    :aria-label="
                                        $t('dashboard.settings.groupName')
                                    "
                                    @update:model-value="
                                        (value: string | number) =>
                                            updateGroup(index, {
                                                name: String(value),
                                            })
                                    "
                                />
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="
                                        $t('dashboard.settings.removeItem')
                                    "
                                    @click="removeItem(index)"
                                >
                                    <Icon
                                        name="delete"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </div>

                            <ul class="flex flex-wrap gap-1.5">
                                <li
                                    v-for="envelopeId in item.envelope_ids"
                                    :key="envelopeId"
                                    class="flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2 text-xs"
                                >
                                    <span
                                        class="size-2 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                envelopeColor(envelopeId),
                                        }"
                                        aria-hidden="true"
                                    />
                                    {{ envelopeName(envelopeId) }}
                                    <button
                                        type="button"
                                        class="rounded-full p-0.5 text-muted-foreground hover:text-destructive"
                                        :aria-label="`${$t('dashboard.settings.removeItem')} — ${envelopeName(envelopeId)}`"
                                        @click="
                                            removeFromGroup(index, envelopeId)
                                        "
                                    >
                                        <Icon
                                            name="close"
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </li>
                            </ul>

                            <Select
                                v-if="unusedForGroup(index).length > 0"
                                :model-value="''"
                                @update:model-value="
                                    (value: unknown) => {
                                        const id = Number(value);

                                        if (Number.isInteger(id) && id > 0) {
                                            addToGroup(index, id);
                                            groupAddValue[index] = '';
                                        }
                                    }
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    class="w-full text-muted-foreground"
                                    :aria-label="
                                        $t('dashboard.settings.addEnvelope')
                                    "
                                >
                                    {{ $t('dashboard.settings.addEnvelope') }}
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="envelope in unusedForGroup(
                                            index,
                                        )"
                                        :key="envelope.id"
                                        :value="String(envelope.id)"
                                    >
                                        {{ envelope.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </li>
                </ul>

                <!-- Add an envelope row -->
                <Select
                    v-if="hasUnusedEnvelopes"
                    :model-value="addEnvelopeValue"
                    @update:model-value="
                        (value: unknown) => {
                            addEnvelopeValue = String(value);
                            addEnvelope();
                        }
                    "
                >
                    <SelectTrigger
                        size="sm"
                        class="w-full text-muted-foreground"
                        :aria-label="$t('dashboard.settings.addEnvelope')"
                    >
                        {{ $t('dashboard.settings.addEnvelope') }}
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="envelope in unusedEnvelopes"
                            :key="envelope.id"
                            :value="String(envelope.id)"
                        >
                            {{ envelope.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <p v-else class="text-xs text-muted-foreground">
                    {{ $t('dashboard.settings.noEnvelopesLeft') }}
                </p>

                <!-- Group creation -->
                <div
                    v-if="creatingGroup"
                    class="space-y-2 rounded-lg border border-dashed p-2.5"
                >
                    <Input
                        v-model="groupName"
                        :aria-label="$t('dashboard.settings.groupName')"
                        :placeholder="
                            $t('dashboard.settings.groupNamePlaceholder')
                        "
                        :maxlength="60"
                    />
                    <Select
                        :model-value="''"
                        @update:model-value="
                            (value: unknown) => {
                                const id = Number(value);

                                if (Number.isInteger(id) && id > 0) {
                                    groupMembers.push(id);
                                }
                            }
                        "
                    >
                        <SelectTrigger
                            size="sm"
                            class="w-full text-muted-foreground"
                            :aria-label="$t('dashboard.settings.addEnvelope')"
                        >
                            {{ $t('dashboard.settings.addEnvelope') }}
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="envelope in unusedEnvelopes.filter(
                                    (envelope) =>
                                        !groupMembers.includes(envelope.id),
                                )"
                                :key="envelope.id"
                                :value="String(envelope.id)"
                            >
                                {{ envelope.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <p
                        v-if="groupMembers.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ groupMembers.map(envelopeName).join(', ') }}
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="creatingGroup = false"
                        >
                            {{ $t('dashboard.removeConfirm.cancel') }}
                        </Button>
                        <Button
                            size="sm"
                            :disabled="
                                groupName.trim() === '' ||
                                groupMembers.length === 0
                            "
                            @click="createGroup"
                        >
                            {{ $t('dashboard.settings.addGroup') }}
                        </Button>
                    </div>
                </div>

                <Button
                    v-else-if="hasUnusedEnvelopes"
                    variant="outline"
                    size="sm"
                    class="w-full"
                    @click="startGroup"
                >
                    <Icon
                        name="check-indeterminate-small"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ $t('dashboard.settings.addGroup') }}
                </Button>
            </section>

            <div class="mt-auto space-y-2 pt-2">
                <Separator />
                <Button
                    variant="outline"
                    class="w-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                    @click="emit('remove')"
                >
                    <Icon name="delete" class="size-4" aria-hidden="true" />
                    {{ $t('dashboard.widget.remove') }}
                </Button>
                <Button class="w-full" @click="emit('update:open', false)">
                    {{ $t('dashboard.settings.done') }}
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
