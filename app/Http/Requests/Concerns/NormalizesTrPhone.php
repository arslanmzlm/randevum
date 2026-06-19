<?php

namespace App\Http\Requests\Concerns;

use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Shared TR phone normalization for FormRequests: rewrites raw input to E.164 before
 * validation so the stored value and the phone:TR rule agree on one format.
 */
trait NormalizesTrPhone
{
    /**
     * Merge the given input field back as an E.164 TR number when present. Invalid
     * input is left untouched for the phone:TR rule to reject.
     */
    protected function normalizePhone(string $field): void
    {
        if ($this->filled($field)) {
            try {
                $this->merge([$field => $this->toE164((string) $this->input($field))]);
            } catch (\Exception) {
                // Leave as-is; the phone:TR validation rule will reject invalid numbers.
            }
        }
    }

    protected function toE164(string $value): string
    {
        return (new PhoneNumber($value, 'TR'))->formatE164();
    }
}
