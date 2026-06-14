<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Repositories\ClinicSmsSettingRepository;

class ClinicSmsSettingService
{
    public function __construct(
        private ClinicSmsSettingRepository $repository,
    ) {}

    /**
     * Full preference map for the edit page.
     * Every clinic-scoped SmsType is present; missing rows default to true (ON).
     *
     * @return array<string, bool>
     */
    public function current(int $clinicId): array
    {
        return $this->repository->allForClinic($clinicId);
    }

    /**
     * Persist the submitted preferences for a clinic.
     *
     * @param  array<string, bool>  $enabledByType  keyed by SmsType value
     */
    public function update(int $clinicId, array $enabledByType): void
    {
        $this->repository->upsertForClinic($clinicId, $enabledByType);
    }
}
