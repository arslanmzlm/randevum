# PrimeVue (UI components)

PrimeVue is the UI component library (Aura preset). Registered via the Inertia v3 `withApp` callback in `resources/js/app.ts` (`app.use(PrimeVue, { theme: { preset: Aura, options: { cssLayer: {...} } } })`).

## Auto-import

- Components are AUTO-IMPORTED via `unplugin-vue-components` + `@primevue/auto-import-resolver` (configured in `vite.config.ts`). Do NOT manually `import Button from 'primevue/button'` — just use `<Button>` in templates and the resolver injects the import. Generated types live in `resources/js/types/components.d.ts`.
- Non-component services/directives still need explicit registration in `app.ts` (e.g. `app.use(ToastService)`, `app.use(ConfirmationService)`, `app.directive('tooltip', Tooltip)`).

## Customization — design tokens first

Customize via PrimeVue v4 **design tokens (`dt`)**, in this priority:

1. Global theme: `definePreset(Aura, { ... })` (semantic/primitive token overrides) registered in `app.ts`.
2. Per-instance: the `:dt` prop on a component for one-off token overrides.
3. Token-referencing CSS: the `dt()` function inside a preset's `css` block.

Avoid hard-coded colors, ad-hoc CSS overrides, and `!important`. Tokens keep theming consistent and dark-mode-aware.

## Tailwind v4 integration

- `app.css` imports `tailwindcss` + `tailwindcss-primeui` (bridges PrimeVue tokens into Tailwind) + `primeicons`.
- The CSS layer order (`theme, base, primevue, utilities`, set in `app.ts`) lets Tailwind utilities override PrimeVue's styled layer when needed.
- Icons use `primeicons` (`pi pi-*`) — not lucide.

## Components

- Use PrimeVue `DataTable` for list/table screens (patients, appointments, payments) — sort/filter/paginate/lazy built in.
- Calendar UI uses FullCalendar Vue 3 (separate package), not a PrimeVue calendar.
