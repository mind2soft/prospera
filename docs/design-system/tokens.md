# Prospera design tokens

Tokens are shared design decisions, not a collection of convenient CSS values.
This follows M3's distinction between reference values, semantic system roles,
and component-level choices, adapted to Prospera's existing CSS custom
properties and Tailwind CSS 4 theme.

## Token layers

1. **Reference values** are concrete values such as the project typefaces and
   the spacing/radius scale.
2. **System roles** describe why a value is used (`primary`, `surface`,
   `on-surface`, `error`, and so on). Use the semantic Tailwind classes already
   mapped in `resources/css/app.css` (`bg-background`, `text-foreground`,
   `bg-primary`, `text-muted-foreground`, `border-border`, etc.).
3. **Component roles** apply system roles to a particular control. Prefer the
   existing shadcn-vue variant/size props. A one-off component override must
   still use system tokens and be justified by a real state or hierarchy.

Do not write M3's `--md-sys-*` names directly in app components: Material Web
is not installed. The table below is a **semantic crosswalk**, not a claim that
the current palette contains every M3 role or generated tonal palette.

## Color

The current light/dark values live in `app.css`; change them there, never in an
isolated page. Keep all foreground/background pairs legible in both themes.

| M3 concept | Current Prospera token / utility | Intent |
| --- | --- | --- |
| Primary / on-primary | `--primary` / `--primary-foreground`; `bg-primary text-primary-foreground` | Highest-priority contained action and selected emphasis. |
| Secondary / on-secondary | `--secondary` / `--secondary-foreground`; `bg-secondary text-secondary-foreground` | Lower-emphasis contained surfaces and controls. |
| Tertiary brand accent | `--brand` / `--brand-foreground`; `bg-brand text-brand-foreground` | Prospera's green accent where the brand itself is the message; do not use as a generic replacement for `primary`. |
| Error / on-error | `--destructive` / `--destructive-foreground`; `bg-destructive text-destructive-foreground` | Errors and destructive actions only. |
| Surface / on-surface | `--background` / `--foreground`; `bg-background text-foreground` | Main page surface and default content. |
| Surface container | `--card` / `--card-foreground`; `bg-card text-card-foreground` | Grouped content that needs a distinct container. |
| Elevated surface | `--popover` / `--popover-foreground`; `bg-popover text-popover-foreground` | Menus, popovers, and floating dialog surfaces. |
| Surface variant / on-surface variant | `--muted` / `--muted-foreground`; `bg-muted text-muted-foreground` | Quiet support content, secondary labels, and subdued surface areas. |
| Outline / outline variant | `--border`, `--input` | Boundaries and input outlines; use the lightest boundary that still communicates structure. |
| Focus indicator | `--ring`; `ring-ring` / `outline-ring` | Visible keyboard focus. Use the full-opacity token, not a translucent `/50` ring. A 2–3px ring with a `background` offset makes the indicator distinct from filled controls; preserve the theme-specific contrast. Never remove it without an equally visible replacement. |
| Financial chart roles | `--chart-revenue`, `--chart-expense`, `--chart-1`…`--chart-5` | Existing chart palette. Use only through chart tokens and pair color with labels, signs, or symbols. |

The existing `--brand` and `--primary` are intentionally distinct today. Do
not assume the brand accent is the primary action color. Current aliases also
do not expose every M3 role (for example, a full primary-container/on-primary-
container pair or the surface-container tonal ladder). If a component needs a
missing semantic role, first decide whether it is genuinely needed; then add a
named light/dark token pair in `app.css`, map it into Tailwind, and document its
purpose here. Do not substitute an unrelated role just to avoid defining one.

### Color behavior

- Use color to show hierarchy, meaning, selection, or state—not as decoration.
- Revenue and expense must remain distinguishable without color: keep their
  signed/formatted values and labels or direction icons. Do not rely on
  red/green alone.
- Envelope colors are user-configurable data. Keep the envelope name visible;
  do not assume a user-picked hue has accessible text contrast or encodes
  revenue/expense. Use a neutral text surface and add a boundary or symbol when
  needed.
- Never reveal masked financial amounts through a chart, tooltip, accessible
  label, skeleton size, color intensity, or empty-state copy. Follow the
  masking behavior in the relevant product spec (especially `docs/dashboard.md`
  and `docs/ledger.md`).
- Check text contrast against its actual surface in both themes. Use the
  requirements in [accessibility.md](accessibility.md); do not infer contrast
  from hue or token names.

## Typography

Prospera's current typefaces are **Geist** for plain/interface text and
**Neuton** for selected display headings (`--font-sans`, `--font-display` in
`app.css`). This is the project's M3-style plain/brand pairing. The values
below are a Prospera-tuned scale using M3's role taxonomy, not a claim to use
M3's unmodified default measurements. Do not add a third family or load an
external face for a single page without a clear need.

| M3 role | Size / line height | Face / weight | Typical utility mapping and use |
| --- | --- | --- | --- |
| Display large | 48 / 56px | Neuton 400 | `text-5xl leading-[1.167]`; rare product/marketing-level focal heading, not app chrome. |
| Headline large | 30 / 36px | Neuton 400 | `text-3xl leading-9`; `Heading.vue` page heading and major empty-state message. |
| Headline small | 24 / 32px | Geist 600 | `text-2xl leading-8`; secondary page-level heading when hierarchy needs it. |
| Title large | 20 / 28px | Geist 600 | `text-xl leading-7`; section and dialog title. |
| Title medium | 16 / 24px | Geist 500 | `text-base leading-6 font-medium`; card/widget and dense section title. |
| Body large | 16 / 24px | Geist 400 | `text-base leading-6`; primary explanatory copy. |
| Body medium | 14 / 20px | Geist 400 | `text-sm leading-5`; default supporting copy and control text. |
| Body small | 12 / 16px | Geist 400 | `text-xs leading-4`; secondary metadata only, not essential instructions. |
| Label large | 14 / 20px | Geist 500 | `text-sm leading-5 font-medium`; form labels and prominent control labels. |
| Label medium | 12 / 16px | Geist 500 | `text-xs leading-4 font-medium`; table headers and compact metadata, sentence case. |

Use the listed utility mapping and `Heading.vue` for these roles; avoid custom
pixel sizes where a role fits. The 48px display role is reserved for a truly
large focal moment; most app pages should start at Headline large or smaller.
Keep important body copy at 14–16px or larger. Financial values use Geist with
tabular numerals (`tabular-nums`), right/end alignment in tabular contexts, and
enough weight to scan without making every value bold. Never shrink primary
money values to fit; allow wrapping or responsive reflow instead.

## Spacing and layout

Use Tailwind's 4px-root spacing scale as the shared rhythm: 4, 8, 12, 16, 24,
32, 48, and 64px (`1`, `2`, `3`, `4`, `6`, `8`, `12`, `16`). Use smaller
values for compact control internals and larger values between distinct
sections. Prefer `gap-*` for layout relationships. Avoid arbitrary one-off
spacing unless the visual alignment requires it and there is no scale value
that works.

Keep page content aligned to a consistent container and use responsive grid
changes based on available width. Dense financial tables may prioritize
information density on large screens, but must reflow or provide a deliberate
small-screen alternative; do not simply shrink text or introduce page-level
horizontal scrolling for essential actions.

## Shape

The app's base radius is currently `--radius: 0.625rem` (10px), exposed through
Tailwind `rounded-sm`, `rounded-md`, `rounded-lg`, and `rounded-xl`. Use that
existing scale rather than setting a new radius for every component:

- Smaller controls and compact nested containers: `rounded-sm` or `rounded-md`.
- Cards, sheets, and dialogs: `rounded-lg` or `rounded-xl` according to their
  hierarchy and existing component implementation.
- `rounded-full`: pills, avatars, or circular controls only when the shape
  communicates the component's role.

Do not apply the same large radius to every surface. Shape should signal
containment, selection, or control affordance—not add softness indiscriminately.

## Elevation and surfaces

Use the existing `--elevation-1` and `--elevation-2` only when a surface must
visibly lift above content (for example, an open popover or floating layer).
Most cards and page sections should be separated by surface tone, spacing, or a
subtle border rather than a shadow. Avoid a shadow on every card, stacked
shadows, or shadows as a substitute for hierarchy. Preserve the light/dark
theme-specific values in `app.css`.

## Motion

Motion should clarify a state change or direct manipulation: opening a dialog,
expanding a section, saving, or dragging a dashboard widget. Keep transitions
short and purposeful (roughly 100–200ms for small feedback and up to 300ms for
a larger surface transition); do not animate every card into view or use motion
to compensate for unclear hierarchy. Honor `prefers-reduced-motion` and avoid
continuous motion. State changes must still be understandable with animation
disabled.

## Icons and charts

Use the existing `Icon.vue`/bundled Material Symbols for product-specific
glyphs and the existing `@lucide/vue` imports where established UI primitives
and screens use them. Don't migrate between these conventions as part of an
unrelated feature. Keep icons optically aligned with text; decorative icons
are hidden from assistive technology. Never use an icon alone for a high-impact
action unless it has an accessible name and the icon is unambiguous.

Charts should use the chart tokens from `app.css`, readable labels, and a
legend or equivalent textual summary. Use axes/gridlines sparingly. For
financial comparisons, preserve sign and locale formatting in labels and
tooltips; never make color the only encoding. See `docs/dashboard.md` for
current chart semantics and privacy rules.
