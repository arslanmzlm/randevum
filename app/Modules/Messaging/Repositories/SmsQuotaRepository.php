<?php

namespace App\Modules\Messaging\Repositories;

use App\Enums\SmsStatus;
use App\Models\SmsLog;
use Carbon\CarbonInterface;

class SmsQuotaRepository
{
    /**
     * Count sms_logs rows that consumed quota for a clinic within a UTC month window.
     *
     * Only Queued / Sent / Failed rows count — Skipped is excluded (disabled, no-phone,
     * and quota-exceeded rows never burn allowance).
     */
    public function countConsumedInMonth(
        int $clinicId,
        CarbonInterface $monthStartUtc,
        CarbonInterface $monthEndUtc,
    ): int {
        return SmsLog::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->whereIn('status', [
                SmsStatus::Queued->value,
                SmsStatus::Sent->value,
                SmsStatus::Failed->value,
            ])
            ->whereBetween('created_at', [$monthStartUtc, $monthEndUtc])
            ->count();
    }
}
