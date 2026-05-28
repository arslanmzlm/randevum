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
- **Reference projects (e.g. tezkolay) are for patterns/ideas, not to copy structure wholesale** —
  take the idea, build the minimal thing THIS project needs.
