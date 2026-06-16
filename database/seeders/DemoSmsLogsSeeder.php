<?php

namespace Database\Seeders;

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SmsLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo SMS logs for the demo clinic so the /sms-logs list and the patient-detail
 * communication-history section have data to review. Covers every SmsStatus
 * (sent / queued / failed / skipped), every clinic-scoped SmsType, named-patient
 * and raw-phone recipients, and failure/skip reasons — spread over the past two
 * weeks so the date-range filter and newest-first ordering are visible.
 *
 * OTP rows are intentionally omitted: they are platform-level (clinic_id null) and
 * never appear in a clinic's list.
 *
 * Runs AFTER DemoSeeder (needs the demo clinic + patients).
 */
class DemoSmsLogsSeeder extends Seeder
{
    private Clinic $clinic;

    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();

        if (! $clinic) {
            return;
        }

        // Idempotent: skip once the demo set exists so re-seeds don't pile up.
        if (SmsLog::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count() >= 20) {
            return;
        }

        $this->clinic = $clinic;

        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->take(12)
            ->get();

        if ($patients->count() < 6) {
            return;
        }

        // [type, status, hoursAgo, error]. Mixed so every filter/badge has rows.
        $rows = [
            [SmsType::Reminder24h, SmsStatus::Sent, 2, null],
            [SmsType::Reminder1h, SmsStatus::Sent, 5, null],
            [SmsType::AppointmentCreated, SmsStatus::Sent, 8, null],
            [SmsType::AppointmentRescheduled, SmsStatus::Sent, 26, null],
            [SmsType::AppointmentCancelled, SmsStatus::Sent, 30, null],
            [SmsType::Reminder24h, SmsStatus::Queued, 1, null],
            [SmsType::AppointmentCreated, SmsStatus::Queued, 3, null],
            [SmsType::BalanceReminder, SmsStatus::Sent, 50, null],
            [SmsType::Reminder24h, SmsStatus::Failed, 28, 'Provider rejected: invalid number'],
            [SmsType::AppointmentCancelled, SmsStatus::Failed, 54, 'Provider timeout'],
            [SmsType::Reminder1h, SmsStatus::Skipped, 76, 'disabled by clinic'],
            [SmsType::BalanceReminder, SmsStatus::Skipped, 100, 'disabled by clinic'],
            [SmsType::AppointmentCreated, SmsStatus::Sent, 120, null],
            [SmsType::Reminder24h, SmsStatus::Sent, 148, null],
            [SmsType::AppointmentRescheduled, SmsStatus::Sent, 172, null],
            [SmsType::Reminder1h, SmsStatus::Sent, 200, null],
            [SmsType::AppointmentCancelled, SmsStatus::Sent, 240, null],
            [SmsType::Reminder24h, SmsStatus::Queued, 4, null],
        ];

        foreach ($rows as $i => [$type, $status, $hoursAgo, $error]) {
            $patient = $patients[$i % $patients->count()];

            $this->makeLog($type, $status, $hoursAgo, $error, $patient);
        }

        // A couple of "no phone" skips with a raw recipient and no linked patient,
        // so the recipient column shows a bare phone fallback too.
        $this->makeLog(SmsType::Reminder24h, SmsStatus::Skipped, 70, 'no phone', null, '+905551112233');
        $this->makeLog(SmsType::AppointmentCreated, SmsStatus::Sent, 90, null, null, '+905554445566');
    }

    private function makeLog(
        SmsType $type,
        SmsStatus $status,
        int $hoursAgo,
        ?string $error,
        ?Patient $patient,
        ?string $phone = null,
    ): void {
        $createdAt = Carbon::now()->subHours($hoursAgo);

        $log = SmsLog::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient?->id,
            'phone' => $phone ?? $patient?->getRawOriginal('phone'),
            'type' => $type,
            'body' => $this->body($type),
            'status' => $status,
            'provider_ref' => $status === SmsStatus::Sent ? 'demo-'.fake()->numerify('##########') : null,
            'sent_at' => $status === SmsStatus::Sent ? $createdAt : null,
            'error' => $error,
        ]);

        // created_at is auto-set to now; raw-update it to spread the demo over time.
        DB::table('sms_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
    }

    private function body(SmsType $type): string
    {
        $name = $this->clinic->name;

        return match ($type) {
            SmsType::AppointmentCreated => "{$name}: 18 Haziran 14:30 için randevunuz oluşturuldu.",
            SmsType::AppointmentCancelled => "{$name}: 18 Haziran 14:30 randevunuz iptal edildi.",
            SmsType::AppointmentRescheduled => "{$name}: randevunuz 20 Haziran 10:00 olarak güncellendi.",
            SmsType::Reminder24h => "{$name}: yarın 14:30 randevunuzu hatırlatırız.",
            SmsType::Reminder1h => "{$name}: 1 saat sonra 14:30 randevunuz var.",
            SmsType::BalanceReminder => "{$name}: 450,00 TL bakiyeniz bulunmaktadır.",
            SmsType::Otp => "{$name}: doğrulama kodunuz 123456.",
        };
    }
}
