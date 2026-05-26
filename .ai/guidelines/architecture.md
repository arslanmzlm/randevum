# Architecture — modular monolith

- This is ONE Laravel monolith plus a separate Ionic-Vue mobile repo. No microservices, no extra backend repos.
- Organize backend code under `app/Modules/<Module>/` by business domain (Core, Identity, Scheduling, Medical, Catalog, Billing, Messaging, Media, Verticals), never by UI surface (clinic/admin/api). The exact module roster is provisional; the by-domain principle is not.
- Keep Eloquent models flat in `app/Models/` (Laravel convention). Do NOT move models into module folders — a module "owns" a model through its Services/Repositories, not through file location.
- Layering: Controller → FormRequest → Service → Repository → Model. Controllers stay thin; business logic lives in `Services/`, query logic in `Repositories/`.
- Module folders, their sub-folders (`Contracts/ Services/ Repositories/ Events/ Listeners/ Jobs/ Policies/`) and the `<Module>ServiceProvider` are **lazy** — create them with the first real file, never pre-scaffold empty dirs or empty providers. `"App\\": "app/"` autoload already resolves `App\Modules\*` (no extra PSR-4 entry needed). Register a module's ServiceProvider in `bootstrap/providers.php` (Laravel 11+, NOT `config/app.php`) and only once it actually has something to register (a Contract binding, `Event::listen`, config/lang).
- Migrations live centrally in Laravel's default `database/migrations/` (same reasoning as flat models: global ordering, `make:migration`/`schema:dump`/`migrate:fresh` work out of the box). EXCEPTION: a Vertical keeps its own `database/migrations/` + `seeders/` and registers them via `loadMigrationsFrom` in its `<Vertical>ServiceProvider` (a vertical = one self-contained folder).
- A module's `Listeners/` only handle that module's own events.
- Cross-module communication is allowed ONLY via a Service interface (sync, when you need data back) or a Domain Event (async, fire-and-forget). Never query or import another module's Model, Repository, or concrete Service directly.
- Introduce an interface only at a REAL seam — a cross-module-callable service or a second implementation (e.g. `SmsProviderInterface`). Expose it as `Contracts/<Service>Contract.php` and bind it in the module's ServiceProvider. Do NOT add interfaces mechanically for module-internal services.
- `App\Modules\Core` is the shared kernel and may be imported by any module.
- Do not perform module extraction in MVP. Keep boundaries clean so extraction stays possible later, but don't build for it.
- Vue components live in `resources/js/` (never under `app/Modules/`). Vertical-specific components go under `resources/js/Verticals/<Vertical>/`, loaded via `defineAsyncComponent` keyed on `clinic.vertical.slug`. Patient SPA pages live under `resources/js/Pages/Patient/*`.
