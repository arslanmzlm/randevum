<?php

namespace App\Modules\Identity\Events;

use App\Models\Clinic;

/**
 * Dispatched by ClinicRegistrationService after the tenant+clinic+owner transaction commits.
 * Consumed by cross-module listeners (e.g. Scheduling) via the domain event seam.
 */
class ClinicRegistered
{
    public function __construct(public readonly Clinic $clinic) {}
}
