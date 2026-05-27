<?php

namespace App\Modules\Messaging\Data;

use App\Enums\SmsType;

/**
 * A queued SMS plus the context snapshotted onto its sms_logs row. Clinic/patient
 * ids are carried explicitly because the queue worker has no request clinic context.
 */
final readonly class SmsMessage
{
    public function __construct(
        public string $phone,
        public string $body,
        public SmsType $type,
        public ?int $clinicId = null,
        public ?int $patientId = null,
        public ?string $loggableType = null,
        public ?int $loggableId = null,
    ) {}
}
