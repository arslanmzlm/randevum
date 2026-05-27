---
name: figma-dashboard-reference
description: "Use when building a Vue/Inertia screen that has a Figma→HTML export in .ai/dashboard/ (login, sign-up, dashboard/home, appointments list, create-appointment, detail, profile, balance). Explains how to read the *.relaxed.html / *.exact.html exports as a structural + visual reference (not code to copy), maps each export to its feature, and gives the rules for translating it into this project's PrimeVue + Tailwind + i18n stack. Trigger in the frontend phase of the feature pipeline or any time a dashboard screen is being implemented."
license: MIT
metadata:
  author: randevum
---

# Figma dashboard reference

`.ai/dashboard/` holds HTML exports of Figma frames — a **structural and visual reference** for
re-implementing screens in this project, **not** code to copy. Full guidance: `.ai/dashboard/README.md`.

## Screen → feature map

| Export (`.ai/dashboard/`) | Feature (`features.md`) | Builds toward |
|---|---|---|
| `sign-in-4.{relaxed,exact}.html` | 1.2 | Login page |
| `sign-up-4.{relaxed,exact}.html` | 1.1 | Registration / onboarding |
| `ana-sayfa.{relaxed,exact}.html` | 1.3 | Clinic dashboard (stats + calendar summary + widget) |
| `randevular.{relaxed,exact}.html` | 1.4 / 1.5 | Appointments calendar + list/filter |
| `randevu-olu-tur.{relaxed,exact}.html` | 1.6 | Create-appointment form |
| `detay.{relaxed,exact}.html` | — | Appointment/treatment detail (Process) |
| `profil.{relaxed,exact}.html` | 1.26 | Profile / settings |
| `bakiye.{relaxed,exact}.html` | 1.23 | Balance view |

Each screen has two files: start from **`*.relaxed.html`** (flexbox flow — order, grouping,
spacing) and verify measurements against **`*.exact.html`** (absolute, pixel-faithful spec sheet).

## How to read an export

- **HTML comments are the design's intent** — `<!-- Button -->`, `<!-- Form -->`, `<!-- Card -->`,
  `<!-- icon: ic_eye -->` are the original Figma layer names. Use them to choose semantics
  (`Button` → real `<button>`/PrimeVue `<Button>`, a `Form`/input box → `<input>`, etc.).
- **Design values are real** — colors, font family/size/weight, line-heights, radii, the relative
  spacing rhythm come from Figma. Reuse the *intent*; map to project tokens.
- **Icons** are named (`ic_eye`, `ic_down`, flags). No `assets/` folder is present → the dashed gray
  boxes / gray rects are placeholders; use the project's real icons (Tabler) and images.
- **`⚠ COMPOSITION NOTE`** at the top (if present) means the file is only the page shell; real
  content lives in overlay frames not in this file — obtain those before building.

## Rules — match the project, don't transcribe the file

1. **Use the project stack & conventions.** PrimeVue (Aura, auto-imported), Tailwind v4 utilities +
   design tokens, Tabler icons, vue-i18n. Match existing patterns; don't introduce a new styling
   approach or ad-hoc CSS/`!important`.
2. **Never hardcode px widths/positions.** Treat `width:370px`, `gap:207px`, `left:56.53%`,
   `line-height:16.94px`, `white-space:nowrap` as Figma artifacts — use fluid, responsive,
   token-based values (`w-full`/`flex-1`/`max-w-*`, spacing scale, relative line-heights). Keep only
   meaningful constraints (e.g. a max content width).
3. **Semantic HTML / framework components** — `<form>`, typed `<input>`, `<label>`, `<button>`,
   `<a>`, headings — not div/p soup.
4. **Map, don't copy, values** — colors → tokens, spacing → the scale, typography → text styles.

## Not in the export — you decide, per project conventions

A frame is one static state. Design these to match the project:
- **Responsive behavior / breakpoints** (how it stacks on mobile; side-image vs single column).
- **Interaction states** — hover, focus, active, disabled, error, checked, loading.
- **Semantics & a11y** — input `type`/`name`/`required`, validation, `<label for>`, `aria-*`, focus order.
- **Placeholder vs label vs value** — gray field text is ambiguous; pick per the project's form UX.
- **Real content & assets** — export copy may be placeholder, mixed-language, or have typos, and
  may not match the file name (e.g. `sign-in-4` shows sign-up fields). Use the project's real,
  i18n-keyed content; let the **feature spec**, not the export's copy, decide fields and labels.
