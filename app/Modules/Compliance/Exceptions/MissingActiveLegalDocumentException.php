<?php

namespace App\Modules\Compliance\Exceptions;

use RuntimeException;

/**
 * Thrown when an active platform legal document of the requested type is not seeded.
 * Registration cannot record the owner's consent without it, so the signup transaction
 * rolls back. Never reaches a user in normal operation (LegalDocumentSeeder provisions them).
 */
class MissingActiveLegalDocumentException extends RuntimeException
{
    public function __construct(string $type)
    {
        parent::__construct("No active platform legal document of type '{$type}' found.");
    }
}
