<?php

namespace App\Modules\Messaging\Repositories;

use App\Enums\SmsType;
use App\Models\ClinicSmsSetting;
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
     * Resolves the full preference map for a clinic.
     * Every clinic-scoped SmsType is present; missing rows default to true.
     *
     * @return array<string, bool>
     */
    public function allForClinic(int $clinicId): array
    {
        $rows = ClinicSmsSetting::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->get()
            ->keyBy(fn (ClinicSmsSetting $r) => $r->sms_type->value);

        $result = [];

        foreach (SmsType::clinicScopedCases() as $type) {
            $result[$type->value] = isset($rows[$type->value]) ? (bool) $rows[$type->value]->enabled : true;
        }

        return $result;
    }

    /**
     * Upserts one row per clinic-scoped SmsType from the given map.
     * Unknown / non-clinic-scoped keys are ignored.
     *
     * @param  array<string, bool>  $enabledByType  keyed by SmsType value
     */
    public function upsertForClinic(int $clinicId, array $enabledByType): void
    {
        $rows = [];
        $now = now();

        foreach (SmsType::clinicScopedCases() as $type) {
            if (! array_key_exists($type->value, $enabledByType)) {
                continue;
            }

            $rows[] = [
                'clinic_id' => $clinicId,
                'sms_type' => $type->value,
                'enabled' => $enabledByType[$type->value] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        DB::table('clinic_sms_settings')->upsert(
            $rows,
            ['clinic_id', 'sms_type'],
            ['enabled', 'updated_at'],
        );
    }
}
