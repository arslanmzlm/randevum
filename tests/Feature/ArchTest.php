<?php

use Illuminate\Database\Eloquent\Scope;

/*
|--------------------------------------------------------------------------
| Code hygiene & structure (enforced now)
|--------------------------------------------------------------------------
*/

arch('debugging helpers are not left in the codebase')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('controllers are suffixed Controller')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('models are classes')
    ->expect('App\Models')
    ->toBeClasses()
    ->ignoring('App\Models\Concerns');

arch('enums are backed enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('global scopes implement the Scope contract')
    ->expect('App\Scopes')
    ->toImplement(Scope::class);

/*
|--------------------------------------------------------------------------
| Module boundaries
|--------------------------------------------------------------------------
| app/Modules/* are lazy (born with the first real file), so these are inert
| today and auto-enforce per module as soon as one exists — a boundary
| violation breaks the build from that point on (CI merge blocker).
|
| NOTE: Eloquent models live flat in app/Models (a module owns a model by
| convention, not by namespace), so "no module imports another module's
| Model" is NOT expressible as an arch rule and is deliberately omitted.
*/

// Resolve from __DIR__ (not app_path()) — this runs at collection time, before the app boots.
$modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    $self = "App\\Modules\\{$module}";

    // Repositories are module-internal: no other module — and no controller — reaches in.
    arch("module {$module}: repositories stay internal")
        ->expect("{$self}\\Repositories")
        ->toOnlyBeUsedIn($self);

    // Core is the shared kernel — its Services are importable by any module.
    // Only non-Core modules enforce service privacy.
    if ($module === 'Core') {
        continue;
    }

    // Concrete services are module-internal: cross-module calls go through Contracts/* only.
    arch("module {$module}: services stay internal")
        ->expect("{$self}\\Services")
        ->toOnlyBeUsedIn($self);
}

// Core is the shared kernel — any module may import it, but it must not depend on sibling modules.
$siblingModules = array_values(array_filter(
    array_map(fn (string $m): string => "App\\Modules\\{$m}", $modules),
    fn (string $ns): bool => $ns !== 'App\\Modules\\Core',
));

if (in_array('Core', $modules, true) && $siblingModules !== []) {
    arch('module Core does not depend on sibling modules')
        ->expect('App\Modules\Core')
        ->not->toUse($siblingModules);
}
