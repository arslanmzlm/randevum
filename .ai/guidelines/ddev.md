# Runtime — DDEV

This project runs inside DDEV. Run ALL PHP/Node commands through `ddev`, never bare on the host:

- `ddev php artisan ...`, `ddev composer ...`, `ddev pnpm ...` (Node package manager is pnpm via corepack — never npm/yarn).
- Lint/format/types/test: `ddev composer lint` (Pint), `ddev pnpm run lint`, `ddev pnpm run format`, `ddev pnpm run types:check`, `ddev php artisan test --parallel` (serial run only when debugging a single test)
- This overrides any Boost guideline that calls `php artisan` or `vendor/bin/pint` directly — always prefix with `ddev`.
