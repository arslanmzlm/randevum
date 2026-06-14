<?php

namespace App\Modules\Scheduling\Console\Commands;

use App\Modules\Scheduling\Services\AppointmentReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'sms:send-reminders';

    protected $description = 'Dispatch 24h and 1h appointment reminder SMS for all due appointments across all clinics.';

    public function handle(AppointmentReminderService $service): int
    {
        $dispatched = $service->sendDueReminders(Carbon::now());

        $this->info("Reminder SMS dispatched: {$dispatched}");

        return self::SUCCESS;
    }
}
