<?php

namespace App\Modules\Messaging\Contracts;

interface SmsQuotaContract
{
    /**
     * Whether the clinic can dispatch one more clinic-scoped SMS this calendar month.
     * OTP / platform sends (clinicId === null) always bypass quota — callers never
     * need to check quota for those paths.
     */
    public function hasRoom(int $clinicId): bool;

    /**
     * Usage summary for the SMS settings quota panel.
     *
     * resets_at is the next calendar-month start as a clinic-local ISO wall-clock string.
     *
     * @return array{used: int, allowance: int, remaining: int, resets_at: string}
     */
    public function usage(int $clinicId): array;
}
