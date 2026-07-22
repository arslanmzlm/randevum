<?php

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Services\InstallmentReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendInstallmentRemindersCommand extends Command
{
    protected $signature = 'sms:send-installment-reminders';

    protected $description = 'Dispatch 7-day and 1-day payment-plan installment reminder SMS for all due installments across all clinics.';

    public function handle(InstallmentReminderService $service): int
    {
        $dispatched = $service->sendDueReminders(Carbon::now());

        $this->info("Installment reminder SMS dispatched: {$dispatched}");

        return self::SUCCESS;
    }
}
