# Accessibility and interface content

Accessibility is part of the design system, not a later polish pass. Target
WCAG 2.2 AA for user-facing flows and follow the established Vue/Reka UI
semantics. A visually M3-like component is not accessible merely because it
comes from a design system.

## Keyboard and assistive technology

- All actions and inputs work with a keyboard. Preserve logical tab order,
  native semantics, and a clearly visible focus indicator using the shared
  full-opacity `ring`/focus tokens (use a visible 2–3px ring and a background
  offset where the control's fill could reduce contrast). Do not use translucent
  focus-ring utilities such as `ring-ring/50`; test the rendered indicator in
  both themes. Never remove outlines without an equally visible replacement.
- Use headings in a meaningful hierarchy, landmarks, buttons for actions, and
  links for navigation. Give icon-only controls and non-text content
  accessible names; hide decorative icons from the accessibility tree.
- Labels, helper text, and validation errors are programmatically associated
  with their inputs. Announce important asynchronous status changes without
  making every keystroke noisy.
- Dialogs and sheets have a title, sensible initial focus, contained keyboard
  navigation when modal, Escape/close behavior, and focus restoration.
- Tables use real table markup and header cells. A chart has an accessible
  title and a text/structured-data equivalent; color is never the only source
  of meaning.

## Contrast, target size, and zoom

- Meet WCAG AA contrast: 4.5:1 for normal text, 3:1 for large text, and 3:1
  for essential control boundaries and visual state indicators. Check the
  actual token pair in both light and dark themes, including hover, disabled,
  selected, error, and focus states.
- Do not communicate state only with color. Pair it with text, an icon/shape,
  position, or another perceivable cue.
- Make interactive targets comfortably usable on touch. Aim for at least
  44×44 CSS px where practical; tightly packed data actions still need clear
  spacing and must meet WCAG 2.2 target-size requirements or a valid exception.
- At 320 CSS px viewport width (including the equivalent of 400% zoom from a
  1280 CSS px viewport), content reflows without two-dimensional scrolling for
  ordinary reading and actions. A genuinely two-dimensional data table may use
  a labeled, keyboard-accessible scroll region; keep surrounding page content
  reflowed. Also test 200% text resize/zoom for clipping and overlap.
- Keyboard focus is never entirely obscured by sticky headers, banners,
  dialogs, or other author-created content (WCAG 2.2 Focus Not Obscured).
  Prefer keeping the whole focus indicator and target visible after scrolling.

## Motion and perception

- Respect `prefers-reduced-motion`; remove nonessential movement while keeping
  the same state feedback and affordance.
- Do not use flashing or continuous animation to communicate ordinary status.
  Avoid relying on hover alone; the same information/action must be available
  to keyboard and touch users.
- Loading and saving indicators should not cause layout shifts or resemble
  actual data. Pair a spinner with a useful accessible status when the wait is
  meaningful.

## Financial privacy and correctness

- Treat permission masking as a security boundary, not a stylistic option.
  Use server-provided masking metadata, never calculate or reconstruct hidden
  amounts in the UI, and don't expose them through chart axes, hover content,
  `aria-label`, screen-reader-only text, skeletons, logs, or toasts.
- A dash or other masked marker must not be announced as zero. Provide enough
  context for users to understand that information is unavailable without
  revealing the protected value.
- Color and direction do not replace signed money, accessible labels, or
  locale-aware values. Keep revenue/expense meaning clear in text as well as
  visual treatment.

## Localization and readable copy

- Use the active locale for dates, numbers, and currency. Keep amounts and
  currency together in bidi contexts; do not manually reverse digit strings.
- Use logical CSS properties and test RTL where supported. Allow labels and
  buttons to wrap instead of truncating important instructions.
- Write concise, specific, user-centered copy in plain language. Prefer active
  verbs and explicit outcomes (“Save changes”, “Delete envelope”). Keep the
  label and resulting confirmation consistent.
- Explain an error and its recovery path. Treat empty states as guidance, not
  decoration or a dead end. Do not apologize vaguely or blame the user.

## Review checklist

- [ ] Complete the main flow without a mouse.
- [ ] Focus is always visible and does not get trapped outside an intentional
      modal interaction.
- [ ] Inputs, dialogs, tables, status changes, and charts have appropriate
      semantic names/roles and announcements.
- [ ] Text, controls, and focus pass contrast checks in both supported themes.
- [ ] No meaning depends on color, hover, motion, or sight alone.
- [ ] Reduced motion, zoom, narrow width, long labels, and RTL/bidi behavior
      have been considered.
- [ ] At 320 CSS px (or 400% zoom from a 1280 CSS px viewport), ordinary page
      content reflows; any two-dimensional table region is labeled and
      keyboard-accessible.
- [ ] Focus is not entirely hidden behind sticky or overlay content.
- [ ] Financial values remain locale-correct and protected values remain
      absent in every visual and accessibility representation.
