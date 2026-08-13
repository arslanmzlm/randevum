<?php

namespace Tests\Support;

use Closure;
use ReflectionClass;
use ReflectionFunction;

/**
 * Reads the container's binding table WITHOUT resolving anything.
 *
 * A constructor-injection cycle makes the container recurse forever, so any check built on
 * top of this must stay pure reflection — resolving a service would hang the test process
 * instead of failing it.
 */
final class ContainerBindings
{
    /**
     * bind(Contract::class, Concrete::class) wraps the concrete in a closure that captures it
     * via `use`, so reflection recovers the class name without instantiating anything.
     *
     * @return array<string, string> abstract => concrete
     */
    public static function map(): array
    {
        $bindings = [];

        foreach (app()->getBindings() as $abstract => $binding) {
            $concrete = $binding['concrete'] ?? null;

            if ($concrete instanceof Closure) {
                $concrete = (new ReflectionFunction($concrete))->getStaticVariables()['concrete'] ?? null;
            }

            if (is_string($concrete) && (class_exists($concrete) || interface_exists($concrete))) {
                $bindings[ltrim((string) $abstract, '\\')] = ltrim($concrete, '\\');
            }
        }

        return $bindings;
    }

    /**
     * Follow the binding chain to an instantiable module class; anything else (scalars,
     * framework classes, contracts bound to a factory closure) is silently out of scope.
     *
     * @param  array<string, string>  $bindings
     */
    public static function concreteFor(string $type, array $bindings): ?string
    {
        $seen = [];

        while (isset($bindings[$type]) && $bindings[$type] !== $type) {
            if (isset($seen[$type])) {
                return null;
            }
            $seen[$type] = true;
            $type = $bindings[$type];
        }

        if (! str_starts_with($type, 'App\\Modules\\') || ! class_exists($type)) {
            return null;
        }

        return (new ReflectionClass($type))->isInstantiable() ? $type : null;
    }

    /**
     * `App\Modules\Scheduling\Services\AppointmentService` => `Scheduling`.
     */
    public static function moduleOf(string $class): ?string
    {
        if (! str_starts_with($class, 'App\\Modules\\')) {
            return null;
        }

        $segments = explode('\\', substr($class, strlen('App\\Modules\\')));

        return $segments[0] !== '' ? $segments[0] : null;
    }
}
