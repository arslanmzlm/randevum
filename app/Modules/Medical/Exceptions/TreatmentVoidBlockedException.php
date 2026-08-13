<?php

namespace App\Modules\Medical\Exceptions;

use RuntimeException;

/**
 * Thrown when voiding a treatment is refused — wrong source status, or money already
 * collected against it. Deliberately not a ValidationException: void is a single button,
 * not a form submission, so writing to the field-error bag would trip the frontend's
 * generic "check the form" toast on top of the real message (same reasoning as
 * DeletionBlockedException). The message arrives already translated — the caller flashes it.
 */
class TreatmentVoidBlockedException extends RuntimeException {}
