/**
 * The Material Symbols icons the app renders. Only these are bundled: the
 * `material-symbols` Vite plugin (vite.config.ts) builds the
 * `virtual:material-symbols` subset from this list at build time, instead
 * of shipping the whole collection (~8 MB). `<Icon name>` is typed against
 * it, so an icon missing here fails the type check — add it to the list.
 *
 * Kept free of imports: vite.config.ts loads this file directly.
 */
export const MATERIAL_SYMBOLS = [
    'add',
    'bar-chart',
    'calendar-month',
    'category',
    'check',
    'check-circle',
    'check-circle-outline',
    'check-indeterminate-small',
    'chevron-left',
    'chevron-right',
    'close',
    'content-copy',
    'dashboard',
    'delete',
    'donut-large',
    'drag-indicator',
    'edit',
    'error',
    'expand-more',
    'group',
    'group-add',
    'info',
    'link',
    'lock',
    'lock-open',
    'logout',
    'mail',
    'more-vert',
    'north',
    'north-east',
    'person',
    'person-add',
    'person-remove',
    'publish',
    'receipt-long',
    'savings',
    'settings',
    'shield-lock',
    'south',
    'south-west',
    'star',
    'star-outline',
    'swap-horiz',
    'table-chart',
    'thumb-up-outline',
    'tune',
    'warning',
] as const;

export type MaterialSymbol = (typeof MATERIAL_SYMBOLS)[number];
