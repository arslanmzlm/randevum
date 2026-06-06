<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Scheduling\Services\AppointmentTypeService;

/**
 * Provisions the vertical's default appointment types for a newly registered clinic.
 * Runs synchronously (provisioning must complete before the user is redirected).
 * Cross-module communication via domain event — no direct import of Identity internals.
 */
class ProvisionDefaultAppointmentTypes
{
    public function __construct(private AppointmentTypeService $appointmentTypeService) {}

    public function handle(ClinicRegistered $event): void
    {
        $clinic = $event->clinic;
        $slug = $clinic->vertical?->slug;

        if ($slug === null) {
            return;
        }

        /** @var array{name: string, color: string, default_duration_minutes: int}[] $defaults */
        $defaults = config("{$slug}.appointment_types", []);

        if (empty($defaults)) {
            return;
        }

        $this->appointmentTypeService->provisionDefaults($clinic, $defaults);
    }
}
