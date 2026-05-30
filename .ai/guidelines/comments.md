# Code comments

Comment only what is **critical or non-obvious**; never restate what the code plainly does.

- Write a comment for the *why* behind a surprising choice, a gotcha, a workaround, or a constraint — not for the *what*. Good: "structuredClone throws on Inertia's reactive proxy → JSON clone". Bad: "Copy Monday's hours to every day" above `copyMondayToAll()`.
- No section-label / banner comments (`// ---- Helpers ----`, ASCII boxes) and no docblocks that merely echo the method name or signature.
- Prefer a single-line `//` note over a multi-line docblock. Keep docblocks only for real contracts that tooling needs — `@param`/`@return` with type info, `@mixin`, `@var` array shapes.
- This **overrides** the Boost "prefer PHPDoc blocks over inline comments" default: here, fewer/leaner comments win.
- Applies to everyone — direct edits and every pipeline phase agent.
