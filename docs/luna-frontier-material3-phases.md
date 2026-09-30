# Luna Frontier 2.0 Material 3 Phase Plan

Last updated: 2026-08-23

This document tracks Phase 1-10 for Luna Frontier 2.0 and records the Material 3
checks used during implementation.

## Google Material 3 Baseline

Use these official references as the compliance frame:

- Material 3 design tokens: reference tokens, system tokens, and component tokens.
- Material 3 color system: source color generates tonal palettes, then tones are
  assigned to color roles.
- Material 3 color roles: role names describe where a color is used, not the
  visual color itself.
- Material 3 state layers: hover, focus, pressed, and dragged states use
  systematic opacity layers.
- Material 3 elevation: surfaces primarily separate through tonal differences;
  shadows are reserved for actual z-axis separation.
- Material 3 typography: type roles are assigned through tokens, not one-off
  hard-coded sizes.
- Material 3 for Web: Material Web Components are in maintenance mode and M3
  Expressive is not implemented on Web, so Luna keeps the existing WordPress /
  CSS custom property architecture instead of adding `@material/web`.

## Namespace Rule

| Namespace | Meaning | Rule |
| --- | --- | --- |
| `--md-sys-*` | Material 3 system roles | Use only for M3 roles or local aliases to real M3 roles. |
| `--lc-*` | Article seed-derived roles | Generated per page/card from featured image seed. |
| `--lf-*` | Luna Frontier decisions | Paper, brand frame, spacing, layout, and custom web constraints. |
| `--m3-*` | Parent Node compatibility aliases | Keep as compatibility shims until parent debt is retired. |

## Phase 1: Role Map

Completed.

| Area | M3 role | Luna role | Current state |
| --- | --- | --- | --- |
| Page article color | source color -> tonal palette | `_lc_seed_color`, `--lc-*` | Implemented in PHP. Switched from HSL role tones to OKLCH role tones. |
| Primary action | `primary`, `on-primary` | `--lc-primary`, `--lc-on-primary` | Used by search submit and available for CTA. |
| Article header surface | `primary-container`, `on-primary-container` | `--lc-primary-container`, `--lc-on-primary-container` | Implemented. Header body text derives from on-container. |
| Reading surface | `surface`, `surface-variant`, `on-surface` | `--lf-paper`, `--lc-surface-variant`, `--lc-on-surface` | Implemented for article body and quote surface. |
| Brand frame | Custom product color | `--lf-brand`, `--lf-brand-text`, `--lf-on-brand` | Kept separate from dynamic color. Header/footer do not inherit article seed. |
| State layers | hover/focus/pressed opacity | `--lf-state-hover`, `--lf-state-focus`, `--lf-state-pressed` | Implemented as aliases to parent M3 state tokens with fallbacks. |
| Shape | M3 shape scale | `--lf-shape-paper`, `--lf-shape-action`, `--lf-shape-full` | Implemented. Paper is square; chips/actions can remain rounded. |
| Typography | M3 type roles | `--md-sys-typescale-*` mapped to `--lf-text-*` | Implemented for main display/headline/body/label roles. |
| Elevation | tonal surfaces / elevation tokens | `--m3-elevation-*`, `--lf-paper-*` | Luna translates token elevation to Paper surfaces; parent direct shadows remain Phase 4 debt. |

## Phase 2: Component Inventory

Completed.

| Component | Variant decision | Color source | Shape | Elevation/state rule |
| --- | --- | --- | --- | --- |
| Header / footer | Brand chrome | Fixed `--lf-brand` / parent tokens | Parent | May retain elevation as frame. No article seed. |
| Topic nav pick | Expressive brand chip | `--lf-brand` + `--lf-on-brand` | Full | State layer only. |
| Topic nav links | Outlined/text navigation | Brand text | Full / underline | State layer only. |
| Article header | Container surface | `--lc-primary-container` | Paper | No shadow. |
| Article body | Reading paper | `--lf-paper` | Paper | No shadow. |
| Quote | Surface variant | `--lc-surface-variant` + brand rule | Paper | No shadow. |
| Search form | Outlined field + filled submit | Surface + `--lc-primary` | Paper/action/full | Submit uses state layer. |
| Cards | Paper card | Paper + optional per-card spectrum accent | Paper | No resting shadow; hover/focus by border. |
| Notice alert | Error role | `--md-sys-color-error-*` | Paper | No shadow. |
| TOC links | Current location | `--lc-accent` | Text/list | Border/start rule, state by color. |
| Floating controls | Actual floating UI | Parent M3 tokens | Round | Elevation may remain. |

## Phase 3: OKLCH Tonal Roles

Completed in `luna-frontier/inc/dynamic-color.php`.

The PHP runtime now:

- Converts seed RGB to OKLCH.
- Falls back to brand hue when a seed is nearly neutral and hue is unstable.
- Generates light/dark role tokens from OKLCH tone values.
- Reduces chroma to fit sRGB gamut while preserving hue and tone.
- Checks accent contrast against both paper and article-header container.
- Keeps the existing `--lc-*` output contract so CSS consumers do not need a
  large migration.

Quality check: compared 300 Luna OKLCH roles against official Material Color
Utilities HCT roles using a temporary `@material/material-color-utilities@0.2.7`
install outside the repo. Average RGB distance was 58.6. The largest differences
were expected and accepted:

- Neutral / black / white seeds intentionally fall back to Luna brand orange
  because their hue is unstable in OKLCH.
- Yellow / lime / green / cyan / sky light `on-secondary` uses black where Luna's
  lighter secondary surface needs it for AA contrast, even when HCT uses white
  against a darker secondary tone.

Decision: keep the current OKLCH role tones. The visual gap is a product
constraint difference, not a Material role mapping bug, and the contrast verifier
continues to pass all tracked roles.

## Phase 4: Base Token Debt

Completed for Luna scope.

Debt that stays in the Node 1.x-derived base stylesheet, not inside Luna-specific CSS:

- Direct `box-shadow` declarations that bypass `--m3-elevation-*`.
- Direct `filter: brightness()` state changes that bypass state layers.
- Direct radius values that bypass M3 shape aliases.
- Custom color roles under `--md-sys-*` that are not actual M3 roles.

Rule: only move those base declarations to tokens when the computed value is
unchanged, or isolate the change to Luna.

Luna decision: this debt stays out of the Luna-specific phase set. Luna
isolates its Paper surfaces, dynamic roles, shapes, and state layers inside
the same theme, without a parent theme and without rewriting the inherited
token architecture in the same change.

## Phase 5: Spectrum Cards

Completed with a conservative implementation.

Implemented:

- Luna template `template-parts/article-card.php` adds per-post spectrum
  tokens to the card root.
- Tokens are generated from the same seed resolution path as single articles.
- CSS consumes the card seed only for border/focus and weak media/no-image
  surfaces.
- Home / archive / search main loops explicitly preload post meta and thumbnail
  attachment meta before card rendering, so `_lc_seed_color` and image seed cache
  reads do not turn into per-card lazy queries.

Guardrails:

- Do not recolor category chips; they remain wayfinding.
- Do not fill every card with saturated article colors.
- Do not add extra queries per card beyond existing meta/thumbnail color paths.
  If the card seed path expands, extend the loop preload instead of resolving
  new data lazily from each card.
- Keep home LATEST count and archive pagination unchanged.

Phase 5 decision: keep Spectrum as a quiet M3 role accent, not a saturated grid
fill, and preload seed-related meta for list screens.

## Phase 6: Page Layout

Completed in `luna-frontier/page.php` and
`luna-frontier/src/styles/_page-layout.css`.

Implemented:

- Pages use Luna Paper sizing and align their readable card width with article
  body surfaces.
- Desktop-only page support areas can become a two-column layout when the page
  actually has headings or secondary content.
- Pages without useful table-of-contents content remain single-column, avoiding
  empty sidebar chrome.
- Mobile remains a single reading column.

Decision: do not globally turn pages into a site-wide two-column template. The
layout only expands when page content provides a real navigation/support target.

## Phase 7: Dynamic Color Application Scope

Completed in `luna-frontier/inc/dynamic-color.php` and
`luna-frontier/src/styles/_dynamic-color.css`.

Implemented:

- Dynamic color is scoped to `body.lf-theme` and emitted in the initial HTML
  head, so first paint has the correct article roles.
- Article links, current TOC state, selected article UI, focus affordances, and
  card accents consume `--lc-*` roles.
- Header, footer, category badges, and global brand chrome keep fixed brand /
  wayfinding colors instead of inheriting article seed colors.
- Dark mode receives equivalent scoped tokens through both `body[data-theme]`
  and `html[data-theme] body` selectors.

Decision: article seed color is content personality, not global navigation
identity.

## Phase 8: Notice / Semantic Feedback Surfaces

Completed in `luna-frontier/src/styles/_notice.css`.

Implemented:

- `m3-notice--alert` now maps to Material 3 error roles.
- Info, warning, and memo notices remain custom semantic colors because Material
  3 does not define first-class roles for those statuses.
- Notice blocks use Luna Paper shape, so they no longer keep the Node-derived
  rounded card shape inside article paper.
- Light / dark alert contrast was checked against AA thresholds before adoption.

Decision: only connect alert to `error`; do not pretend custom info / warning /
memo colors are Material system roles.

## Phase 9: Search

Completed in `luna-frontier/search.php` and
`luna-frontier/src/styles/_search.css`.

Implemented:

- Search uses Paper surfaces and Material 3 outlined-field semantics.
- The submit action uses a filled primary treatment with state-layer behavior.
- Advanced search remains a native `details` interaction and does not require
  JavaScript.
- Search result cards reuse the same Luna card surface and Spectrum accent path
  as home / archive lists.

Decision: keep the search screen operational and quiet, with Material 3 form
semantics rather than a separate expressive landing-page treatment.

## Phase 10: Article Cards

Completed in `luna-frontier/template-parts/article-card.php` and
`luna-frontier/src/styles/_cards.css`.

Implemented:

- Article cards use Luna Paper surfaces, square Paper shape, and no resting
  shadow.
- Category chips keep their wayfinding colors and pill shape.
- Per-card Spectrum accent is applied only to borders, focus, and weak media /
  no-image surfaces.
- Container Queries adjust card density from the card's own width rather than
  the viewport.

Decision: cards should feel unified as Paper objects while still carrying a
subtle article-color signal.

## Verification

Completed checks for Phase 1-10:

- `php -l luna-frontier/inc/dynamic-color.php`
- `php -l luna-frontier/functions.php`
- `php scripts/verify-luna-material3-roles.php`
- `bun x vite build`
- `bun x vite build --config luna-frontier/vite.config.js`
- `git diff --check`
- LocalWP browser verification on home, archive, search, and representative
  single/page surfaces.
