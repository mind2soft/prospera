<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { useMutation } from '@/composables/query';
import { ApiError } from '@/lib/api';
import {
    type NotificationSettings,
    notificationApi,
} from '@/lib/notificationApi';
import { edit } from '@/routes/notifications';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'settings.notifications.title', href: edit() }],
    },
});

const props = defineProps<{
    /** The user's stored settings, defaults applied server-side. */
    settings: NotificationSettings;
}>();

const { t } = useI18n();

/** The togglable notification types, in display order. */
const TOGGLES = [
    {
        key: 'payment_reminders',
        label: 'settings.notifications.payment_reminders',
        description: 'settings.notifications.payment_remindersDescription',
    },
    {
        key: 'ledger_expired',
        label: 'settings.notifications.ledger_expired',
        description: 'settings.notifications.ledger_expiredDescription',
    },
    {
        key: 'contributor_transactions',
        label: 'settings.notifications.contributor_transactions',
        description:
            'settings.notifications.contributor_transactionsDescription',
    },
    {
        key: 'envelope_limit_exceeded',
        label: 'settings.notifications.envelope_limit_exceeded',
        description:
            'settings.notifications.envelope_limit_exceededDescription',
    },
    {
        key: 'profile_invite',
        label: 'settings.notifications.profile_invite',
        description: 'settings.notifications.profile_inviteDescription',
    },
] as const satisfies ReadonlyArray<{
    key: keyof NotificationSettings;
    label: string;
    description: string;
}>;

const settings = ref<NotificationSettings>({ ...props.settings });

const save = useMutation({
    mutationFn: (next: NotificationSettings) => notificationApi.update(next),
});

// Each PATCH carries the full catalog, so concurrent saves could interleave
// (a failing first save reverting state that a succeeding second save
// already persisted). Requests are tagged: only the newest one reconciles
// the UI (its success adopts the server echo; its failure reverts), older
// resolutions are ignored — the newest request always reflects every
// toggle clicked so far.
let requestId = 0;

/**
 * Autosave on every toggle — no save buttons (project convention). The
 * switch flips optimistically; a failed save reverts to the previous state
 * and surfaces the error.
 */
const toggle = (key: keyof NotificationSettings) => {
    const previous = { ...settings.value };
    const next: NotificationSettings = {
        ...settings.value,
        [key]: !settings.value[key],
    };

    settings.value = next;
    const id = ++requestId;
    save.mutate(next, {
        onSuccess: (data) => {
            if (id === requestId) {
                settings.value = data.settings;
            }
        },
        onError: (error) => {
            if (id === requestId) {
                settings.value = previous;
                toast.error(
                    error instanceof ApiError
                        ? error.firstError
                        : t('settings.notifications.saveFailed'),
                );
            }
        },
    });
};
</script>

<template>
    <Head :title="$t('settings.notifications.title')" />

    <h1 class="sr-only">{{ $t('settings.notifications.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('settings.notifications.title')"
            :description="$t('settings.notifications.description')"
        />

        <Card class="gap-0 py-0">
            <CardContent class="p-0">
                <!-- Master switch -->
                <div
                    class="flex items-center justify-between gap-4 p-4"
                    data-test="notifications-master"
                >
                    <div class="space-y-1">
                        <Label for="notifications-master" class="font-medium">
                            {{ $t('settings.notifications.master') }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('settings.notifications.masterDescription') }}
                        </p>
                    </div>
                    <Switch
                        id="notifications-master"
                        :model-value="settings.master"
                        :aria-label="$t('settings.notifications.master')"
                        @update:model-value="toggle('master')"
                    />
                </div>

                <Separator />

                <div class="space-y-4 p-4">
                    <div
                        v-for="toggleItem in TOGGLES"
                        :key="toggleItem.key"
                        class="flex items-center justify-between gap-4"
                        :class="settings.master ? '' : 'opacity-60'"
                        :data-test="`notifications-toggle-${toggleItem.key}`"
                    >
                        <div class="space-y-1">
                            <Label :for="`notifications-${toggleItem.key}`">
                                {{ $t(toggleItem.label) }}
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                {{ $t(toggleItem.description) }}
                            </p>
                        </div>
                        <Switch
                            :id="`notifications-${toggleItem.key}`"
                            :model-value="settings[toggleItem.key]"
                            :aria-label="$t(toggleItem.label)"
                            @update:model-value="toggle(toggleItem.key)"
                        />
                    </div>

                    <p
                        v-if="!settings.master"
                        class="text-sm text-muted-foreground"
                        data-test="notifications-all-off"
                    >
                        {{ $t('settings.notifications.allOff') }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
