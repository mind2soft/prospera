<!-- biome-ignore-all lint/a11y/noHeaderScope: vue-i18n `scope` prop, not the HTML attribute -->
<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { useQueryClient } from '@tanstack/vue-query';
import { computed, ref } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
import { profileKeys, useAcceptInvite } from '@/composables/profileQueries';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { errorMessage } from '@/lib/api';
import { profileApi } from '@/lib/profileApi';
import { registerHeading } from '@/lib/registerHeading';
import { dashboard, login, logout, privacy } from '@/routes';
import { acceptLink } from '@/routes/invites';
import { store } from '@/routes/register';
import { notice } from '@/routes/verification';
import type { InviteContext, User } from '@/types';

const props = defineProps<{
    passwordRules: string;
    /** Invite context resolved by the backend from the link's invite key. */
    invite?: InviteContext | null;
}>();

defineOptions({
    layout: (pageProps: {
        auth?: { user: User | null };
        invite?: InviteContext | null;
    }) => {
        const { title, description } = registerHeading(
            Boolean(pageProps.auth?.user),
            Boolean(pageProps.invite),
        );

        return { title, description };
    },
});

const page = usePage();

/** The visited URL — Inertia's, so the server render can read it too. */
const url = computed(() => new URL(page.url, 'http://localhost'));

/**
 * The raw invite key travels in the URL (either `/invites/{key}` or
 * `?invite_key={key}`) and is submitted with the registration payload so the
 * backend can consume it and grant the membership.
 */
const inviteKey = computed(() => {
    const fromQuery = url.value.searchParams.get('invite_key');
    if (fromQuery) {
        return fromQuery;
    }
    const match = url.value.pathname.match(/^\/invites\/([^/]+)$/);
    return match?.[1] ?? '';
});

// --- signed-in accept flow (docs/shared-finances.md — invites) ---------------
const user = computed(() => page.props.auth.user);
const signedIn = computed(() => user.value !== null);
/** An invalid/expired key renders the landing page without invite context. */
const onInviteLink = computed(() => url.value.pathname.startsWith('/invites/'));
/** Accepting needs a verified email address (the API is behind `verified`). */
const verified = computed(() => Boolean(user.value?.email_verified_at));
/** Only the invited address can accept: Accept would always fail. */
const emailMismatch = computed(
    () =>
        props.invite !== null &&
        props.invite !== undefined &&
        user.value !== null &&
        props.invite.email.toLowerCase() !== user.value.email.toLowerCase(),
);

const { t } = useI18n();

const documentTitle = computed(() =>
    t(registerHeading(signedIn.value, Boolean(props.invite)).documentTitle),
);

/**
 * A guest with an account signs in through the email's accept link: the
 * `auth` middleware keeps it as the intended URL, so they come back to this
 * invitation instead of the dashboard.
 */
const loginHref = computed(() =>
    props.invite && onInviteLink.value ? acceptLink(inviteKey.value) : login(),
);

const acceptInvite = useAcceptInvite();
const queryClient = useQueryClient();
const accepting = ref(false);
const acceptError = ref<string | null>(null);
const { select } = useCurrentProfile();

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
            acceptError.value = errorMessage(e, t('auth.register.acceptError'));
        },
    });
};

// Declining deletes the invite server-side, so it leaves the owner's
// pending list and can no longer be accepted: it is confirmed first.
const confirmingDecline = ref(false);
const declining = ref(false);

const onDecline = async () => {
    acceptError.value = null;
    declining.value = true;
    try {
        await profileApi.declineInvite(inviteKey.value);
    } catch (e) {
        declining.value = false;
        confirmingDecline.value = false;
        acceptError.value = errorMessage(e, t('auth.register.declineError'));
        return;
    }
    confirmingDecline.value = false;
    toast.success(t('auth.register.declinedToast'));
    router.get(dashboard());
};
</script>

<template>
    <Head :title="documentTitle" />

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
                        <h2 class="text-base font-medium">
                            {{
                                $t('auth.register.join', {
                                    profile: invite.profile,
                                })
                            }}
                        </h2>
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

                <!-- Another account: only the invited address can accept — checked
                     first, verifying this address would not change it. -->
                <template v-if="emailMismatch">
                    <Alert variant="destructive" data-test="invite-mismatch">
                        <Icon name="error" aria-hidden="true" />
                        <AlertTitle>{{
                            $t('auth.invite.mismatch')
                        }}</AlertTitle>
                        <AlertDescription>
                            {{
                                $t('auth.invite.mismatchDescription', {
                                    invited: invite.email,
                                    current: user?.email,
                                })
                            }}
                        </AlertDescription>
                    </Alert>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="logout()" as="button">
                                {{ $t('auth.invite.logOut') }}
                            </Link>
                        </Button>
                        <Button variant="ghost" as-child>
                            <Link :href="dashboard()">
                                {{ $t('auth.register.goToDashboard') }}
                            </Link>
                        </Button>
                    </div>
                </template>

                <!-- Unverified: the accept API would refuse (403). -->
                <template v-else-if="!verified">
                    <Alert data-test="invite-verify-first">
                        <Icon name="mail" aria-hidden="true" />
                        <AlertTitle>{{
                            $t('auth.invite.verifyFirst')
                        }}</AlertTitle>
                        <AlertDescription>
                            {{
                                $t('auth.invite.verifyFirstDescription', {
                                    email: user?.email,
                                })
                            }}
                        </AlertDescription>
                    </Alert>
                    <Button as-child>
                        <Link :href="notice()">
                            {{ $t('auth.invite.goToVerification') }}
                        </Link>
                    </Button>
                </template>

                <template v-else>
                    <InputError
                        v-if="acceptError"
                        :message="acceptError"
                        class="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2"
                    />

                    <div class="flex items-center gap-2">
                        <Button
                            class="gap-2"
                            :disabled="accepting || declining"
                            @click="onAccept"
                        >
                            <Spinner v-if="accepting" />
                            {{ $t('auth.register.acceptInvite') }}
                        </Button>
                        <Button
                            variant="ghost"
                            :disabled="accepting || declining"
                            @click="confirmingDecline = true"
                            >{{
                                $t('auth.register.decline')
                            }}</Button
                        >
                    </div>
                </template>

                <Dialog
                    :open="confirmingDecline"
                    @update:open="confirmingDecline = $event"
                >
                    <DialogContent class="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {{
                                    $t('auth.invite.declineConfirm.title', {
                                        profile: invite.profile,
                                    })
                                }}
                            </DialogTitle>
                            <DialogDescription>
                                {{
                                    $t(
                                        'auth.invite.declineConfirm.description',
                                        {
                                            inviter: invite.inviter,
                                        },
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                :disabled="declining"
                                @click="confirmingDecline = false"
                            >
                                {{ $t('auth.invite.declineConfirm.cancel') }}
                            </Button>
                            <Button
                                variant="destructive"
                                class="gap-2"
                                :disabled="declining"
                                @click="onDecline"
                            >
                                <Spinner v-if="declining" />
                                {{ $t('auth.invite.declineConfirm.confirm') }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </template>
            <template v-else>
                <div class="flex items-start gap-3">
                    <Icon
                        name="error"
                        class="size-6 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('auth.register.inviteUnavailableDescription') }}
                    </p>
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
        <!-- An invalid or expired link: say so inline (the toast is transient). -->
        <Alert
            v-if="onInviteLink && !invite"
            variant="destructive"
            class="mb-6"
            data-test="invite-unavailable"
        >
            <Icon name="error" aria-hidden="true" />
            <AlertTitle>{{ $t('auth.register.inviteUnavailable') }}</AlertTitle>
            <AlertDescription>
                {{ $t('auth.register.inviteUnavailableDescription') }}
                {{ $t('auth.invite.unavailableNotice') }}
            </AlertDescription>
        </Alert>

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
                        autocomplete="name"
                        name="name"
                        :placeholder="$t('auth.register.namePlaceholder')"
                    />
                    <InputError for="name" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ $t('common.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                        :default-value="invite?.email"
                        :readonly="invite !== null && invite !== undefined"
                        :class="
                            invite ? 'bg-muted/50 text-muted-foreground' : ''
                        "
                    />
                    <InputError for="email" :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        $t('common.fields.password')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        required
                        autocomplete="new-password"
                        name="password"
                        :placeholder="$t('common.fields.password')"
                        :passwordrules="passwordRules"
                    />
                    <InputError for="password" :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        $t('auth.register.confirmPassword')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        autocomplete="new-password"
                        name="password_confirmation"
                        :placeholder="$t('auth.register.confirmPassword')"
                        :passwordrules="passwordRules"
                    />
                    <InputError
                        for="password_confirmation"
                        :message="errors.password_confirmation"
                    />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
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
                    :href="loginHref"
                    class="underline underline-offset-4"
                    >{{
                        $t('auth.login.submit')
                    }}</TextLink
                >
            </div>

            <div class="text-center text-xs text-muted-foreground">
                <TextLink
                    :href="privacy()"
                    class="underline underline-offset-4"
                    data-test="privacy-link"
                    >{{
                        $t('privacy.link')
                    }}</TextLink
                >
            </div>
        </Form>
    </template>
</template>
