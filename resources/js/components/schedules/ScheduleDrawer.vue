<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Currency from '@/components/Currency.vue';
import Icon from '@/components/Icon.vue';
import QueryErrorState from '@/components/QueryErrorState.vue';
import ScheduleDialog from '@/components/schedules/ScheduleDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useBudgets } from '@/composables/budgetQueries';
import {
    useDeleteSchedule,
    useEnvelopeSchedules,
} from '@/composables/scheduleQueries';
import { useLocale } from '@/composables/useLocale';
import { errorMessage } from '@/lib/api';
import { latestPublishedCurrency } from '@/lib/budget';
import {
    describeAffected,
    describeSchedule,
    minorToCurrencyUnits,
} from '@/lib/schedule';
import type { Envelope, EnvelopeSchedule } from '@/types';

/**
 * The owner-only schedules drawer of an envelope (Envelopes page, see
 * docs/scheduled-transactions.md): a view-only list with localized
 * summaries; creation and edits go through the schedule dialog.
 */
const props = defineProps<{
    /** The envelope whose schedules are shown; null closes the drawer. */
    envelope: Envelope | null;
}>();

const emit = defineEmits<(e: 'close') => void>();

const { t } = useI18n();
const { locale } = useLocale();

const schedulesQuery = useEnvelopeSchedules(
    computed(() => props.envelope?.id ?? null),
);
const budgetsQuery = useBudgets();
const remove = useDeleteSchedule();

/** The profile's latest published budget currency, like the Ledger's. */
const currency = computed(() =>
    latestPublishedCurrency(budgetsQuery.data.value ?? []),
);

const rows = computed(() =>
    (schedulesQuery.data.value ?? []).map((schedule) => ({
        schedule,
        summary: describeSchedule(schedule, locale.value, t),
    })),
);

// --- create / edit ----------------------------------------------------------
const dialogOpen = ref(false);
const editing = ref<EnvelopeSchedule | null>(null);

const openDialog = (schedule: EnvelopeSchedule | null) => {
    editing.value = schedule;
    dialogOpen.value = true;
};

// --- delete -----------------------------------------------------------------
const toDelete = ref<{ schedule: EnvelopeSchedule; summary: string } | null>(
    null,
);
const confirmingDelete = ref(false);
const deleted = ref(false);
const addButton = useTemplateRef<{ $el: HTMLElement }>('addButton');

const askDelete = (schedule: EnvelopeSchedule, summary: string) => {
    toDelete.value = { schedule, summary };
    deleted.value = false;
    confirmingDelete.value = true;
};

const confirmDelete = () => {
    const target = toDelete.value;
    if (target === null || remove.isPending.value) {
        return;
    }
    remove.mutate(
        {
            envelopeId: target.schedule.envelope_id,
            scheduleId: target.schedule.id,
        },
        {
            onSuccess: () => {
                deleted.value = true;
                confirmingDelete.value = false;
                toast.success(t('envelopes.schedules.toast.deleted'));
            },
            onError: (error) => {
                toast.error(
                    errorMessage(
                        error,
                        t('envelopes.schedules.toast.deleteFailed'),
                    ),
                );
            },
        },
    );
};

/** The deleted row's button is gone: move focus to a stable control. */
const restoreFocusAfterDelete = (event: Event) => {
    if (!deleted.value) {
        return;
    }
    event.preventDefault();
    addButton.value?.$el.focus();
};
</script>

<template>
    <Sheet
        :open="envelope !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <SheetContent class="flex flex-col gap-6 overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{
                    $t('envelopes.schedules.drawer.title')
                }}</SheetTitle>
                <SheetDescription>
                    {{
                        $t('envelopes.schedules.drawer.description', {
                            name: envelope?.name ?? '',
                        })
                    }}
                </SheetDescription>
            </SheetHeader>

            <div class="px-4">
                <Button
                    ref="addButton"
                    variant="outline"
                    class="w-full gap-2"
                    :disabled="schedulesQuery.isPending.value"
                    @click="openDialog(null)"
                >
                    <Icon name="add" class="size-4" aria-hidden="true" />
                    {{ $t('envelopes.schedules.drawer.add') }}
                </Button>
            </div>

            <section class="px-4">
                <QueryErrorState
                    v-if="schedulesQuery.isError.value"
                    :message="$t('envelopes.schedules.drawer.loadError')"
                    :retrying="schedulesQuery.isFetching.value"
                    @retry="schedulesQuery.refetch()"
                />

                <div
                    v-else-if="schedulesQuery.isPending.value"
                    class="space-y-3"
                    :aria-label="$t('common.loading')"
                    role="status"
                >
                    <Skeleton v-for="n in 2" :key="n" class="h-24 w-full" />
                </div>

                <p
                    v-else-if="rows.length === 0"
                    class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    {{ $t('envelopes.schedules.drawer.empty') }}
                </p>

                <ul
                    v-else
                    class="space-y-3"
                    :aria-label="
                        $t('envelopes.schedules.drawer.list', {
                            name: envelope?.name ?? '',
                        })
                    "
                >
                    <li
                        v-for="{ schedule, summary } in rows"
                        :key="schedule.id"
                        class="flex gap-3 rounded-xl border border-border p-3"
                        data-testid="schedule-row"
                    >
                        <Icon
                            name="event-repeat"
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1 space-y-1">
                            <div
                                class="flex items-baseline justify-between gap-3"
                            >
                                <p class="text-sm font-medium">
                                    {{ summary.recurrence }}
                                </p>
                                <Currency
                                    class="shrink-0 text-sm font-medium"
                                    :amount="
                                        minorToCurrencyUnits(schedule.amount)
                                    "
                                    :currency="currency"
                                />
                            </div>
                            <p
                                v-if="summary.range"
                                class="text-xs text-muted-foreground"
                            >
                                {{ summary.range }}
                            </p>
                            <p
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Icon
                                    name="notifications-outline"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ summary.reminder }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ summary.upcoming }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-1">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground"
                                :aria-label="
                                    $t('envelopes.schedules.drawer.edit', {
                                        summary: summary.recurrence,
                                    })
                                "
                                @click="openDialog(schedule)"
                            >
                                <Icon
                                    name="edit"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="
                                    $t('envelopes.schedules.drawer.delete', {
                                        summary: summary.recurrence,
                                    })
                                "
                                @click="askDelete(schedule, summary.recurrence)"
                            >
                                <Icon
                                    name="delete"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <div class="mt-auto px-4 pt-2 pb-4">
                <Button
                    variant="outline"
                    class="w-full"
                    @click="emit('close')"
                    >{{
                        $t('envelopes.schedules.drawer.done')
                    }}</Button
                >
            </div>

            <ScheduleDialog
                v-if="envelope"
                v-model:open="dialogOpen"
                :envelope="envelope"
                :schedule="editing"
                :currency="currency"
            />

            <Dialog
                :open="confirmingDelete"
                @update:open="confirmingDelete = false"
            >
                <DialogContent
                    class="sm:max-w-md"
                    @close-auto-focus="restoreFocusAfterDelete"
                >
                    <DialogHeader>
                        <DialogTitle>{{
                            $t('envelopes.schedules.delete.title')
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                $t('envelopes.schedules.delete.description', {
                                    summary: toDelete?.summary ?? '',
                                })
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <p
                        v-if="
                            toDelete &&
                            toDelete.schedule.open_ledger_entries > 0
                        "
                        class="flex items-start gap-2 text-sm"
                        data-testid="schedule-affected"
                    >
                        <Icon
                            name="warning"
                            class="mt-0.5 size-4 shrink-0 text-destructive"
                            aria-hidden="true"
                        />
                        {{
                            describeAffected(
                                toDelete.schedule.open_ledger_entries,
                                locale,
                                t,
                            )
                        }}
                    </p>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            @click="confirmingDelete = false"
                            >{{
                                $t('common.actions.cancel')
                            }}</Button
                        >
                        <Button
                            variant="destructive"
                            :disabled="remove.isPending.value"
                            @click="confirmDelete"
                            >{{
                                $t('envelopes.schedules.delete.confirm')
                            }}</Button
                        >
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SheetContent>
    </Sheet>
</template>
