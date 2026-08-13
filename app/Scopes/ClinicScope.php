<?php

namespace App\Scopes;

use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters clinic-owned models to the active clinic. Fail-closed: with no active clinic
 * (console, queue, scheduler, unauthenticated) the query matches NOTHING rather than
 * every clinic — a missing context must never widen a query to the whole platform.
 * Legitimate cross-clinic work opts out explicitly with withoutGlobalScope(ClinicScope::class).
 */
class ClinicScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $clinicId = app(ClinicContext::class)->id();

        if (is_null($clinicId)) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.clinic_id', $clinicId);
    }
}
