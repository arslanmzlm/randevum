<?php

namespace App\Modules\Messaging\Services;

use App\Enums\SmsType;
use App\Models\Clinic;
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
     * Per-type custom template map for the edit page.
     * Every customizable SmsType is present; a null value means "no custom
     * template" (falls back to the lang default).
     *
     * @return array<string, ?string>
     */
    public function templates(int $clinicId): array
    {
        return $this->repository->templatesForClinic($clinicId);
    }

    /**
     * The lang default body for every customizable SmsType, in the given
     * locale — the reset-to-default / preview baseline for the edit page.
     *
     * @return array<string, string>
     */
    public function defaults(string $locale): array
    {
        $lang = strtolower(explode('_', $locale)[0]);

        $result = [];

        foreach (SmsType::customizableCases() as $type) {
            $result[$type->value] = __($type->defaultBodyKey(), [], $lang);
        }

        return $result;
    }

    /**
     * Static illustrative preview values per allowlisted variable, in the
     * clinic's own locale — no DB lookup of a real patient/appointment.
     *
     * @return array<string, string>
     */
    public function sample(Clinic $clinic): array
    {
        $lang = strtolower(explode('_', $clinic->locale)[0]);

        return [
            'clinic' => $clinic->name,
            'date' => __('sms_settings.preview_sample.date', [], $lang),
            'time' => __('sms_settings.preview_sample.time', [], $lang),
            'patient' => __('sms_settings.preview_sample.patient', [], $lang),
            'doctor' => __('sms_settings.preview_sample.doctor', [], $lang),
        ];
    }

    /**
     * Persist the submitted preferences + templates for a clinic. An empty
     * string in $templatesByType has already been normalized to null by the
     * FormRequest accessor (reset to lang default).
     *
     * @param  array<string, bool>  $enabledByType  keyed by SmsType value
     * @param  array<string, ?string>  $templatesByType  keyed by SmsType value
     */
    public function update(int $clinicId, array $enabledByType, array $templatesByType): void
    {
        $this->repository->upsertForClinic($clinicId, $enabledByType, $templatesByType);
    }
}
