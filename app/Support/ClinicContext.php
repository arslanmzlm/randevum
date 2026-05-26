<?php

namespace App\Support;

/**
 * Request-scoped active clinic (tenant) context. Bound as a singleton and set by
 * SetClinicContext middleware; read by ClinicScope and BelongsToClinic.
 */
class ClinicContext
{
    private ?int $clinicId = null;

    public function id(): ?int
    {
        return $this->clinicId;
    }

    public function set(?int $clinicId): void
    {
        $this->clinicId = $clinicId;
    }

    public function forget(): void
    {
        $this->clinicId = null;
    }

    public function has(): bool
    {
        return ! is_null($this->clinicId);
    }
}
