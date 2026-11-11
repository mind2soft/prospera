<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    useCreateInvite,
    useCreateProfile,
    useDeleteProfile,
    usePendingInvites,
    useProfileMembers,
    useRemoveMember,
    useRenameProfile,
    useRevokeInvite,
    useSetDefaultProfile,
    useTransferProfileOwnership,
} from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLocale } from '@/composables/useLocale';
import { ApiError } from '@/lib/api';
import { edit as editProfiles } from '@/routes/profiles';
import type { FinancialProfile, ProfileInvite, ProfileMember } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'settings.profiles.title', href: editProfiles() },
        ],
    },
});

const { t } = useI18n();
const { locale } = useLocale();

// --- profiles ----------------------------------------------------------------
const meId = computed(() => usePage().props.auth.user.id);

const {
    profiles,
    profileId: currentProfileId,
    select,
    isPending,
    isError,
} = useCurrentProfile();

const createProfile = useCreateProfile();
const renameProfile = useRenameProfile();
const deleteProfile = useDeleteProfile();
const setDefaultProfile = useSetDefaultProfile();
const removeMember = useRemoveMember();
const transferOwnership = useTransferProfileOwnership();
const createInvite = useCreateInvite();
const revokeInvite = useRevokeInvite();

const fail = (e: unknown) => {
    toast.error(
        e instanceof ApiError
            ? e.firstError
            : t('settings.profiles.somethingWentWrong'),
    );
};

const expandedId = ref<number | null>(null);

const toggleExpanded = (profile: FinancialProfile) => {
    expandedId.value = expandedId.value === profile.id ? null : profile.id;
};

// Auto-expand the current profile on first load.
watch(
    profiles,
    (list) => {
        if (expandedId.value === null && list.length > 0) {
            expandedId.value = currentProfileId.value ?? list[0].id;
        }
    },
    { immediate: true },
);

// --- rename (auto-save) ------------------------------------------------------
const renamingId = ref<number | null>(null);
const renameValue = ref('');

const startRename = (profile: FinancialProfile) => {
    renamingId.value = profile.id;
    renameValue.value = profile.name;
};

const commitRename = (profile: FinancialProfile) => {
    const name = renameValue.value.trim();
    renamingId.value = null;
    if (name === '' || name === profile.name) {
        return;
    }
    renameProfile.mutate(
        { profileId: profile.id, name },
        {
            onSuccess: () => toast.success(t('settings.profiles.renamedToast')),
            onError: fail,
        },
    );
};

const cancelRename = () => {
    renamingId.value = null;
};

// --- create ------------------------------------------------------------------
const showCreate = ref(false);
const newName = ref('');
const newNameError = ref<string | undefined>(undefined);

const onCreate = () => {
    const name = newName.value.trim();
    if (name === '') {
        return;
    }
    createProfile.mutate(name, {
        onSuccess: () => {
            showCreate.value = false;
            newName.value = '';
            toast.success(t('settings.profiles.createdToast'));
        },
        onError: (e) => {
            newNameError.value =
                e instanceof ApiError ? e.firstError : undefined;
            fail(e);
        },
    });
};

// --- delete ------------------------------------------------------------------
const deleteTarget = ref<FinancialProfile | null>(null);

const onDelete = () => {
    const target = deleteTarget.value;
    if (target === null) {
        return;
    }
    deleteProfile.mutate(target.id, {
        onSuccess: () => {
            deleteTarget.value = null;
            toast.success(t('settings.profiles.deletedToast'));
        },
        onError: (e) => {
            deleteTarget.value = null;
            fail(e);
        },
    });
};

// --- set default (reversible, auto-saved) ------------------------------------
const setDefault = (profile: FinancialProfile) => {
    setDefaultProfile.mutate(profile.id, {
        onSuccess: () =>
            toast.success(
                t('settings.profiles.defaultToast', { name: profile.name }),
            ),
        onError: fail,
    });
};

// --- members -----------------------------------------------------------------
const expandedProfile = computed(
    () => profiles.value.find((p) => p.id === expandedId.value) ?? null,
);

const membersQuery = useProfileMembers(
    computed(() => (expandedId.value !== null ? expandedId.value : null)),
);
const members = computed(() => membersQuery.data.value ?? []);

const removeTarget = ref<ProfileMember | null>(null);
const leaveTarget = ref<ProfileMember | null>(null);

const confirmRemove = () => {
    const target = removeTarget.value;
    const profile = expandedProfile.value;
    if (target === null || profile === null) {
        return;
    }
    removeMember.mutate(
        { profileId: profile.id, userId: target.id },
        {
            onSuccess: () => {
                removeTarget.value = null;
                toast.success(
                    t('settings.profiles.memberRemovedToast', {
                        name: target.name,
                    }),
                );
            },
            onError: (e) => {
                removeTarget.value = null;
                fail(e);
            },
        },
    );
};

const confirmLeave = () => {
    const target = leaveTarget.value;
    const profile = expandedProfile.value;
    if (target === null || profile === null) {
        return;
    }
    removeMember.mutate(
        { profileId: profile.id, userId: target.id },
        {
            onSuccess: () => {
                leaveTarget.value = null;
                toast.success(
                    t('settings.profiles.leftToast', { name: profile.name }),
                );
            },
            onError: (e) => {
                leaveTarget.value = null;
                fail(e);
            },
        },
    );
};

// --- transfer ownership ------------------------------------------------------
const transferTarget = ref<number | null>(null);
const confirmingTransfer = ref(false);

const transferCandidates = computed(() =>
    members.value.filter((m) => !m.is_owner),
);

const askTransfer = () => {
    if (transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = true;
};

const confirmTransfer = () => {
    const profile = expandedProfile.value;
    if (profile === null || transferTarget.value === null) {
        return;
    }
    confirmingTransfer.value = false;
    transferOwnership.mutate(
        { profileId: profile.id, userId: transferTarget.value },
        {
            onSuccess: () => {
                transferTarget.value = null;
                toast.success(t('settings.profiles.ownershipTransferredToast'));
            },
            onError: (e) => {
                transferTarget.value = null;
                fail(e);
            },
        },
    );
};

// --- invites -----------------------------------------------------------------
/**
 * Pending invites come from GET /api/profiles/{profile}/invites (owner only),
 * so they survive reloads. The shareable link (raw key) is only returned
 * when the invite is created, so copy links are kept per invite id for the
 * current session.
 */
const invitesQuery = usePendingInvites(
    computed(() => (expandedId.value !== null ? expandedId.value : null)),
    computed(() => expandedProfile.value?.is_owner ?? false),
);
const pendingInvites = computed(() => invitesQuery.data.value ?? []);

const inviteUrls = ref<Record<number, string>>({});

const inviteEmail = ref('');
const inviteError = ref<string | undefined>(undefined);
const copiedInviteId = ref<number | null>(null);

const onInvite = () => {
    const profile = expandedProfile.value;
    const email = inviteEmail.value.trim();
    if (profile === null || email === '') {
        return;
    }
    inviteError.value = undefined;
    createInvite.mutate(
        { profileId: profile.id, email },
        {
            onSuccess: (data) => {
                inviteEmail.value = '';
                inviteUrls.value = {
                    ...inviteUrls.value,
                    [data.invite.id]: data.url,
                };
                toast.success(t('settings.profiles.inviteCreatedToast'));
            },
            onError: (e) => {
                inviteError.value =
                    e instanceof ApiError ? e.firstError : undefined;
                fail(e);
            },
        },
    );
};

const copyInvite = async (invite: ProfileInvite) => {
    const url = inviteUrls.value[invite.id];
    if (url === undefined) {
        return;
    }
    try {
        await navigator.clipboard.writeText(`${window.location.origin}${url}`);
        copiedInviteId.value = invite.id;
        setTimeout(() => (copiedInviteId.value = null), 2000);
    } catch {
        toast.error(t('settings.profiles.copyFailedToast'));
    }
};

const revoke = (invite: ProfileInvite) => {
    const profile = expandedProfile.value;
    if (profile === null) {
        return;
    }
    revokeInvite.mutate(
        { profileId: profile.id, inviteId: invite.id },
        {
            onSuccess: () =>
                toast.success(t('settings.profiles.inviteRevokedToast')),
            onError: (e) => fail(e),
        },
    );
};

const expiryLabel = (expiresAt: string): string =>
    new Date(expiresAt).toLocaleString(locale.value, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

const joinedLabel = (joinedAt: string): string =>
    new Date(joinedAt).toLocaleDateString(locale.value, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
</script>

<template>
    <Head :title="$t('settings.profiles.title')" />

    <h1 class="sr-only">{{ $t('settings.profiles.title') }}</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                variant="small"
                :title="$t('settings.profiles.title')"
                :description="$t('settings.profiles.description')"
            />
            <Button class="gap-2" @click="showCreate = true">
                <Icon name="add" class="size-4" aria-hidden="true" />
                {{ $t('settings.profiles.newProfile') }}
            </Button>
        </div>

        <!-- `div`, not `p`: the HTML parser auto-closes a <p> before block
             elements, so <div>s inside a <p> hydrate into the wrong DOM
             shape and desync every sibling that follows. -->
        <TooltipProvider :delay-duration="0">
            <div v-if="isPending && !isError" class="space-y-2">
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
            </div>

            <div v-else class="space-y-3">
                <Card
                    v-for="profile in profiles"
                    :key="profile.id"
                    class="gap-0 overflow-hidden border-sidebar-border/70 py-0 transition-colors dark:border-sidebar-border"
                    :class="
                        profile.id === currentProfileId
                            ? 'border-primary/40'
                            : ''
                    "
                >
                    <CardContent class="p-0">
                        <div class="flex w-full items-center gap-3 px-4 py-3.5">
                            <div
                                v-if="renamingId === profile.id"
                                class="flex min-w-0 flex-1 items-center gap-3"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-xl"
                                    :class="
                                        profile.is_owner
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    <Icon
                                        :name="
                                            profile.is_owner
                                                ? 'person'
                                                : 'group'
                                        "
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <Input
                                    v-model="renameValue"
                                    v-focus
                                    class="h-7 max-w-56"
                                    :aria-label="$t('settings.profiles.rename')"
                                    @keydown.enter.prevent="
                                        commitRename(profile)
                                    "
                                    @keydown.escape="cancelRename"
                                    @blur="commitRename(profile)"
                                />
                            </div>
                            <!-- Disclosure: only the name area toggles the
                                 details; the actions sit next to it (no
                                 interactive content inside a button). -->
                            <button
                                v-else
                                type="button"
                                class="flex min-w-0 flex-1 items-center gap-3 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                :aria-expanded="expandedId === profile.id"
                                :aria-controls="`profile-panel-${profile.id}`"
                                data-test="profile-disclosure"
                                @click="toggleExpanded(profile)"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-xl"
                                    :class="
                                        profile.is_owner
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    <Icon
                                        :name="
                                            profile.is_owner
                                                ? 'person'
                                                : 'group'
                                        "
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span
                                    class="flex min-w-0 flex-1 items-center gap-2"
                                >
                                    <span class="truncate text-sm font-medium">
                                        {{ profile.name }}
                                    </span>
                                    <Badge
                                        v-if="!profile.is_owner"
                                        variant="secondary"
                                        class="shrink-0"
                                        >{{
                                            $t('settings.profiles.shared')
                                        }}</Badge
                                    >
                                    <!-- Status icons with tooltips instead
                                         of badges: ownership alone is
                                         stated in the members list
                                         (Owner/Collaborator). -->
                                    <Tooltip
                                        v-if="profile.id === currentProfileId"
                                    >
                                        <TooltipTrigger as-child>
                                            <span
                                                class="grid shrink-0 place-items-center"
                                                data-test="current-profile-badge"
                                            >
                                                <Icon
                                                    name="check-circle"
                                                    class="size-4 text-primary"
                                                    aria-hidden="true"
                                                />
                                                <span class="sr-only">{{
                                                    $t(
                                                        'settings.profiles.current',
                                                    )
                                                }}</span>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>
                                                {{
                                                    $t(
                                                        'settings.profiles.current',
                                                    )
                                                }}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                    <Tooltip v-if="profile.is_default">
                                        <TooltipTrigger as-child>
                                            <span
                                                class="grid shrink-0 place-items-center text-warning"
                                                data-test="default-profile-badge"
                                            >
                                                <Icon
                                                    name="star"
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                <span class="sr-only">{{
                                                    $t(
                                                        'settings.profiles.default',
                                                    )
                                                }}</span>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>
                                                {{
                                                    $t(
                                                        'settings.profiles.default',
                                                    )
                                                }}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                </span>
                                <Icon
                                    name="expand-more"
                                    class="size-5 shrink-0 text-muted-foreground transition-transform"
                                    :class="
                                        expandedId === profile.id
                                            ? 'rotate-180'
                                            : ''
                                    "
                                    aria-hidden="true"
                                />
                            </button>
                            <Button
                                v-if="profile.is_owner"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0"
                                :aria-label="$t('settings.profiles.rename')"
                                @click="startRename(profile)"
                            >
                                <Icon
                                    name="edit"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                            <Button
                                v-if="profile.id !== currentProfileId"
                                variant="ghost"
                                size="sm"
                                class="shrink-0 text-xs"
                                @click="select(profile.id)"
                            >
                                {{ $t('settings.profiles.switchTo') }}
                            </Button>
                            <Button
                                v-if="!profile.is_default"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0"
                                :aria-label="$t('settings.profiles.setDefault')"
                                :title="$t('settings.profiles.setDefault')"
                                :disabled="setDefaultProfile.isPending.value"
                                data-test="set-default-button"
                                @click="setDefault(profile)"
                            >
                                <Icon
                                    name="star-outline"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </div>

                        <div
                            v-if="expandedId === profile.id"
                            :id="`profile-panel-${profile.id}`"
                            class="space-y-6 border-t px-4 pt-4 pb-5"
                        >
                            <!-- Members -->
                            <div class="space-y-2">
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    {{ $t('settings.profiles.members') }}
                                </p>
                                <p
                                    v-if="
                                        membersQuery.isPending.value &&
                                        !membersQuery.isError.value
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ $t('common.loading') }}
                                </p>
                                <div v-else class="space-y-1">
                                    <div
                                        v-for="member in members"
                                        :key="member.id"
                                        class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted/50"
                                    >
                                        <span
                                            class="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium uppercase"
                                        >
                                            {{ member.name.slice(0, 1) }}
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-sm font-medium"
                                            >
                                                {{ member.name }}
                                                <span
                                                    v-if="member.id === meId"
                                                    class="text-xs font-normal text-muted-foreground"
                                                    >{{
                                                        $t(
                                                            'settings.profiles.you',
                                                        )
                                                    }}</span
                                                >
                                            </p>
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {{ member.email }} ·
                                                {{
                                                    $t(
                                                        'settings.profiles.joined',
                                                        {
                                                            date: joinedLabel(
                                                                member.joined_at,
                                                            ),
                                                        },
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <Badge
                                            :variant="
                                                member.is_owner
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                            class="shrink-0"
                                        >
                                            {{
                                                member.is_owner
                                                    ? $t(
                                                          'settings.profiles.owner',
                                                      )
                                                    : $t(
                                                          'settings.profiles.collaborator',
                                                      )
                                            }}
                                        </Badge>
                                        <Button
                                            v-if="
                                                profile.is_owner &&
                                                !member.is_owner
                                            "
                                            variant="ghost"
                                            size="icon-sm"
                                            class="shrink-0 text-muted-foreground hover:text-destructive"
                                            :aria-label="
                                                $t(
                                                    'settings.profiles.removeMember',
                                                )
                                            "
                                            @click="removeTarget = member"
                                        >
                                            <Icon
                                                name="person-remove"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                        <Button
                                            v-else-if="
                                                member.id === meId &&
                                                !member.is_owner
                                            "
                                            variant="ghost"
                                            size="sm"
                                            class="shrink-0 text-xs text-muted-foreground hover:text-destructive"
                                            @click="leaveTarget = member"
                                        >
                                            {{ $t('settings.profiles.leave') }}
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Owner-only management -->
                            <template v-if="profile.is_owner">
                                <div class="space-y-2">
                                    <Label
                                        :for="`invite-email-${profile.id}`"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            $t(
                                                'settings.profiles.inviteSomeone',
                                            )
                                        }}
                                    </Label>
                                    <form
                                        class="flex items-start gap-2"
                                        @submit.prevent="onInvite"
                                    >
                                        <div class="flex-1 space-y-1">
                                            <Input
                                                :id="`invite-email-${profile.id}`"
                                                v-model="inviteEmail"
                                                type="email"
                                                required
                                                :placeholder="
                                                    $t(
                                                        'settings.profiles.emailPlaceholder',
                                                    )
                                                "
                                            />
                                            <InputError
                                                :for="`invite-email-${profile.id}`"
                                                :message="inviteError"
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            class="gap-2"
                                            :disabled="
                                                createInvite.isPending.value
                                            "
                                        >
                                            <Icon
                                                name="person-add"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ $t('settings.profiles.invite') }}
                                        </Button>
                                    </form>
                                    <p class="text-xs text-muted-foreground">
                                        {{ $t('settings.profiles.inviteNote') }}
                                    </p>

                                    <div
                                        v-for="invite in pendingInvites"
                                        :key="invite.id"
                                        class="flex items-center gap-2 rounded-md border px-3 py-2"
                                        data-test="pending-invite"
                                    >
                                        <Icon
                                            name="link"
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm">
                                                {{ invite.email }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    $t(
                                                        'settings.profiles.expires',
                                                        {
                                                            date: expiryLabel(
                                                                invite.expires_at,
                                                            ),
                                                        },
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <Button
                                            v-if="inviteUrls[invite.id]"
                                            variant="ghost"
                                            size="icon-sm"
                                            class="shrink-0"
                                            :aria-label="
                                                $t(
                                                    'settings.profiles.copyInviteLink',
                                                )
                                            "
                                            @click="copyInvite(invite)"
                                        >
                                            <Icon
                                                :name="
                                                    copiedInviteId === invite.id
                                                        ? 'check'
                                                        : 'content-copy'
                                                "
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            class="shrink-0 text-muted-foreground hover:text-destructive"
                                            :aria-label="
                                                $t(
                                                    'settings.profiles.revokeInvite',
                                                )
                                            "
                                            :disabled="
                                                revokeInvite.isPending.value
                                            "
                                            @click="revoke(invite)"
                                        >
                                            <Icon
                                                name="close"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>
                                </div>

                                <div
                                    v-if="transferCandidates.length > 0"
                                    class="space-y-2"
                                >
                                    <Label
                                        :for="`transfer-member-${profile.id}`"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            $t(
                                                'settings.profiles.transferOwnership',
                                            )
                                        }}
                                    </Label>
                                    <div class="flex items-center gap-2">
                                        <Select
                                            :model-value="
                                                transferTarget ?? undefined
                                            "
                                            @update:model-value="
                                                (value) =>
                                                    (transferTarget =
                                                        Number(value))
                                            "
                                        >
                                            <SelectTrigger
                                                :id="`transfer-member-${profile.id}`"
                                                class="flex-1"
                                            >
                                                <SelectValue
                                                    :placeholder="
                                                        $t(
                                                            'settings.profiles.chooseCollaborator',
                                                        )
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
                                            @click="askTransfer"
                                        >
                                            {{
                                                $t('settings.profiles.transfer')
                                            }}
                                        </Button>
                                    </div>
                                </div>

                                <!-- Deleting reassigns affected users' defaults server-side. -->
                                <div class="space-y-2 border-t pt-4">
                                    <p
                                        class="text-xs font-medium text-destructive"
                                    >
                                        {{ $t('settings.profiles.dangerZone') }}
                                    </p>
                                    <div
                                        class="flex items-center justify-between gap-4"
                                    >
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                $t(
                                                    'settings.profiles.deleteNote',
                                                )
                                            }}
                                        </p>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            class="shrink-0 gap-2"
                                            @click="deleteTarget = profile"
                                        >
                                            <Icon
                                                name="delete"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{
                                                $t(
                                                    'settings.profiles.deleteProfile',
                                                )
                                            }}
                                        </Button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </TooltipProvider>

        <!-- Create profile -->
        <Dialog :open="showCreate" @update:open="showCreate = false">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('settings.profiles.newProfile')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ $t('settings.profiles.createDescription') }}
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="profile-name">{{
                        $t('common.fields.name')
                    }}</Label>
                    <Input
                        id="profile-name"
                        v-model="newName"
                        v-focus
                        :placeholder="$t('settings.profiles.namePlaceholder')"
                        @keydown.enter.prevent="onCreate"
                    />
                    <InputError for="profile-name" :message="newNameError" />
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="showCreate = false">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        :disabled="
                            newName.trim() === '' ||
                            createProfile.isPending.value
                        "
                        @click="onCreate"
                        >{{
                            $t('settings.profiles.createProfile')
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete profile -->
        <Dialog
            :open="deleteTarget !== null"
            @update:open="deleteTarget = null"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('settings.profiles.deleteConfirmTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('settings.profiles.deleteConfirmDescription', {
                                name: deleteTarget?.name,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleteTarget = null">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="deleteProfile.isPending.value"
                        @click="onDelete"
                        >{{
                            $t('settings.profiles.deleteProfile')
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Remove member -->
        <Dialog
            :open="removeTarget !== null"
            @update:open="removeTarget = null"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('settings.profiles.removeConfirmTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('settings.profiles.removeConfirmDescription', {
                                name: removeTarget?.name,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="removeTarget = null">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="removeMember.isPending.value"
                        @click="confirmRemove"
                        >{{
                            $t('common.actions.remove')
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Leave profile -->
        <Dialog :open="leaveTarget !== null" @update:open="leaveTarget = null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('settings.profiles.leaveConfirmTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('settings.profiles.leaveConfirmDescription', {
                                name: expandedProfile?.name,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="leaveTarget = null">{{
                        $t('common.actions.cancel')
                    }}</Button>
                    <Button
                        variant="destructive"
                        :disabled="removeMember.isPending.value"
                        @click="confirmLeave"
                        >{{
                            $t('settings.profiles.leaveProfile')
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Transfer ownership -->
        <Dialog
            :open="confirmingTransfer"
            @update:open="confirmingTransfer = false"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('settings.profiles.transferConfirmTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t('settings.profiles.transferConfirmDescription', {
                                member: members.find(
                                    (m) => m.id === transferTarget,
                                )?.name,
                                profile: expandedProfile?.name,
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
                        $t('settings.profiles.transfer')
                    }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
