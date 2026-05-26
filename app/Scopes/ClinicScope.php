<?php

namespace App\Scopes;

use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters clinic-owned models to the active clinic. No active clinic (superadmin /
 * system / unauthenticated) means no filter — those callers see across clinics.
 */
class ClinicScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $clinicId = app(ClinicContext::class)->id();

        if (! is_null($clinicId)) {
            $builder->where($model->getTable().'.clinic_id', $clinicId);
        }
    }
}
