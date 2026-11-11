<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from '@/components/Icon.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useInitials } from '@/composables/useInitials';
import type {
    ProfileMember,
    ResourceMember,
    ResourcePermission,
} from '@/types';

const props = defineProps<{
    open: boolean;
    title: string;
    /** Overrides the generic drawer description (envelopes describe their own audience). */
    description?: string;
    transferResource: string;
    /** People with access — the owner row first, implicit viewers included. */
    members: ResourceMember[];
    /**
     * True while the members are being fetched (absent → inferred from an
     * empty list, the pre-`loading` heuristic).
     */
    loading?: boolean;
    currentUserId: number;
    /** True when the current user owns the resource. */
    canManage: boolean;
    /**
     * The owner's profile circle (user-owned resources like envelopes):
     * enables the "Grant access" section and widens transfer candidates to
     * circle members without an explicit share row yet.
     */
    profileCircle?: ProfileMember[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'set-permission', userId: number, permission: ResourcePermission): void;
    (e: 'revoke', userId: number): void;
    (e: 'transfer', userId: number): void;
    (e: 'grant', userId: number, permission: ResourcePermission): void;
}>();

const { getInitials } = useInitials();

const permissionLabels: Record<ResourcePermission, string> = {
    owner: 'share.permission.owner',
    contributor: 'share.permission.contributor',
    viewer: 'share.permission.viewer',
};

// --- grant access (profile-circle resources) --------------------------------
const newUserId = ref<number | null>(null);
const newPermission = ref<'contributor' | 'viewer'>('contributor');

/** Circle members without an explicit row yet. */
const grantCandidates = computed(() => {
    const shared = new Set(props.members.map((m) => m.user_id));
    return (props.profileCircle ?? []).filter((m) => !shared.has(m.id));
});

const grant = (): void => {
    if (newUserId.value === null) {
        return;
    }
    emit('grant', newUserId.value, newPermission.value);
    newUserId.value = null;
    newPermission.value = 'contributor';
};

// --- change / revoke --------------------------------------------------------
const onPermissionChange = (
    member: ResourceMember,
    permission: string,
): void => {
    if (!props.canManage || member.permission === 'owner') {
        return;
    }
    emit('set-permission', member.user_id, permission as ResourcePermission);
};

// Revoking cannot be undone from every drawer (budgets and ledgers have no
// grant section): confirm it, naming the member.
const revokeTarget = ref<ResourceMember | null>(null);
const confirmingRevoke = ref(false);

const askRevoke = (member: ResourceMember): void => {
    revokeTarget.value = member;
    confirmingRevoke.value = true;
};

const confirmRevoke = (): void => {
    if (revokeTarget.value === null) {
        return;
    }
    confirmingRevoke.value = false;
    emit('revoke', revokeTarget.value.user_id);
};

// --- transfer ---------------------------------------------------------------
const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);

/** Transfer candidates normalized to {id, name}, whichever the source list. */
const transferCandidates = computed((): { id: number; name: string }[] => {
    if (props.profileCircle) {
        return props.profileCircle
            .filter((m) => m.id !== props.currentUserId)
            .map((m) => ({ id: m.id, name: m.name }));
    }
    return props.members
        .filter(
            (m) =>
                m.permission !== 'owner' && m.user_id !== props.currentUserId,
        )
        .map((m) => ({ id: m.user_id, name: m.name }));
});

const transferTargetName = computed(
    () =>
        transferCandidates.value.find((m) => m.id === transferTarget.value)
            ?.name ?? '',
);

const askTransfer = (): void => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = (): void => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    emit('transfer', transferTarget.value);
    transferTarget.value = null;
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            newUserId.value = null;
            newPermission.value = 'contributor';
            transferTarget.value = null;
            confirmingTransfer.value = false;
            confirmingRevoke.value = false;
        }
    },
);
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent class="flex flex-col gap-6 overflow-y-auto sm:max-w-md">
            <SheetHeader>
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription>{{
                    description ?? $t('share.drawer.description')
                }}</SheetDescription>
            </SheetHeader>

            <section class="space-y-2">
                <p class="text-sm font-medium">
                    {{ $t('share.drawer.people') }}
                </p>

                <p
                    v-if="props.loading ?? members.length === 0"
                    class="py-4 text-sm text-muted-foreground"
                >
                    {{ $t('common.loading') }}
                </p>

                <ul v-else class="space-y-1">
                    <li
                        v-for="member in members"
                        :key="member.user_id"
                        class="flex items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-muted/50"
                    >
                        <Avatar class="size-8 shrink-0">
                            <AvatarFallback
                                class="bg-muted text-xs font-medium text-foreground"
                            >
                                {{ getInitials(member.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ member.name }}
                                <span
                                    v-if="member.user_id === currentUserId"
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{
                                        $t('share.drawer.you')
                                    }}</span
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ member.email }}
                            </p>
                        </div>
                        <Select
                            v-if="canManage && member.permission !== 'owner'"
                            :model-value="member.permission"
                            @update:model-value="
                                (value) =>
                                    onPermissionChange(member, String(value))
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="w-32 shrink-0"
                                :aria-label="
                                    $t('share.drawer.accessFor', {
                                        name: member.name,
                                    })
                                "
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="contributor">
                                    <Icon
                                        name="edit"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ $t('share.permission.contributor') }}
                                </SelectItem>
                                <SelectItem value="viewer">
                                    <Icon
                                        name="person"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ $t('share.permission.viewer') }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <span
                            v-else
                            class="shrink-0 text-xs font-medium text-muted-foreground"
                            >{{
                                $t(permissionLabels[member.permission])
                            }}</span
                        >
                        <Button
                            v-if="
                                canManage &&
                                member.permission !== 'owner' &&
                                member.explicit
                            "
                            variant="ghost"
                            size="icon-sm"
                            class="shrink-0 text-muted-foreground hover:text-destructive"
                            :aria-label="
                                $t('share.drawer.revokeFor', {
                                    name: member.name,
                                })
                            "
                            @click="askRevoke(member)"
                        >
                            <Icon
                                name="close"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </Button>
                    </li>
                </ul>
            </section>

            <template v-if="canManage">
                <Separator />

                <div v-if="profileCircle" class="space-y-2">
                    <Label for="share-grant-member">
                        {{ $t('share.drawer.grantAccess') }}
                    </Label>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="newUserId ?? undefined"
                            @update:model-value="
                                (value) => (newUserId = Number(value))
                            "
                        >
                            <SelectTrigger
                                id="share-grant-member"
                                class="flex-1"
                            >
                                <SelectValue
                                    :placeholder="
                                        $t('share.drawer.chooseMember')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in grantCandidates"
                                    :key="member.id"
                                    :value="member.id"
                                >
                                    {{ member.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select v-model="newPermission">
                            <SelectTrigger
                                size="sm"
                                class="w-32 shrink-0"
                                :aria-label="$t('share.drawer.accessLevel')"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="contributor">
                                    <Icon
                                        name="edit"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ $t('share.permission.contributor') }}
                                </SelectItem>
                                <SelectItem value="viewer">
                                    <Icon
                                        name="person"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ $t('share.permission.viewer') }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            :disabled="newUserId === null"
                            class="shrink-0 gap-2"
                            @click="grant"
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

                <div v-if="transferCandidates.length > 0" class="space-y-2">
                    <Label for="share-transfer-member">
                        {{ $t('share.drawer.transferOwnership') }}
                    </Label>
                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="transferTarget ?? undefined"
                            @update:model-value="
                                (value) => (transferTarget = Number(value))
                            "
                        >
                            <SelectTrigger
                                id="share-transfer-member"
                                class="flex-1"
                            >
                                <SelectValue
                                    :placeholder="
                                        $t('share.drawer.chooseMember')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="member in transferCandidates"
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
                            class="gap-2"
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

            <div class="mt-auto pt-2">
                <Button
                    variant="outline"
                    class="w-full"
                    @click="emit('update:open', false)"
                    >{{
                        $t('share.drawer.done')
                    }}</Button
                >
            </div>

            <Dialog
                :open="confirmingRevoke"
                @update:open="confirmingRevoke = false"
            >
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{{
                            $t('share.drawer.revokeTitle', {
                                name: revokeTarget?.name ?? '',
                            })
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                profileCircle
                                    ? $t('share.drawer.revokeDescription', {
                                          name: revokeTarget?.name ?? '',
                                          resource: transferResource,
                                      })
                                    : $t(
                                          'share.drawer.revokeDescriptionMember',
                                          {
                                              name: revokeTarget?.name ?? '',
                                              resource: transferResource,
                                          },
                                      )
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            @click="confirmingRevoke = false"
                            >{{
                                $t('common.actions.cancel')
                            }}</Button
                        >
                        <Button variant="destructive" @click="confirmRevoke">{{
                            $t('share.drawer.revoke')
                        }}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
                                $t('share.drawer.transferDescription', {
                                    name: transferTargetName,
                                    resource: transferResource,
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
