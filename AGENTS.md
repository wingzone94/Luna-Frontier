# AGENTS.md — Node / Luna Frontier

This is the project-wide contract for AI coding agents. Keep changes narrow, preserve existing content, and read the relevant scoped rule before editing.

## Project
- Product: **Node**, the WordPress theme used by **Luminous Core**.
- Do not rename or merge the Luminous Core and Node brands without explicit instruction.
- Current release/version truth comes from `style.css`, `README.md`, and `CHANGELOG.md`; do not hard-code an old version here.
- Build source of truth: `src/`. Generated assets live under `assets/`.
- Build command: `bun x vite build`.

## Before editing
1. Read this file.
2. Read only files needed for the task. Do not perform repository-wide exploration unless requested or necessary.
3. Check the applicable rules in `.cursor/rules/`.
4. Prefer one focused change at a time. Explain scope before any broad architectural change.

## Core constraints
- Preserve existing posts, WordPress block alignment, URLs, SEO/meta output, and public behavior unless the task explicitly changes them.
- Prefer fixes in `src/`; rebuild generated assets instead of patching minified/generated files directly.
- Do not introduce TypeScript or a new framework without explicit approval.
- Avoid unrelated rewrites, mass formatting, bulk deletion, large class-name changes, JS layout hacks, and `!important` proliferation.
- Do not change filled pills/badges/chips/FAB/series banners from white text/icons to dark colors without explicit approval.
- Respect WordPress alignment classes: `.has-text-align-*`, `.aligncenter`, `.alignleft`, `.alignright`, `.alignwide`, `.alignfull`.
- Prevent horizontal scrolling on mobile.

## Change scope
Normally safe when task-relevant: `src/main.js`, `src/scripts/*`, `src/styles/*`, `assets/*` via build, `inc/*`, `template-parts/*`.
Use extra care with `functions.php`, PHP template structure, global CSS, `theme.json`, JS initialization order, Vite config, plugin integration, SEO/OGP/meta output, and article-layout CSS.

## Validation
For code changes, run the checks that are available and relevant:
1. `bun x vite build`
2. Confirm manifest/generated assets match the change.
3. When the local test environment is available, sync to `cybernode.local` and check affected pages at desktop/mobile widths.
4. Never claim a check passed if it was not run. State unavailable checks as **not verified** with the reason.
5. Create release ZIPs only after testing, following `HOW_TO_RELEASE.md`.

## Local environment
Local paths and `cybernode.local` instructions are machine-specific. Use them only when that environment is actually available. Never report local validation from a remote/cloud agent that cannot access it.

## Reporting
Report: changed files, concise diff summary, build/test results, unverified items, existing-content impact, and remaining concerns.

## Scoped rules
- `.cursor/rules/wordpress-php.mdc` — PHP, templates, WordPress behavior.
- `.cursor/rules/frontend.mdc` — JS/CSS/UI and generated assets.
- `.cursor/rules/validation-release.mdc` — validation and release workflow.
- `.cursor/rules/agent-efficiency.mdc` — context and model-cost discipline.

Historical plans and old prompt documents are reference material, not automatically binding rules.