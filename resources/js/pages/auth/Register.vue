<!-- biome-ignore-all lint/a11y/noHeaderScope: vue-i18n `scope` prop, not the HTML attribute -->
<!-- biome-ignore-all lint/a11y/noPositiveTabindex: deliberate tab order for the invite page form -->
<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { useQueryClient } from '@tanstack/vue-query';
import { computed, ref } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { profileKeys, useAcceptInvite } from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { ApiError } from '@/lib/api';
import { dashboard, login, privacy } from '@/routes';
import { store } from '@/routes/register';
import type { InviteContext } from '@/types';

defineProps<{
    passwordRules: string;
    /** Invite context resolved by the backend from the link's invite key. */
    invite?: InviteContext | null;
}>();

defineOptions({
    layout: {
        title: 'auth.register.layoutTitle',
        description: 'auth.register.layoutDescription',
    },
});

/**
 * The raw invite key travels in the URL (either `/invites/{key}` or
 * `?invite_key={key}`) and is submitted with the registration payload so the
 * backend can consume it and grant the membership.
 */
const inviteKey = computed(() => {
    const url = new URL(window.location.href);
    const fromQuery = url.searchParams.get('invite_key');
    if (fromQuery) {
        return fromQuery;
    }
    const match = url.pathname.match(/^\/invites\/([^/]+)$/);
    return match?.[1] ?? '';
});

// --- signed-in accept flow (docs/shared-finances.md — invites) ---------------
const page = usePage();
const signedIn = computed(() => page.props.auth.user !== null);
/** An invalid/expired key renders the landing page without invite context. */
const onInviteLink = computed(() =>
    window.location.pathname.startsWith('/invites/'),
);

const acceptInvite = useAcceptInvite();
const queryClient = useQueryClient();
const accepting = ref(false);
const acceptError = ref<string | null>(null);
const { select } = useCurrentProfile();

const { t } = useI18n();

const onAccept = () => {
    acceptError.value = null;
    accepting.value = true;
    acceptInvite.mutate(inviteKey.value, {
        onSuccess: async (data) => {
            // Refresh the profiles list so the joined profile can be selected.
            await queryClient.invalidateQueries({ queryKey: profileKeys.all });
            select(data.profile.id);
            toast.success(
                t('auth.register.joinedToast', { name: data.profile.name }),
            );
            router.get(dashboard());
        },
        onError: (e) => {
            accepting.value = false;
            acceptError.value =
                e instanceof ApiError
                    ? e.firstError
                    : t('auth.register.acceptError');
        },
    });
};

const onDecline = () => {
    router.get(dashboard());
};
</script>

<template>
    <Head :title="$t('auth.register.title')" />

    <!-- Signed-in: accept (or decline) the invite. -->
    <Card v-if="signedIn && onInviteLink">
        <CardContent class="space-y-5 p-6">
            <template v-if="invite">
                <div class="flex items-start gap-3">
                    <Icon
                        name="group-add"
                        class="size-6 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div class="text-sm">
                        <p class="text-base font-medium">
                            {{
                                $t('auth.register.join', {
                                    profile: invite.profile,
                                })
                            }}
                        </p>
                        <I18nT
                            keypath="auth.register.invitedBy"
                            tag="p"
                            scope="global"
                            class="text-muted-foreground"
                        >
                            <template #inviter>{{ invite.inviter }}</template>
                            <template #email>
                                <span class="font-medium">{{
                                    invite.email
                                }}</span>
                            </template>
                        </I18nT>
                    </div>
                </div>

                <InputError
                    v-if="acceptError"
                    :message="acceptError"
                    class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2"
                />

                <div class="flex items-center gap-2">
                    <Button
                        class="gap-2"
                        :disabled="accepting"
                        @click="onAccept"
                    >
                        <Spinner v-if="accepting" />
                        {{ $t('auth.register.acceptInvite') }}
                    </Button>
                    <Button
                        variant="ghost"
                        :disabled="accepting"
                        @click="onDecline"
                        >{{
                            $t('auth.register.decline')
                        }}</Button
                    >
                </div>
            </template>
            <template v-else>
                <div class="flex items-start gap-3">
                    <Icon
                        name="error"
                        class="size-6 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <div class="text-sm">
                        <p class="text-base font-medium">
                            {{ $t('auth.register.inviteUnavailable') }}
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                $t('auth.register.inviteUnavailableDescription')
                            }}
                        </p>
                    </div>
                </div>
                <Button as-child>
                    <Link :href="dashboard()">
                        {{ $t('auth.register.goToDashboard') }}
                    </Link>
                </Button>
            </template>
        </CardContent>
    </Card>

    <!-- Signed-out: register through the invite (email pre-filled and locked). -->
    <template v-else>
        <div
            v-if="invite"
            class="mb-2 flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3"
        >
            <Icon
                name="group-add"
                class="size-5 shrink-0 text-primary"
                aria-hidden="true"
            />
            <div class="text-sm">
                <p class="font-medium">
                    {{ $t('auth.register.join', { profile: invite.profile }) }}
                </p>
                <I18nT
                    keypath="auth.register.invitedBy"
                    tag="p"
                    scope="global"
                    class="text-muted-foreground"
                >
                    <template #inviter>{{ invite.inviter }}</template>
                    <template #email>
                        <span class="font-medium">{{ invite.email }}</span>
                    </template>
                </I18nT>
            </div>
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input type="hidden" name="invite_key" :value="inviteKey" />

            <InputError
                v-if="errors.invite_key"
                :message="errors.invite_key"
                class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2"
            />

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">{{ $t('common.fields.name') }}</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        :placeholder="$t('auth.register.namePlaceholder')"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ $t('common.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                        :default-value="invite?.email"
                        :readonly="invite !== null && invite !== undefined"
                        :class="
                            invite ? 'bg-muted/50 text-muted-foreground' : ''
                        "
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        $t('common.fields.password')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        :placeholder="$t('common.fields.password')"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        $t('auth.register.confirmPassword')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        :placeholder="$t('auth.register.confirmPassword')"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="5"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    {{
                        invite
                            ? $t('auth.register.createAndJoin', {
                                  profile: invite.profile,
                              })
                            : $t('auth.register.create')
                    }}
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                {{ $t('auth.register.alreadyHaveAccount') }}
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4"
                    :tabindex="6"
                    >{{
                        $t('auth.login.submit')
                    }}</TextLink
                >
            </div>

            <div class="text-center text-xs text-muted-foreground">
                <TextLink
                    :href="privacy()"
                    class="underline underline-offset-4"
                    :tabindex="7"
                    data-test="privacy-link"
                    >{{
                        $t('privacy.link')
                    }}</TextLink
                >
            </div>
        </Form>
    </template>
</template>
