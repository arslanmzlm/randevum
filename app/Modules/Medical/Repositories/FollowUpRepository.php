<?php

namespace App\Modules\Medical\Repositories;

use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Collection;

class FollowUpRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FollowUp
    {
        return FollowUp::create($data);
    }

    /**
     * Open follow-ups due on or before $todayDate for the active clinic, oldest-due first.
     * ClinicScope (via BelongsToClinic) isolates the tenant.
     *
     * @return Collection<int, FollowUp>
     */
    public function dueForActiveClinic(string $todayDate): Collection
    {
        return FollowUp::open()
            ->whereDate('due_date', '<=', $todayDate)
            ->with([
                'patient:id,first_name,last_name,phone',
                'type:id,name',
                'caseRecord.doctor.user',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * A case's follow-ups for the case-detail panel: open rows first (due_date asc), then
     * the rest (done/cancelled) newest-completed-first.
     *
     * @return Collection<int, FollowUp>
     */
    public function forCase(int $caseId): Collection
    {
        return FollowUp::where('case_id', $caseId)
            ->with(['type:id,name', 'completedBy'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->orderByDesc('completed_at')
            ->get();
    }
}
