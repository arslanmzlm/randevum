<?php

namespace App\Modules\Messaging\Data;

/**
 * Outcome of a single provider send attempt.
 */
final readonly class SmsResponse
{
    public function __construct(
        public bool $successful,
        public ?string $reference = null,
        public ?string $error = null,
    ) {}

    public static function success(?string $reference = null): self
    {
        return new self(true, reference: $reference);
    }

    public static function failure(string $error, ?string $reference = null): self
    {
        return new self(false, reference: $reference, error: $error);
    }
}
