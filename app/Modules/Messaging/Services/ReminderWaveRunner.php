<?php

namespace App\Modules\Messaging\Services;

use App\Enums\SmsType;
use App\Modules\Messaging\Contracts\ReminderWaveContract;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;

class ReminderWaveRunner implements ReminderWaveContract
{
    public function __construct(
        private SmsDispatcherContract $dispatcher,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function dispatchWave(
        iterable $records,
        string $loggableType,
        SmsType $type,
        callable $toMessage,
        callable $onSent,
    ): int {
        $count = 0;

        foreach ($records as $record) {
            // Second idempotency guard: if a Sent log already exists for this
            // record+type, skip dispatch even though the flag was false.
            if ($this->dispatcher->wasSent($loggableType, (int) $record->getKey(), $type)) {
                $onSent($record);

                continue;
            }

            $this->dispatcher->dispatch($toMessage($record));

            $onSent($record);

            $count++;
        }

        return $count;
    }
}
