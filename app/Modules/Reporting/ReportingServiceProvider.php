<?php

namespace App\Modules\Reporting;

use Illuminate\Support\ServiceProvider;

/**
 * Reporting has no cross-module contract to bind yet — the module is read-only and
 * resolves its own services by concrete type. The provider exists as the module's
 * registration seam for when it does.
 */
class ReportingServiceProvider extends ServiceProvider
{
    //
}
