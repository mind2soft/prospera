# Prospera design system

This is the visual and interaction contract for Prospera's frontend. It is for
product designers and frontend specialists implementing or changing user
interfaces. Follow it for new work; when an existing page has an intentional,
documented exception, preserve that behavior unless the task asks to change it.

## Product direction

Prospera makes personal finances feel understandable and manageable. The
interface should feel **calm, warm, trustworthy, and practical**—not like a
trading terminal, an accounting package, or a generic SaaS admin template. The
envelope metaphor is a useful product cue, not a reason to decorate every
surface with illustrations of envelopes.

Make money and choices easy to scan. Give the most important action clear
priority, use color to express semantic meaning, and keep charts and financial
tables quieter than the data they explain. Prefer a distinctive, restrained
detail over lots of decorative effects.

## Material 3 relationship

Prospera follows **Material Design 3 (M3) design-system principles**: semantic
color roles, a role-based type scale, intentional shape and elevation, familiar
component behavior, accessibility, and consistent tokens. The source reference
is Google's [Material Web](https://github.com/material-components/material-web)
repository and its [theming guidance](https://github.com/material-components/material-web/tree/main/docs/theming).

This is an M3-informed Prospera system, **not a Material Web implementation**.
The app is Vue 3 + Inertia with Tailwind CSS 4 and shadcn-vue/Reka UI. Continue
using the existing Vue components in `resources/js/components/ui/` and tokens
in `resources/css/app.css`; do not add `@material/web`, Lit, or a second
component framework just to imitate M3. Translate M3 concepts into the current
stack and product vocabulary. See [credits and notices](../../THIRD_PARTY_NOTICES.md).

## Read these documents in order

1. [Tokens](tokens.md) — color roles, typography, spacing, shape, elevation,
   motion, and financial data.
2. [Components and patterns](components.md) — when to use existing primitives
   and how to compose common Prospera flows.
3. [Accessibility and content](accessibility.md) — inclusive behavior,
   localization, privacy, and interface writing.

## Implementation source of truth

- **CSS variables and Tailwind theme mapping:** `resources/css/app.css`.
- **Reusable components:** `resources/js/components/ui/` (shadcn-vue built on
  Reka UI) and product-specific components in `resources/js/components/`.
- **Icons:** use the existing `Icon.vue`/`lib/icons.ts` Material Symbols
  convention for product icons and the existing `@lucide/vue` imports used by
  UI primitives/screens. Match the local component; don't replace an icon
  family wholesale or introduce a new package for ordinary UI.
- **Localization:** `resources/js/locales/` and `docs/localization.md`.
- **Product behavior:** the relevant product specification in `docs/` (for
  example `docs/dashboard.md`, `docs/budget.md`, or `docs/ledger.md`).

The CSS values are the implementation truth; this system describes their
intended roles. If a design needs a role that the current CSS does not expose,
add or map a semantic token in `app.css` and update [tokens.md](tokens.md).
Do not silently introduce one-off hex colors, arbitrary CSS values, or a
parallel `--md-*` token set in a component. Review light and dark themes
together whenever changing a shared token.

## Principles

1. **Clarity before ornament.** Make hierarchy, state, and next action obvious.
   Use decoration only when it helps comprehension or establishes the product's
   identity.
2. **Money deserves precision.** Preserve sign, currency, locale, period, and
   privacy state. Align amounts for scanning; never imply unavailable or
   masked values.
3. **Use semantic roles.** A color, type style, shape, or elevation level has a
   purpose. Reuse roles consistently rather than choosing a new value per page.
4. **Use familiar behavior.** Controls should behave as their accessible name
   suggests and follow the established app patterns.
5. **Be calm, not bland.** Keep supporting UI restrained while allowing one
   meaningful focal point—such as envelope progress or a clear page title—to
   carry character.
6. **Design for real conditions.** Support narrow screens, keyboard and assistive
   technology, reduced motion, long translated labels, empty data, failures,
   and permission-masked data from the start.

## Frontend specialist workflow

Before implementation, check the request, the existing page, its product spec,
and the three design-system documents. Decide what the user needs to notice,
understand, and do. Sketch the page hierarchy and state changes before styling.
Then build from existing tokens and components; add a new component only when
it expresses a reusable behavior or visual role that the current library lacks.

Before delivery, check at minimum:

- The UI uses existing semantic tokens and component patterns, with no
  accidental M3 component-library dependency.
- Default, loading, empty, error, disabled/pending, success, and masked or
  read-only states are appropriate to the feature.
- Layout works at narrow and wide viewports and with the active text direction.
- Keyboard focus, accessible names, contrast, and reduced-motion behavior meet
  [accessibility.md](accessibility.md).
- Money and dates use the app's locale-aware formatters; private values remain
  masked in every visual state.
- Relevant checks pass: `npm run check`, `npm run types:check`, and the
  applicable tests (including `npm run e2e` when a user journey changed).
