<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '@/components/Icon.vue';
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { logout, privacy } from '@/routes';
import { show as contact } from '@/routes/contact';
import { edit } from '@/routes/profile';
import { edit as editProfiles } from '@/routes/profiles';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

const {
    profiles,
    profileId,
    select,
    isPending: profilesPending,
    isError: profilesError,
    refetch: refetchProfiles,
} = useCurrentProfile();
const menuProfiles = computed(() =>
    [...profiles.value].sort((a, b) => Number(b.is_owner) - Number(a.is_owner)),
);
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger class="gap-2">
                <Icon name="group" class="size-4" aria-hidden="true" />
                {{ $t('layout.user.profiles') }}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent class="w-64">
                <p
                    v-if="profilesPending"
                    class="px-2 py-1.5 text-sm text-muted-foreground"
                    role="status"
                >
                    {{ $t('common.loading') }}
                </p>
                <template v-else-if="profilesError && profiles.length === 0">
                    <p
                        class="px-2 py-1.5 text-sm text-muted-foreground"
                        role="alert"
                    >
                        {{ $t('common.error') }}
                    </p>
                    <DropdownMenuItem @select.prevent="refetchProfiles()">
                        {{ $t('common.actions.retry') }}
                    </DropdownMenuItem>
                </template>
                <DropdownMenuRadioGroup
                    v-else
                    :model-value="profileId ?? undefined"
                    @update:model-value="(value) => select(Number(value))"
                >
                    <DropdownMenuRadioItem
                        v-for="profile in menuProfiles"
                        :key="profile.id"
                        :value="profile.id"
                        class="gap-2"
                    >
                        <template #indicator-icon>
                            <Icon
                                name="check"
                                class="size-4 text-primary"
                                aria-hidden="true"
                                data-test="current-profile-check"
                            />
                        </template>
                        <Icon
                            :name="profile.is_owner ? 'person' : 'group'"
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 items-center gap-1">
                            <span class="min-w-0 truncate">{{
                                profile.name
                            }}</span>
                            <template v-if="profile.is_default">
                                <Icon
                                    name="star"
                                    class="size-3.5 shrink-0 text-warning"
                                    aria-hidden="true"
                                    data-test="default-profile-star"
                                />
                                <span class="sr-only">{{
                                    $t('layout.user.defaultSuffix')
                                }}</span>
                            </template>
                        </span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                profile.is_owner
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{
                                profile.is_owner
                                    ? $t('layout.user.owned')
                                    : $t('layout.user.shared')
                            }}
                        </span>
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem :as-child="true">
                    <Link
                        class="block w-full cursor-pointer"
                        :href="editProfiles()"
                    >
                        <Icon name="tune" class="size-4" aria-hidden="true" />
                        {{ $t('layout.user.manageProfiles') }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Icon name="settings" class="size-4" aria-hidden="true" />
                {{ $t('layout.user.settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="contact()">
                <Icon name="mail" class="size-4" aria-hidden="true" />
                {{ $t('layout.user.contact') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="privacy()"
                data-test="privacy-link"
            >
                <Icon name="shield-lock" class="size-4" aria-hidden="true" />
                {{ $t('privacy.link') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <Icon name="logout" class="size-4" aria-hidden="true" />
            {{ $t('layout.user.logOut') }}
        </Link>
    </DropdownMenuItem>
</template>
