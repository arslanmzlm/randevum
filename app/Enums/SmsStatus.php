<?php

namespace App\Enums;

// `Sent` = accepted by provider, not handset-confirmed.
// TODO (DLR): add Delivered/Undelivered when a Netgsm delivery-report webhook exists.
enum SmsStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
