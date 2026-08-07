<?php

namespace App\Models\Concerns;

use App\Scopes\ClinicScope;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies the global ClinicScope and auto-fills clinic_id from the active clinic
 * context on create. For clinic-owned operational models (one clinic = one tenant in MVP).
 */
trait BelongsToClinic
{
    public static function bootBelongsToClinic(): void
    {
        static::addGlobalScope(new ClinicScope);

        static::creating(function (Model $model): void {
            if (! $model->autoFillsClinicId()) {
                return;
            }

            if (is_null($model->getAttribute('clinic_id')) && ($clinicId = app(ClinicContext::class)->id())) {
                $model->setAttribute('clinic_id', $clinicId);
            }
        });
    }

    /**
     * Platform-level rows whose clinic_id is null BY DESIGN (definition/reference tables that
     * still want the read scope) override this and return false, so a create that happens to
     * run inside a clinic-scoped request is not silently stamped.
     */
    public function autoFillsClinicId(): bool
    {
        return true;
    }
}
