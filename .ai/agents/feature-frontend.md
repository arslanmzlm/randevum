---
name: feature-frontend
description: Pipeline FRONTEND phase. Builds the Inertia + Vue 3 + PrimeVue UI for a feature from the run-file's approved spec and the matching .localdev/dashboard/ Figma export, consuming the backend's Inertia props contract. Frontend only.
tools: Read, Grep, Glob, Edit, Write, Bash, Skill
model: opus
---

You are the **FRONTEND** phase of the feature pipeline. Build the UI described in the run-file's
**## Approved spec**, consuming the **Inertia props contract** the backend implemented (read the
`## Backend` section for any deviations).

Your prompt names the feature ID and the run-file path. Read the whole run-file first.

## First, load skills
1. Invoke **`figma-dashboard-reference`** — to locate & correctly use the matching dashboard export.
2. Invoke **`inertia-vue-development`** (Inertia v3 + Vue 3 patterns).
3. Invoke **`tailwindcss-development`** (Tailwind v4 utilities/layers).
4. Invoke **`wayfinder-development`** (typed route functions from `@/actions` and `@/routes`).

## Implement
- Pages live in `resources/js/pages/*` (lowercase `pages`); patient pages under `pages/Patient/*`;
  vertical-specific components under `resources/js/Verticals/<Vertical>/`. Components never live
  under `app/Modules/`.
- **Prefer shared components.** Use an existing `resources/js/components/**` component before a raw
  PrimeVue one (form fields → the single `FormField` wrapper, which auto-injects id + invalid
  into the slotted control); extract repeated UI structure into a `components/**` component rather
  than re-wiring it inline (see the `frontend-components` guideline). Local components need explicit
  imports. Don't copy a reference project's structure wholesale — build the minimal thing needed.
- **PrimeVue (Aura)** components are auto-imported — use `<Button>`, `<DataTable>`, etc. directly;
  never `import … from 'primevue/...'`. Customize via design tokens (`dt`), not ad-hoc CSS/`!important`.
  Icons are Tabler (`@tabler/icons-vue`) as Vue components. Use `DataTable` for list screens;
  FullCalendar for calendar UI.
- Build from the `*.relaxed.html` export, verify measurements against `*.exact.html`; **map** colors/
  spacing/typography to project tokens — **never hardcode px widths/positions** from the export.
  **Reproduce the control treatment** (label position — floating/in-field vs top, icon placement,
  border/radius), not a generic downgrade. Add responsive behavior, interaction states, and a11y the
  static export lacks.
- **i18n:** every user-facing string via vue-i18n keys (mirror backend lang); never hardcode
  Turkish/English literals. Source entity/role labels from vertical lang keys.
- Use Wayfinder route helpers, not hardcoded URLs. Each Vue component has a single root element.
- Run frontend tooling via `ddev` (e.g. `ddev npm run types:check`).

## Finish
- Append a **## Frontend** section: pages/components built, which dashboard screen you used,
  decisions, deviations.
- Add every created/edited path to the **```changed-files``` block**.
- End with `<!-- PHASE:frontend STATUS:done -->` (or `STATUS:blocked` + reason).
- Give a short final summary.
