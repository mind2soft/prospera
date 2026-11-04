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
import envelopes from './en/envelopes.json';
import layout from './en/layout.json';
import ledger from './en/ledger.json';
import settings from './en/settings.json';
import share from './en/share.json';
import welcome from './en/welcome.json';

const en = {
    ...common,
    ...auth,
    ...budget,
    ...envelopes,
    ...layout,
    ...ledger,
    ...settings,
    ...share,
    ...welcome,
};

export type MessageSchema = typeof en;

export const messages: Record<string, MessageSchema> = { en };
