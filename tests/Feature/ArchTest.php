<?php

use Illuminate\Database\Eloquent\Scope;
use Tests\Support\ContainerBindings;

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

/** Concrete (non-Contracts) namespaces of every module except the named ones. */
$internalNamespaces = static function (array $modules, string ...$except): array {
    $namespaces = [];

    foreach ($modules as $module) {
        if (in_array($module, $except, true)) {
            continue;
        }

        foreach (glob(dirname(__DIR__, 2)."/app/Modules/{$module}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== 'Contracts') {
                $namespaces[] = "App\\Modules\\{$module}\\".basename($dir);
            }
        }
    }

    return $namespaces;
};

// Core is the shared kernel — any module may import it. Core may only reach back through a
// sibling's Contracts/* (the seam abstraction), never its concrete namespaces.
$siblingInternals = $internalNamespaces($modules, 'Core');

if (in_array('Core', $modules, true) && $siblingInternals !== []) {
    arch('module Core does not depend on sibling module internals')
        ->expect('App\Modules\Core')
        ->not->toUse($siblingInternals);
}

// Reporting is the boundary rule's one exception: its repositories may query and join
// another module's TABLES for analytic aggregates. That exception lives entirely at the
// model/table layer, which (see the note above) no arch rule can express — so the CODE
// boundary is pinned here at full strength: Core plus sibling Contracts/*, nothing else.
$reportingForbidden = $internalNamespaces($modules, 'Core', 'Reporting');

if (in_array('Reporting', $modules, true) && $reportingForbidden !== []) {
    arch('module Reporting joins tables, not sibling module code')
        ->expect('App\Modules\Reporting')
        ->not->toUse($reportingForbidden);
}

/*
 * Owner decision: a cross-module contract lives in the module that FULFILS it, so a seam is
 * discoverable from its implementation. Reads the binding table without resolving anything —
 * resolving here would hang on a dependency cycle instead of failing (see ContainerCycleTest).
 */

test('a module contract is fulfilled by a class in its own module', function (): void {
    $bindings = ContainerBindings::map();

    $checked = 0;
    $violations = [];

    foreach ($bindings as $abstract => $ignored) {
        if (! str_starts_with($abstract, 'App\\Modules\\') || ! interface_exists($abstract)) {
            continue;
        }

        $concrete = ContainerBindings::concreteFor($abstract, $bindings);

        if ($concrete === null) {
            continue;
        }

        $checked++;

        $owner = ContainerBindings::moduleOf($abstract);
        $implementor = ContainerBindings::moduleOf($concrete);

        if ($owner === $implementor) {
            continue;
        }

        $violations[] = sprintf(
            '%s lives in %s but is fulfilled by %s (%s) — move the contract to '
            .'app/Modules/%s/Contracts/ and update its namespace, imports and binding.',
            class_basename($abstract),
            (string) $owner,
            class_basename($concrete),
            (string) $implementor,
            (string) $implementor,
        );
    }

    expect($checked)->toBeGreaterThan(0, 'no module contract bindings were read — the binding scan is broken');

    if ($violations !== []) {
        $this->fail(implode("\n", $violations));
    }
});
