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
            if (is_null($model->getAttribute('clinic_id')) && ($clinicId = app(ClinicContext::class)->id())) {
                $model->setAttribute('clinic_id', $clinicId);
            }
        });
    }
}
