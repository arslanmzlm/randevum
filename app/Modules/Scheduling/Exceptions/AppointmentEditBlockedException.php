<?php

namespace App\Modules\Scheduling\Exceptions;

use RuntimeException;

/**
 * Thrown when an appointment may no longer be edited — its doctor has been soft-deleted, so
 * there is no calendar left to move it onto. Deliberately not a ValidationException: the refusal
 * happens before the form is even shown, and writing to the field-error bag would trip the
 * frontend's generic "check the form" toast on top of the real message (same reasoning as
 * TreatmentVoidBlockedException). The message arrives already translated — the caller flashes it.
 */
class AppointmentEditBlockedException extends RuntimeException {}
