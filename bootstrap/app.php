<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetClinicContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/auth.php'));
            Route::middleware('web')->group(base_path('routes/identity.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));
            Route::middleware('web')->group(base_path('routes/clinic.php'));
            Route::middleware('web')->group(base_path('routes/billing.php'));
            Route::middleware('web')->group(base_path('routes/messaging.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetClinicContext must run before SubstituteBindings so ClinicScope is
        // active during route model binding (clinic A cannot see clinic B's records
        // via {doctor}, {patient}, etc.). Remove SubstituteBindings from its default
        // position and re-append it after SetClinicContext.
        $middleware->web(
            remove: SubstituteBindings::class,
            append: [
                SetClinicContext::class,
                SubstituteBindings::class,
                HandleInertiaRequests::class,
                AddLinkHeadersForPreloadedAssets::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render error statuses as a generic in-app Inertia page (instead of the
        // dev modal / bare HTTP page). JSON/API clients keep the default response.
        // 500/503 stay on the debug page in local so the stack trace survives.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();
            $renderable = in_array($status, [403, 404, 419], true)
                || (in_array($status, [500, 503], true) && ! config('app.debug'));

            if ($renderable) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
