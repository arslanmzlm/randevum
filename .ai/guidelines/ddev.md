# Runtime — DDEV

This project runs inside DDEV. Run ALL PHP/Node commands through `ddev`, never bare on the host:

- `ddev php artisan ...`, `ddev composer ...`, `ddev npm ...`
- Lint/format/types/test: `ddev composer lint` (Pint), `ddev npm run lint`, `ddev npm run format`, `ddev npm run types:check`, `ddev php artisan test`
- This overrides any Boost guideline that calls `php artisan` or `vendor/bin/pint` directly — always prefix with `ddev`.
