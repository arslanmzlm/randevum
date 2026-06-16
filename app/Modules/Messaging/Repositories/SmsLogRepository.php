<?php

namespace App\Modules\Messaging\Repositories;

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Support\FilterHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SmsLogRepository
{
    /**
     * Server-side paginated list of SMS logs for the active clinic, newest-first,
     * with search/filter support via FilterHelper.
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     * Search targets the snapshotted phone column on sms_logs directly.
     *
     * @return LengthAwarePaginator<SmsLog>
     */
    public function paginateForActiveClinic(string $timezone): LengthAwarePaginator
    {
        $query = SmsLog::query()->with('patient')->orderByDesc('created_at');

        return FilterHelper::for($query)
            ->search('phone')
            ->enumMultiple(['status' => SmsStatus::class, 'type' => SmsType::class])
            ->dateRange('created_at', 'start_date', 'end_date', $timezone)
            ->paginate();
    }

    /**
     * A patient's most recent SMS logs, newest-first, capped at $limit.
     * ClinicScope is applied automatically.
     *
     * @return Collection<int, SmsLog>
     */
    public function recentForPatient(Patient $patient, int $limit = 25): Collection
    {
        return SmsLog::where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
