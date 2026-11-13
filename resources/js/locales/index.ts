/**
 * Translation messages, one file per domain under `en/`. Every file owns a
 * single top-level namespace named after the domain (e.g. `auth`), so the
 * objects can be merged without collisions. Adding a new locale means
 * creating a sibling folder (e.g. `fr/`) with the same namespaces and
 * registering the locale in `config/app.php` under `available_locales`.
 */
import auth from './en/auth.json';
import budget from './en/budget.json';
import common from './en/common.json';
import contact from './en/contact.json';
import dashboard from './en/dashboard.json';
import envelopes from './en/envelopes.json';
import layout from './en/layout.json';
import ledger from './en/ledger.json';
import onboarding from './en/onboarding.json';
import privacy from './en/privacy.json';
import settings from './en/settings.json';
import share from './en/share.json';
import welcome from './en/welcome.json';
import authFr from './fr/auth.json';
import budgetFr from './fr/budget.json';
import commonFr from './fr/common.json';
import contactFr from './fr/contact.json';
import dashboardFr from './fr/dashboard.json';
import envelopesFr from './fr/envelopes.json';
import layoutFr from './fr/layout.json';
import ledgerFr from './fr/ledger.json';
import onboardingFr from './fr/onboarding.json';
import privacyFr from './fr/privacy.json';
import settingsFr from './fr/settings.json';
import shareFr from './fr/share.json';
import welcomeFr from './fr/welcome.json';

const en = {
    ...common,
    ...auth,
    ...budget,
    ...contact,
    ...dashboard,
    ...envelopes,
    ...layout,
    ...ledger,
    ...onboarding,
    ...privacy,
    ...settings,
    ...share,
    ...welcome,
};

const fr = {
    ...commonFr,
    ...authFr,
    ...budgetFr,
    ...contactFr,
    ...dashboardFr,
    ...envelopesFr,
    ...layoutFr,
    ...ledgerFr,
    ...onboardingFr,
    ...privacyFr,
    ...settingsFr,
    ...shareFr,
    ...welcomeFr,
};

export type MessageSchema = typeof en;

export const messages: Record<string, MessageSchema> = { en, fr };
