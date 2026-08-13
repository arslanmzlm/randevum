<?php

use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Core\Contracts\AppointmentCancellationContract;
use App\Modules\Core\Contracts\AppointmentLifecycleContract;
use App\Modules\Core\Contracts\DashboardStatsContract;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\DashboardStatsService;
use Tests\Support\ContainerBindings;

/*
 * Bindings still fulfilled by an orchestration service instead of a thin seam class.
 * Every line here is DEBT waiting to be split, not a pattern to copy — adding one is a
 * deliberate decision that has to name why the seam cannot be a thin reader today.
 *
 * @var array<string, string> abstract => concrete
 */
const SEAM_ALLOW_LIST = [
    // Cancelling writes status logs and fires the status SMS — the write path lives in the
    // orchestration service, so the seam cannot be a thin reader.
    AppointmentCancellationContract::class => AppointmentService::class,
    // Command seam: lifecycle transitions run the full booking rules (availability, locks).
    AppointmentLifecycleContract::class => AppointmentService::class,
    // Read seam over Scheduling + Billing; the daily-revenue call is what makes it cross-module.
    DashboardStatsContract::class => DashboardStatsService::class,
    // Command seam: recording a payment needs the linked treatment's total to enforce the
    // overpayment cap, so the write path reads across the Medical boundary.
    PaymentRecorderContract::class => PaymentService::class,
];

/*
 * A constructor-injection cycle between module services (A injects B's contract, B injects
 * A's) makes the container recurse forever. Laravel does not detect it: nothing throws, no
 * test goes red — the process just hangs with no output. So this check stays PURE reflection
 * plus a read of the registered bindings; it must never resolve a service out of the
 * container, or a real cycle would hang this test too.
 */

test('module services have no constructor dependency cycle', function (): void {
    $bindings = ContainerBindings::map();

    /** @var array<string, array<string, string>> $graph class => [dependency class => edge label] */
    $graph = [];

    $addNode = function (string $class) use (&$graph, &$addNode, $bindings): void {
        if (isset($graph[$class])) {
            return;
        }

        $graph[$class] = [];

        $constructor = (new ReflectionClass($class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $declared = $type->getName();
            $target = ContainerBindings::concreteFor($declared, $bindings);

            if ($target === null) {
                continue;
            }

            $graph[$class][$target] ??= $declared === $target
                ? class_basename($target)
                : class_basename($declared).' ('.class_basename($target).')';

            $addNode($target);
        }
    };

    foreach (glob(app_path('Modules/*/Services/*.php')) ?: [] as $file) {
        $class = 'App\\Modules\\'.str_replace('/', '\\', substr($file, strlen(app_path('Modules/')), -4));

        if (class_exists($class) && (new ReflectionClass($class))->isInstantiable()) {
            $addNode($class);
        }
    }

    expect($graph)->not->toBeEmpty('no module services were scanned — the discovery glob is broken');

    $state = [];
    $path = [];
    $labels = [];
    $cycle = null;

    $visit = function (string $class) use (&$visit, &$state, &$path, &$labels, &$cycle, $graph): void {
        $state[$class] = 'open';
        $path[] = $class;

        foreach ($graph[$class] ?? [] as $target => $label) {
            if (($state[$target] ?? null) === 'open') {
                $start = (int) array_search($target, $path, true);
                $cycle = class_basename($path[$start]);

                for ($i = $start + 1; $i < count($path); $i++) {
                    $cycle .= ' -> '.$labels[$i];
                }

                $cycle .= ' -> '.$label;

                return;
            }

            if (! isset($state[$target])) {
                $labels[count($path)] = $label;
                $visit($target);

                if ($cycle !== null) {
                    return;
                }
            }
        }

        array_pop($path);
        unset($labels[count($path)]);
        $state[$class] = 'done';
    };

    foreach (array_keys($graph) as $class) {
        if (! isset($state[$class])) {
            $visit($class);
        }

        if ($cycle !== null) {
            break;
        }
    }

    if ($cycle !== null) {
        $this->fail("Container dependency cycle in module services: {$cycle}");
    }
});

/*
 * The structural guarantee behind the cycle check: if the class fulfilling a cross-module
 * contract injects no OTHER module's contract, every cross-module edge ends in a repository,
 * and a node without an outgoing edge cannot sit on a cycle. Core is the shared kernel, so
 * depending on a Core-implemented contract is always allowed.
 */

test('a class fulfilling a module contract injects no other module contract', function (): void {
    $bindings = ContainerBindings::map();

    $checked = 0;
    $violations = [];

    foreach (array_keys($bindings) as $abstract) {
        if (! str_starts_with($abstract, 'App\\Modules\\') || ! interface_exists($abstract)) {
            continue;
        }

        $concrete = ContainerBindings::concreteFor($abstract, $bindings);

        if ($concrete === null) {
            continue;
        }

        $checked++;

        $module = ContainerBindings::moduleOf($concrete);
        $exempt = (SEAM_ALLOW_LIST[$abstract] ?? null) === $concrete;

        foreach ((new ReflectionClass($concrete))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $declared = $type->getName();

            if (! interface_exists($declared) || ! str_starts_with($declared, 'App\\Modules\\')) {
                continue;
            }

            $target = ContainerBindings::concreteFor($declared, $bindings);
            $owner = ContainerBindings::moduleOf($target ?? $declared);

            if ($owner === null || $owner === $module || $owner === 'Core') {
                continue;
            }

            if ($exempt) {
                continue;
            }

            $violations[] = sprintf(
                '%s fulfils %s but injects %s (owned by %s) — split this seam into a thin '
                .'%s-only class that depends on repositories, or add the binding to '
                .'SEAM_ALLOW_LIST at the top of this file with its reason.',
                class_basename($concrete),
                class_basename($abstract),
                class_basename($declared),
                $owner,
                (string) $module,
            );
        }
    }

    expect($checked)->toBeGreaterThan(0, 'no module contract bindings were read — the binding scan is broken');

    if ($violations !== []) {
        $this->fail(implode("\n", $violations));
    }
});
