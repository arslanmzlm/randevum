<?php

namespace App\Modules\Messaging\Contracts;

use App\Enums\SmsType;
use App\Modules\Messaging\Data\SmsMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Runs one scheduled reminder wave: walk the due records, drop the ones a Sent
 * sms_logs row already covers, dispatch the rest and persist each record's
 * "reminder sent" flag. Every reminder domain (appointments, installments, ...)
 * differs only in WHICH records are due and WHAT the SMS says — the loop, the
 * idempotency guard and the flag write are identical, so they live here.
 *
 * Selecting the due records stays with the calling module: the window/date query
 * and its ClinicScope exemptions are domain knowledge, and the cron runs with no
 * active clinic, so those queries must keep dropping the fail-closed scope.
 */
interface ReminderWaveContract
{
    /**
     * @param  iterable<int, Model>  $records  due records, already loaded with the relations $toMessage reads
     * @param  string  $loggableType  morph-map slug written to sms_logs.loggable_type
     * @param  callable(Model): SmsMessage  $toMessage  builds the SMS for one record
     * @param  callable(Model): void  $onSent  persists the record's reminder flag
     * @return int number of dispatch calls made (a gate-skipped send still counts)
     */
    public function dispatchWave(
        iterable $records,
        string $loggableType,
        SmsType $type,
        callable $toMessage,
        callable $onSent,
    ): int;
}
