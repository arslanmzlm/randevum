<?php

namespace App\Support;

use App\Models\Clinic;

/**
 * Request-scoped active clinic (tenant) context. Bound as a singleton and set by
 * SetClinicContext middleware; read by ClinicScope and BelongsToClinic.
 */
class ClinicContext
{
    private ?int $clinicId = null;

    private ?Clinic $clinic = null;

    public function id(): ?int
    {
        return $this->clinicId;
    }

    public function set(?int $clinicId): void
    {
        if ($clinicId !== $this->clinicId) {
            $this->clinic = null;
        }

        $this->clinicId = $clinicId;
    }

    public function forget(): void
    {
        $this->clinicId = null;
        $this->clinic = null;
    }

    public function has(): bool
    {
        return ! is_null($this->clinicId);
    }

    /**
     * The active clinic, lazily loaded and cached for the request. Null when no
     * clinic is in context (superadmin, patient, queue, scheduler).
     */
    public function clinic(): ?Clinic
    {
        if ($this->clinicId === null) {
            return null;
        }

        return $this->clinic ??= Clinic::find($this->clinicId);
    }

    /**
     * The active clinic, or a thrown exception when none is in context — for the
     * clinic-scoped surfaces (controllers behind SetClinicContext) that require one.
     */
    public function clinicOrFail(): Clinic
    {
        return $this->clinic() ?? throw new \RuntimeException('No active clinic in context.');
    }

    public function timezone(): string
    {
        return $this->clinicOrFail()->timezone;
    }

    public function currency(): string
    {
        return $this->clinicOrFail()->currency;
    }

    public function locale(): string
    {
        return $this->clinicOrFail()->locale;
    }
}
