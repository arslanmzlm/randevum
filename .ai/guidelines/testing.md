# Testing — project-specific

(Generic Pest usage is covered by Boost; these are the Platform360-specific test requirements.)

- Pest architecture tests enforce module boundaries and run in CI as a merge blocker from MVP day one — a boundary violation must break the build. Cover at least: no module imports another module's `Repositories`/`Models`; cross-module imports go only through `Contracts/*` (never concrete `Services/*`); controllers never import `App\Modules\*\Repositories`; `Core` is importable everywhere.
- Multi-tenant isolation is mandatory test coverage on every CRUD feature: prove that tenant/clinic A cannot read or mutate tenant B's data.
