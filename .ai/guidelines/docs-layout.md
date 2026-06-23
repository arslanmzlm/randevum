# Docs & repo layout

- `.localdev/` (project root) is a SEPARATE nested git repo for local-only working files: `docs/` (design docs — data-model, features, architecture, …), `dashboard/` (Figma exports), `pipeline/` (driver, INDEX, runs), `qa/`, `samples/`. The PROJECT repo ignores it via `.git/info/exclude` — it never appears in the project's `git status` and is never committed to the project. Version it on its own: `cd .localdev && git …`. Never try to commit `.localdev/` into the project repo.
- `.ai/` holds ONLY the tracked project source: `guidelines/`, `agents/`, `skills/`.
- Three documentation tiers:
  - **Cross-cutting invariant RULES** → `.ai/guidelines/*` — merged into CLAUDE.md, loaded EVERY turn for EVERY agent. Keep TERSE: imperative rules only (what to do), no rationale/examples/schemas.
  - **Per-domain business rules** → the `domain-rules` skill (`.ai/skills/domain-rules/rules/<domain>.md`), loaded on demand.
  - **Rationale, examples, full table schemas, "why"/Faz-asides** → `.localdev/docs/*` — NOT loaded into context; only the pipeline PLANNER reads them.
- Execution-phase pipeline agents (backend/frontend/tester/verifier/reviewer) read ONLY CLAUDE.md, never `.localdev/docs/`. So NEVER relocate an actionable rule into docs — a condensation pass may move only rationale/examples/why. Every imperative rule stays inline in the guideline, however terse.
- When adding/changing a rule: write ONE terse line in the guideline; put any detail in the matching `.localdev/docs/` file. Don't nibble bytes to fit the CLAUDE.md size cap — if guidelines approach it, do a deliberate condensation pass (rationale → docs) or promote domain rules to the on-demand skill.
