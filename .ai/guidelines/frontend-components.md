# Frontend components & reuse

- Prefer an existing shared component over a raw PrimeVue component. Resolution order: project
  component (`resources/js/components/**`) → PrimeVue component → only then a bespoke element.
- Extract repeated UI structure into a component instead of re-wiring it inline. Form fields use a
  single wrapper, `components/FormField.vue` (label + required + error/hint, wraps the control in PrimeVue
  `FloatLabel variant="in"`). It auto-injects the field id (`inputId` for PrimeVue controls with an
  inner input, else `id`) and `invalid` into the slotted control via `cloneVNode` — callers pass
  neither. Do NOT add a wrapper per control type; just drop the PrimeVue control into FormField's slot.
- Local components are NOT auto-imported (the `Components()` resolver covers only PrimeVue) — import
  them explicitly (`@/components/FormField.vue`). PrimeVue components stay auto-imported.
- FloatLabel-based fields use the label as the in-field placeholder — do NOT also pass a `placeholder`.
- Icons are Tabler (`@tabler/icons-vue`), never PrimeIcons (not installed). When a PrimeVue control's
  default icon is a PrimeIcon (e.g. Password's mask toggle), supply a Tabler icon via the control's
  icon slot (`#maskicon`/`#unmaskicon`, with `toggleCallback`) and position it yourself
  (`absolute right-3 top-1/2 -translate-y-1/2`).
- Layouts: auth/guest pages use `layouts/AuthLayout.vue`; authenticated B2B pages use
  `layouts/AppLayout.vue` (sidebar + topbar shell; mounts `<AppToaster/>`) via
  `defineOptions({ layout: AppLayout })`. The sidebar `navItems` only lists routes that already
  exist — when a feature adds an authenticated page, append its nav entry there (don't pre-add links
  to unbuilt routes). The admin panel is separate from AppLayout.
- User feedback uses flash **toasts**, not ad-hoc inline banners. Server side:
  `App\Modules\Core\Support\Toast::success|info|warning|error(...)` flashes to the session;
  `HandleInertiaRequests` shares them as `flash.toasts`; `<AppToaster/>` (PrimeVue `Toast` +
  `useToasts()` composable) renders them and must be mounted in every page shell/layout. A failed
  (validation) request shows ONE generic error toast (`common.form_error`) — never one per field;
  inline `FormField` errors stay the per-field signal.
- Auth forms must carry `autocomplete` tokens so password managers map fields correctly: the
  account field (email) = `autocomplete="username"`, new-password fields = `autocomplete="new-password"`,
  current-password (login) = `autocomplete="current-password"`. Without a `username` field, browsers
  mis-detect the first password input as the username.
- **Reference projects (e.g. tezkolay) are for patterns/ideas, not to copy structure wholesale** —
  take the idea, build the minimal thing THIS project needs.
