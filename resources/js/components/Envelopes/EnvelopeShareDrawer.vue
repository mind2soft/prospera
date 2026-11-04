<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
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
import {
    useEnvelopeShares,
    useRemoveEnvelopeShare,
    useSetEnvelopeShare,
    useTransferEnvelopeOwnership,
} from '@/composables/envelopeQueries';
import { useProfileCircle } from '@/composables/profileQueries';
import { ApiError } from '@/lib/api';
import type { ResourceMember, ResourcePermission } from '@/types';

const props = defineProps<{
    envelopeId: number;
    open: boolean;
    /** True when the current user owns the envelope. */
    canManage: boolean;
    currentUserId: number;
}>();

const emit = defineEmits<(e: 'update:open', value: boolean) => void>();

const { t } = useI18n();

const sharesQuery = useEnvelopeShares(
    computed(() => (props.open ? props.envelopeId : null)),
);
const circleQuery = useProfileCircle(computed(() => props.open));

const setShare = useSetEnvelopeShare();
const removeShare = useRemoveEnvelopeShare();
const transferOwnership = useTransferEnvelopeOwnership();

const fail = (e: unknown) => {
    toast.error(e instanceof ApiError ? e.firstError : t('share.toast.failed'));
};

const shares = computed(() => sharesQuery.data.value ?? []);

const circle = computed(() => circleQuery.data.value ?? []);

/** Grantable people: profile-circle members without an explicit row yet. */
const candidates = computed(() => {
    const shared = new Set(shares.value.map((s) => s.user_id));
    return circle.value.filter((member) => !shared.has(member.id));
});

// --- add someone ------------------------------------------------------------
const newUserId = ref<number | null>(null);
const newPermission = ref<'contributor' | 'viewer'>('contributor');

const add = () => {
    if (newUserId.value === null) {
        return;
    }
    setShare.mutate(
        {
            envelopeId: props.envelopeId,
            userId: newUserId.value,
            permission: newPermission.value,
        },
        {
            onSuccess: () => {
                newUserId.value = null;
                toast.success(t('share.toast.accessGranted'));
            },
            onError: fail,
        },
    );
};

// --- change / revoke --------------------------------------------------------
const onPermissionChange = (share: ResourceMember, permission: string) => {
    if (!props.canManage) {
        return;
    }
    setShare.mutate(
        {
            envelopeId: props.envelopeId,
            userId: share.user_id,
            permission: permission as ResourcePermission,
        },
        {
            onSuccess: () => toast.success(t('share.toast.accessUpdated')),
            onError: fail,
        },
    );
};

const onRevoke = (share: ResourceMember) => {
    removeShare.mutate(
        { envelopeId: props.envelopeId, userId: share.user_id },
        {
            onSuccess: () => toast.success(t('share.toast.accessRevoked')),
            onError: fail,
        },
    );
};

// --- transfer ---------------------------------------------------------------
const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);
const transferTargetName = computed(
    () => circle.value.find((m) => m.id === transferTarget.value)?.name ?? '',
);

const askTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    transferOwnership.mutate(
        { envelopeId: props.envelopeId, userId: transferTarget.value },
        {
            onSuccess: () => {
                transferTarget.value = null;
                toast.success(t('share.toast.ownershipTransferred'));
                emit('update:open', false);
            },
            onError: (e) => {
                transferTarget.value = null;
                fail(e);
            },
        },
    );
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            newUserId.value = null;
            newPermission.value = 'contributor';
            transferTarget.value = null;
            confirmingTransfer.value = false;
        }
    },
);
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent class="flex flex-col gap-6 overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{ $t('share.envelope.title') }}</SheetTitle>
                <SheetDescription>{{
                    $t('share.envelope.description')
                }}</SheetDescription>
            </SheetHeader>

            <p
                v-if="sharesQuery.isLoading.value"
                class="py-4 text-sm text-muted-foreground"
            >
                {{ $t('common.loading') }}
            </p>
            <p
                v-else-if="shares.length === 0"
                class="py-4 text-sm text-muted-foreground"
            >
                {{ $t('share.envelope.empty') }}
            </p>
            <div v-else class="space-y-1">
                <div
                    v-for="share in shares"
                    :key="share.user_id"
                    class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50"
                >
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium uppercase"
                    >
                        {{ share.name.slice(0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ share.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ share.email }}
                        </p>
                    </div>
                    <Select
                        v-if="canManage"
                        :model-value="share.permission"
                        @update:model-value="
                            (value) => onPermissionChange(share, String(value))
                        "
                    >
                        <SelectTrigger size="sm" class="w-28 shrink-0">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="contributor">{{
                                $t('share.permission.contributor')
                            }}</SelectItem>
                            <SelectItem value="viewer">{{
                                $t('share.permission.viewer')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <span
                        v-else
                        class="shrink-0 text-xs font-medium text-muted-foreground"
                        >{{
                            share.permission === 'contributor'
                                ? $t('share.permission.contributor')
                                : $t('share.permission.viewer')
                        }}</span
                    >
                    <Button
                        v-if="canManage"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground hover:text-destructive"
                        :aria-label="$t('share.drawer.revoke')"
                        @click="onRevoke(share)"
                    >
                        <Icon name="close" class="size-4" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <template v-if="canManage">
                <Separator />

                <div class="space-y-2">
                    <p class="text-sm font-medium">
                        {{ $t('share.envelope.grantAccess') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="newUserId ?? undefined"
                            @update:model-value="
                                (value) => (newUserId = Number(value))
                            "
                        >
                            <SelectTrigger class="flex-1">
                                <SelectValue
                                    :placeholder="
                                        $t('share.drawer.chooseMember')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in candidates"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="newPermission">
                            <SelectTrigger size="sm" class="w-28 shrink-0">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="contributor">{{
                                    $t('share.permission.contributor')
                                }}</SelectItem>
                                <SelectItem value="viewer">{{
                                    $t('share.permission.viewer')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="newUserId === null"
                            class="shrink-0 gap-2"
                            @click="add"
                        >
                            <Icon
                                name="add"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ $t('share.add') }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="
                        circle.filter((m) => m.id !== currentUserId).length > 0
                    "
                    class="space-y-2"
                >
                    <p class="text-sm font-medium">
                        {{ $t('share.drawer.transferOwnership') }}
                    </p>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="transferTarget ?? undefined"
                            @update:model-value="
                                (value) => (transferTarget = Number(value))
                            "
                        >
                            <SelectTrigger class="flex-1">
                                <SelectValue
                                    :placeholder="
                                        $t('share.drawer.chooseMember')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in circle.filter(
                                        (m) => m.id !== currentUserId,
                                    )"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="transferTarget === null"
                            class="shrink-0 gap-2"
                            @click="askTransfer"
                        >
                            <Icon
                                name="swap-horiz"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ $t('share.drawer.transfer') }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('share.drawer.transferNote') }}
                    </p>
                </div>
            </template>

            <Dialog
                :open="confirmingTransfer"
                @update:open="confirmingTransfer = false"
            >
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{{
                            $t('share.drawer.transferTitle')
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                $t('share.envelope.transferDescription', {
                                    name: transferTargetName,
                                })
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            @click="confirmingTransfer = false"
                            >{{
                                $t('common.actions.cancel')
                            }}</Button
                        >
                        <Button @click="confirmTransfer">{{
                            $t('share.drawer.transfer')
                        }}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SheetContent>
    </Sheet>
</template>
