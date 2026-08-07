<?php

namespace App\Modules\Core\Services;

use App\Models\Role;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Replaces Spatie's implicit `findByParam()` lookup (`team IS NULL OR team = X`, unordered
 * `first()`) with a deterministic clinic-first resolver: a clinic's own role copy wins over
 * the global baseline template when both exist with the same name.
 */
class RoleResolver
{
    public function __construct(private ClinicContext $clinicContext) {}

    /**
     * Resolve a role name for the active clinic.
     */
    public function resolve(string $name): Role
    {
        return $this->resolveForClinic($this->clinicContext->id(), $name);
    }

    /**
     * @throws ModelNotFoundException<Role>
     */
    public function resolveForClinic(?int $clinicId, string $name): Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->where(fn ($q) => $q->whereNull('clinic_id')->when(
                $clinicId !== null,
                fn ($q) => $q->orWhere('clinic_id', $clinicId),
            ))
            ->orderByRaw('CASE WHEN clinic_id IS NULL THEN 1 ELSE 0 END')
            ->firstOrFail();
    }

    /**
     * One query for the whole name list, clinic-first precedence, keyed by name —
     * avoids N+1 when resolving several roles at once (e.g. the permission matrix columns).
     *
     * @param  list<string>  $names
     * @return Collection<string, Role>
     */
    public function resolveManyForClinic(?int $clinicId, array $names): Collection
    {
        return Role::query()
            ->whereIn('name', $names)
            ->where('guard_name', 'web')
            ->where(fn ($q) => $q->whereNull('clinic_id')->when(
                $clinicId !== null,
                fn ($q) => $q->orWhere('clinic_id', $clinicId),
            ))
            ->orderByRaw('CASE WHEN clinic_id IS NULL THEN 1 ELSE 0 END')
            ->get()
            // unique() keeps the FIRST match per key — with the clinic-first ordering above,
            // that's the clinic's copy when one exists. keyBy() alone would keep the LAST
            // match instead, silently overwriting the copy with the global row.
            ->unique('name')
            ->keyBy('name');
    }
}
