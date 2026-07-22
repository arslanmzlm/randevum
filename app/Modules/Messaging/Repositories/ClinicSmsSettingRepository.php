<?php

namespace App\Modules\Messaging\Repositories;

use App\Enums\SmsType;
use App\Models\ClinicSmsSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClinicSmsSettingRepository
{
    /**
     * Returns the enabled state for a single SMS type for the given clinic.
     * A missing row means the default: enabled (ON).
     */
    public function isEnabled(int $clinicId, SmsType $type): bool
    {
        $row = ClinicSmsSetting::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('sms_type', $type->value)
            ->first();

        return $row === null || $row->enabled;
    }

    /**
     * The clinic's custom body for a single type, or null when it falls back to
     * the lang default (no row, or the row's template is null/empty).
     */
    public function templateFor(int $clinicId, SmsType $type): ?string
    {
        $row = ClinicSmsSetting::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('sms_type', $type->value)
            ->first();

        return $row?->template;
    }

    /**
     * Resolves the full preference map for a clinic.
     * Every clinic-scoped SmsType is present; missing rows default to true.
     *
     * @return array<string, bool>
     */
    public function allForClinic(int $clinicId): array
    {
        $rows = $this->rowsForClinic($clinicId);

        $result = [];

        foreach (SmsType::clinicScopedCases() as $type) {
            $result[$type->value] = isset($rows[$type->value]) ? (bool) $rows[$type->value]->enabled : true;
        }

        return $result;
    }

    /**
     * Resolves the custom template map for a clinic's customizable SmsTypes.
     * Every customizable type is present; missing/null rows resolve to null
     * (falls back to the lang default).
     *
     * @return array<string, ?string>
     */
    public function templatesForClinic(int $clinicId): array
    {
        $rows = $this->rowsForClinic($clinicId);

        $result = [];

        foreach (SmsType::customizableCases() as $type) {
            $result[$type->value] = $rows[$type->value]->template ?? null;
        }

        return $result;
    }

    /**
     * Upserts one row per clinic-scoped SmsType from the given maps.
     * Unknown / non-clinic-scoped keys in $enabledByType are ignored; a type
     * missing from $enabledByType is skipped entirely (row untouched).
     * $templatesByType keys are only honoured for customizable types.
     *
     * @param  array<string, bool>  $enabledByType  keyed by SmsType value
     * @param  array<string, ?string>  $templatesByType  keyed by SmsType value
     */
    public function upsertForClinic(int $clinicId, array $enabledByType, array $templatesByType = []): void
    {
        $now = now();
        $withTemplate = [];   // template key sent → update enabled + template
        $enabledOnly = [];    // template key absent → update enabled only, leave stored template

        foreach (SmsType::clinicScopedCases() as $type) {
            if (! array_key_exists($type->value, $enabledByType)) {
                continue;
            }

            $base = [
                'clinic_id' => $clinicId,
                'sms_type' => $type->value,
                'enabled' => $enabledByType[$type->value] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // A template column is only touched for a customizable type whose key the
            // caller actually sent. An absent key means "leave the stored template
            // unchanged" (a reset is an explicitly-present null/empty value); folding
            // absent into null would silently wipe a clinic's custom text on a partial PUT.
            if ($type->isCustomizable() && array_key_exists($type->value, $templatesByType)) {
                $withTemplate[] = $base + ['template' => $templatesByType[$type->value]];
            } else {
                $enabledOnly[] = $base + ['template' => null];
            }
        }

        if ($withTemplate !== []) {
            DB::table('clinic_sms_settings')->upsert(
                $withTemplate,
                ['clinic_id', 'sms_type'],
                ['enabled', 'template', 'updated_at'],
            );
        }

        if ($enabledOnly !== []) {
            DB::table('clinic_sms_settings')->upsert(
                $enabledOnly,
                ['clinic_id', 'sms_type'],
                ['enabled', 'updated_at'], // template deliberately excluded → preserved on conflict
            );
        }
    }

    /**
     * @return Collection<string, ClinicSmsSetting>
     */
    private function rowsForClinic(int $clinicId): Collection
    {
        return ClinicSmsSetting::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->get()
            ->keyBy(fn (ClinicSmsSetting $r) => $r->sms_type->value);
    }
}
