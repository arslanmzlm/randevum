# Architecture — modular monolith

- This is ONE Laravel monolith plus a separate Ionic-Vue mobile repo. No microservices, no extra backend repos.
- Organize backend code under `app/Modules/<Module>/` by business domain (Core, Identity, Scheduling, Medical, Catalog, Billing, Messaging, Media, Verticals), never by UI surface (clinic/admin/api). The exact module roster is provisional; the by-domain principle is not.
- Keep Eloquent models flat in `app/Models/` (Laravel convention). Do NOT move models into module folders — a module "owns" a model through its Services/Repositories, not through file location.
- Layering: Controller → FormRequest → Service → Repository → Model. Controllers stay thin; business logic lives in `Services/`, query logic in `Repositories/`.
- Each module uses the fixed sub-folders `Contracts/ Services/ Repositories/ Events/ Listeners/ Jobs/ Policies/ database/migrations/`, and registers its own migrations/config/lang in its `<Module>ServiceProvider`.
- A module's `Listeners/` only handle that module's own events.
- Cross-module communication is allowed ONLY via a Service interface (sync, when you need data back) or a Domain Event (async, fire-and-forget). Never query or import another module's Model, Repository, or concrete Service directly.
- Expose a cross-module-callable service through `Contracts/<Service>Contract.php` and bind it in the module's ServiceProvider. Module-internal services need no interface.
- `App\Modules\Core` is the shared kernel and may be imported by any module.
- Do not perform module extraction in MVP. Keep boundaries clean so extraction stays possible later, but don't build for it.
- Vue components live in `resources/js/` (never under `app/Modules/`). Vertical-specific components go under `resources/js/Verticals/<Vertical>/`, loaded via `defineAsyncComponent` keyed on `clinic.vertical.slug`. Patient SPA pages live under `resources/js/Pages/Patient/*`.
