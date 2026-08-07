<?php

namespace App\Modules\Medical\Listeners;

use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Medical\Services\FollowUpTypeService;

/**
 * Provisions the platform's default follow-up types for a newly registered clinic.
 * Runs synchronously (provisioning must complete before the user is redirected).
 * Cross-module communication via domain event — no direct import of Identity internals.
 */
class ProvisionDefaultFollowUpTypes
{
    public function __construct(private FollowUpTypeService $followUpTypeService) {}

    public function handle(ClinicRegistered $event): void
    {
        $this->followUpTypeService->provisionDefaults(
            $event->clinic,
            config('platform.follow_ups.default_types', []),
        );
    }
}
