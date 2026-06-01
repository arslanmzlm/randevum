<?php

namespace App\Modules\Medical\Exceptions;

use App\Models\Patient;
use RuntimeException;

/**
 * Thrown by PatientService::create() when the submitted phone matches a soft-deleted
 * patient in the active clinic. The controller redirects back with a restore prompt
 * instead of creating a duplicate.
 */
class TrashedPhoneConflictException extends RuntimeException
{
    public function __construct(public readonly Patient $patient)
    {
        parent::__construct('Phone number belongs to a soft-deleted patient.');
    }
}
