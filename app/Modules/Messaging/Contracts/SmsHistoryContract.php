<?php

namespace App\Modules\Messaging\Contracts;

use App\Models\Patient;
use App\Models\SmsLog;
use Illuminate\Support\Collection;

/**
 * Read seam for fetching a patient's SMS history from outside the Messaging module.
 * Medical\PatientController depends on this abstraction — never on the concrete
 * SmsLogRepository — satisfying the cross-module boundary rule.
 */
interface SmsHistoryContract
{
    /**
     * The patient's most recent SMS logs, newest-first, capped at $limit rows.
     * ClinicScope is applied automatically via BelongsToClinic on SmsLog.
     *
     * @return Collection<int, SmsLog>
     */
    public function recentForPatient(Patient $patient, int $limit = 25): Collection;
}
