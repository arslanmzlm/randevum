<?php

namespace App\Modules\Messaging\Services;

use App\Models\Patient;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsHistoryContract;
use App\Modules\Messaging\Repositories\SmsLogRepository;
use App\Support\ClinicContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SmsLogService implements SmsHistoryContract
{
    public function __construct(
        private SmsLogRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return LengthAwarePaginator<SmsLog>
     */
    public function listForActiveClinic(): LengthAwarePaginator
    {
        $clinic = $this->clinicContext->clinicOrFail();

        return $this->repository->paginateForActiveClinic($clinic->timezone);
    }

    /**
     * @return Collection<int, SmsLog>
     */
    public function recentForPatient(Patient $patient, int $limit = 25): Collection
    {
        return $this->repository->recentForPatient($patient, $limit);
    }
}
