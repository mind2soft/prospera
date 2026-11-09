import { api } from '@/lib/api';

/**
 * The user's email notification preferences — mirrors the storage shape of
 * `users.notification_settings` (see docs/notifications.md). The keys are
 * snake_case to match the JSON payload sent by the server.
 */
export type NotificationSettings = {
    master: boolean;
    payment_reminders: boolean;
    ledger_expired: boolean;
    contributor_transactions: boolean;
    envelope_limit_exceeded: boolean;
    profile_invite: boolean;
};

export const notificationApi = {
    update: (
        settings: NotificationSettings,
    ): Promise<{ settings: NotificationSettings }> =>
        api('/settings/notifications', {
            method: 'PATCH',
            body: settings,
        }),
};
