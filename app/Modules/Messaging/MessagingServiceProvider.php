<?php

namespace App\Modules\Messaging;

use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Contracts\SmsHistoryContract;
use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Providers\LogSmsProvider;
use App\Modules\Messaging\Providers\NetgsmSmsProvider;
use App\Modules\Messaging\Providers\NullSmsProvider;
use App\Modules\Messaging\Services\SmsDispatcher;
use App\Modules\Messaging\Services\SmsLogService;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsProviderInterface::class, function (): SmsProviderInterface {
            return match (config('services.sms.provider')) {
                'netgsm' => new NetgsmSmsProvider(config('services.sms.netgsm')),
                'log' => new LogSmsProvider,
                default => new NullSmsProvider,
            };
        });

        $this->app->bind(SmsDispatcherContract::class, SmsDispatcher::class);
        $this->app->bind(SmsHistoryContract::class, SmsLogService::class);
    }
}
