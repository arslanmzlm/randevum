<?php

namespace App\Modules\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when a delete is refused because records still reference the row. Deliberately
 * not a ValidationException: deleting is a single button, not a form submission, so writing
 * to the field-error bag would trip the frontend's generic "check the form" toast on top of
 * the real message. The message arrives already translated — the caller just flashes it.
 */
class DeletionBlockedException extends RuntimeException {}
