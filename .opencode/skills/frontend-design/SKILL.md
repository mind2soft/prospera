---
name: frontend-design
description: Design or reshape Prospera's frontend with a distinctive, usable visual direction that follows the project's M3-informed design system, existing Vue/Tailwind stack, and accessibility requirements.
license: Apache-2.0
compatibility: opencode
metadata:
  source: https://github.com/anthropics/skills/tree/main/skills/frontend-design
  adapted-for: Prospera
---

> **Adaptation notice:** Adapted from Anthropic's `frontend-design` skill for
> OpenCode and Prospera. The workflow and examples have been tailored to this
> project's M3-informed design system, Vue/Inertia stack, and existing visual
> identity. The upstream Apache-2.0 license is included in `LICENSE.txt`.

# Frontend design for Prospera

Act as the design lead for the requested frontend work. Make deliberate,
product-specific choices, but do not mistake novelty for quality or redesign
areas outside the user's scope. Prospera's design should be recognizable as
calm, practical personal-finance software—not a generic dashboard, a bank
marketing page, or a copy of Material Web.

## Required project context

Before choosing a visual direction or editing UI, read these project documents
from the repository root:

1. `docs/design-system/README.md`
2. `docs/design-system/tokens.md`
3. `docs/design-system/components.md`
4. `docs/design-system/accessibility.md`
5. The relevant product specification, such as `docs/dashboard.md`,
   `docs/budget.md`, `docs/envelope.md`, or `docs/ledger.md`.

Follow a more specific user request when it intentionally changes the visual
direction; preserve accessibility, financial privacy, and product behavior.
If the brief leaves a decision open but project context resolves it, make a
well-grounded choice. Ask only when an unresolved choice would materially
change scope, behavior, or an irreversible product decision.

## Project constraints

- The application is Vue 3 + Inertia, TypeScript, Tailwind CSS 4, and
  shadcn-vue/Reka UI. Use `<script setup lang="ts">` and the existing
  components in `resources/js/components/ui/`.
- The source of visual tokens is `resources/css/app.css`. Use semantic theme
  utilities and existing CSS variables; do not sprinkle raw colors or invent a
  parallel token system.
- M3 guides semantic roles and interaction patterns. Do **not** install or use
  Material Web, Lit, or a competing component framework unless the user
  explicitly asks for that architectural change.
- Preserve the project's Geist interface typeface, restrained Neuton display
  use, green brand accent, existing icon conventions (`Icon.vue`/bundled
  Material Symbols for product-specific glyphs and `@lucide/vue` where already
  used by UI primitives/screens), dark theme, locale-aware formatting, and data
  masking behavior. Do not migrate icon libraries as an unrelated cleanup.
- Use the app's existing localization, currency, layout, and toast patterns.
  Never expose masked amounts in rendered text, chart shape, accessibility
  names, tooltips, or loading states.

## Process: understand, plan, build, critique

### 1. Understand

Inspect the target page and neighboring screens. Identify the user's task,
content hierarchy, key action, responsive behavior, data states, and relevant
existing components. Check the product specification before changing behavior.

### 2. Plan

For meaningful visual work, make a compact design plan before implementation:

- **Intent:** what the user should notice, understand, and do.
- **Visual direction:** how the Prospera palette, type, surfaces, and shape
  serve this specific content.
- **Structure:** a short layout description or simple wireframe when layout is
  nontrivial; say how it reflows on narrow screens.
- **States:** loading, empty, error, pending/success, disabled/read-only, and
  masked states that the feature actually needs.

Review the plan against both the task and the design system. Remove any choice
that reads like a reusable SaaS template rather than a decision grounded in
Prospera's product and this page. Keep one meaningful focal point; don't make
every card, label, and action compete for attention.

### 3. Build

Implement with existing tokens and primitives first. Extend a wrapper or add a
reusable component only when it represents a genuine shared behavior or visual
role. Keep the information architecture and user-facing copy specific and
plain-language. Use semantic controls, logical CSS properties, locale-aware
dates/money, and explicit non-color cues for financial direction and state.

Use motion only to explain an interaction or state transition. Respect
`prefers-reduced-motion`. Avoid generic entrance animations, gradients,
ornamental metrics, arbitrary “eyebrow” labels, repetitive cards, and other
template defaults unless the brief gives them a clear purpose.

### 4. Critique and verify

Review the result against the design-system checklist. If available, inspect
the UI at narrow and wide widths and in light and dark themes; use screenshots
when the environment supports them. Check layout, contrast, focus, copy,
long/translated labels, and masked data. Remove any detail that adds noise
without improving comprehension or interaction.

Run checks appropriate to the change, normally `npm run check` and
`npm run types:check`; run relevant Playwright tests (`npm run e2e`) when a
user-visible flow changes. Report checks that could not be run rather than
claiming visual or accessibility verification that was not performed.

## Interface writing

Write from the user's perspective. Prefer specific labels that explain the
result (“Add envelope”, “Save changes”, “Delete transaction”) over generic
mechanics (“Submit”, “OK”). Keep action wording consistent through the flow,
including confirmations and success feedback. Use active voice, sentence case,
and short, concrete instructions. An empty state should offer direction; an
error should identify what happened and how to recover. Do not hide important
meaning in decorative copy or unexplained financial terminology.

## Design review checklist

- [ ] The visual direction follows Prospera's product context and the relevant
      design-system docs.
- [ ] Existing components and semantic tokens are reused; no unintended
      dependency or parallel design system was introduced.
- [ ] The focal hierarchy is clear and secondary surfaces remain quiet.
- [ ] Responsive layout, keyboard focus, touch use, zoom, and reduced motion
      have been considered.
- [ ] Controls have meaningful accessible names; errors, charts, and status
      changes have appropriate text/semantic equivalents.
- [ ] Text, control, and focus contrast has been considered in light and dark
      themes.
- [ ] Financial numbers use active-locale formatting and preserve sign,
      currency, and permission masking in every state.
- [ ] Applicable lint, type, and user-flow checks have been run and reported.
