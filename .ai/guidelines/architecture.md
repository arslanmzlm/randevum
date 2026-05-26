# Architecture — modular monolith

- ONE Laravel monolith plus a separate Ionic-Vue mobile repo. No microservices, no extra backend repos.
- Organize backend code under `app/Modules/<Module>/` by business domain (Core, Identity, Scheduling, Medical, Catalog, Billing, Messaging, Media, Verticals), never by UI surface (clinic/admin/api). The module roster is provisional; the by-domain principle is not.
- Keep Eloquent models flat in `app/Models/` (Laravel convention) — a module "owns" a model through its Services/Repositories, not file location.
- Layering: Controller → FormRequest → Service → Repository → Model. Controllers stay thin; business logic in `Services/`, query logic in `Repositories/`.
- Module folders, sub-folders (`Contracts/ Services/ Repositories/ Events/ Listeners/ Jobs/ Policies/`) and the `<Module>ServiceProvider` are **lazy** — create with the first real file, never pre-scaffold. `"App\\": "app/"` autoload already resolves `App\Modules\*` (no extra PSR-4 entry). Register the ServiceProvider in `bootstrap/providers.php` (Laravel 11+, NOT `config/app.php`) only once it has something to register (binding, `Event::listen`, config/lang).
- Migrations live centrally in `database/migrations/` (global ordering; `make:migration`/`schema:dump`/`migrate:fresh` work out of the box). EXCEPTION: a Vertical keeps its own `database/migrations/` + `seeders/` via `loadMigrationsFrom` in its `<Vertical>ServiceProvider` (a vertical = one self-contained folder).
- A module's `Listeners/` only handle that module's own events.
- Cross-module communication is allowed ONLY via a Service interface (sync, when you need data back) or a Domain Event (async, fire-and-forget). Never query or import another module's Model, Repository, or concrete Service directly.
- Introduce an interface only at a REAL seam — a cross-module-callable service or a second implementation (e.g. `SmsProviderInterface`). Expose as `Contracts/<Service>Contract.php`, bind in the module's ServiceProvider. Don't add interfaces mechanically for module-internal services.
- `App\Modules\Core` is the shared kernel, importable by any module.
- No module extraction in MVP. Keep boundaries clean so extraction stays possible later, but don't build for it.
- Vue components live in `resources/js/` (never under `app/Modules/`). Vertical-specific components go under `resources/js/Verticals/<Vertical>/`, loaded via `defineAsyncComponent` keyed on `clinic.vertical.slug`. Patient SPA pages live under `resources/js/Pages/Patient/*`.
