# PrimeVue (UI components)

- PrimeVue (Aura preset) is the UI component library, registered via the Inertia v3 `withApp` callback in `resources/js/app.ts`.
- Components are AUTO-IMPORTED (`unplugin-vue-components` + `@primevue/auto-import-resolver` in `vite.config.ts`) — never manually `import Button from 'primevue/button'`; use `<Button>` and the resolver injects it (types in `resources/js/types/components.d.ts`). Services/directives still need explicit registration in `app.ts` (`ToastService`, `ConfirmationService`, `Tooltip`).
- Customize via PrimeVue v4 **design tokens (`dt`)**, in priority order: (1) global `definePreset(Aura, {...})` in `app.ts`; (2) per-instance `:dt` prop; (3) the `dt()` function in a preset's `css` block. Avoid hard-coded colors, ad-hoc CSS overrides, and `!important`.
- Tailwind v4 bridges via `tailwindcss-primeui` in `app.css`; the CSS layer order (`theme, base, primevue, utilities`, set in `app.ts`) lets Tailwind utilities override PrimeVue's styled layer.
- Icons use Tabler (`@tabler/icons-vue`) as Vue components passed into PrimeVue icon slots — not `primeicons` or lucide.
- Use PrimeVue `DataTable` for list/table screens (sort/filter/paginate/lazy built in). Calendar UI uses FullCalendar Vue 3 (separate package), not a PrimeVue calendar.
