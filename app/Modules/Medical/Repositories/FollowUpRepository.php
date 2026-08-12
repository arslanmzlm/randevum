<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\FollowUpStatus;
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
        // Two queries instead of one status-CASE ORDER BY: `NULLS LAST` isn't portable
        // (Postgres-only), and the open/closed groups need unrelated sort keys anyway.
        $open = FollowUp::where('case_id', $caseId)
            ->where('status', FollowUpStatus::Open->value)
            ->with(['type:id,name', 'completedBy'])
            ->orderBy('due_date')
            ->get();

        $closed = FollowUp::where('case_id', $caseId)
            ->where('status', '!=', FollowUpStatus::Open->value)
            ->with(['type:id,name', 'completedBy'])
            ->orderByDesc('completed_at')
            ->get();

        return $open->concat($closed);
    }
}
