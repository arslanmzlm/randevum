<?php

namespace App\Modules\Messaging\Services;

use App\Models\Clinic;
use App\Modules\Messaging\Contracts\SmsQuotaContract;
use App\Modules\Messaging\Repositories\SmsQuotaRepository;
use Carbon\Carbon;

class SmsQuotaService implements SmsQuotaContract
{
    public function __construct(
        private SmsQuotaRepository $repository,
    ) {}

    /**
     * Effective monthly allowance for the clinic — override when set, else the platform default.
     */
    public function monthlyAllowance(int $clinicId): int
    {
        $override = Clinic::withoutGlobalScopes()
            ->where('id', $clinicId)
            ->value('sms_monthly_quota');

        return $override ?? (int) config('platform.sms.monthly_quota');
    }

    /**
     * Number of clinic-scoped SMS consumed in the current calendar month (clinic timezone).
     */
    public function usedThisMonth(int $clinicId): int
    {
        [$monthStartUtc, $monthEndUtc] = $this->currentMonthRangeUtc($clinicId);

        return $this->repository->countConsumedInMonth($clinicId, $monthStartUtc, $monthEndUtc);
    }

    /**
     * Whether the clinic has capacity to dispatch one more SMS this month.
     */
    public function hasRoom(int $clinicId): bool
    {
        return $this->usedThisMonth($clinicId) < $this->monthlyAllowance($clinicId);
    }

    /**
     * Usage summary for the SMS settings page panel.
     *
     * resets_at is the next calendar-month start as a clinic-local ISO wall-clock string
     * so the frontend can format it via useDateTime() without any timezone math.
     *
     * @return array{used: int, allowance: int, remaining: int, resets_at: string}
     */
    public function usage(int $clinicId): array
    {
        $allowance = $this->monthlyAllowance($clinicId);
        $used = $this->usedThisMonth($clinicId);
        $remaining = max(0, $allowance - $used);

        $timezone = Clinic::withoutGlobalScopes()
            ->where('id', $clinicId)
            ->value('timezone') ?? 'UTC';

        $resetsAt = Carbon::now($timezone)->startOfMonth()->addMonth()->toIso8601String();

        return [
            'used' => $used,
            'allowance' => $allowance,
            'remaining' => $remaining,
            'resets_at' => $resetsAt,
        ];
    }

    /**
     * Returns [monthStartUtc, monthEndExclusiveUtc] for the current calendar month
     * anchored to the clinic's own timezone.
     *
     * @return array{Carbon, Carbon}
     */
    private function currentMonthRangeUtc(int $clinicId): array
    {
        $timezone = Clinic::withoutGlobalScopes()
            ->where('id', $clinicId)
            ->value('timezone') ?? 'UTC';

        $now = Carbon::now($timezone);
        $monthStart = $now->copy()->startOfMonth()->utc();
        $monthEnd = $now->copy()->startOfMonth()->addMonth()->utc();

        return [$monthStart, $monthEnd];
    }
}
