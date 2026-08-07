<?php

namespace App\Modules\Core\Services;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "which clinics does this user belong to". A Core
 * Service (not a Repository) because App\Http\Middleware\SetClinicContext must
 * call it and ArchTest forbids using a module's Repositories outside the module;
 * Core is the shared kernel every module (and middleware) may import.
 */
class ClinicMembershipService
{
    /**
     * Per-request memo: a single request asks for the membership set from SetClinicContext,
     * the Inertia share and then the controller/service layer.
     *
     * @var array<int, Collection<int, Clinic>>
     */
    private array $cache = [];

    /**
     * @return Collection<int, Clinic>
     */
    public function clinicsFor(User $user): Collection
    {
        return $this->cache[$user->getKey()] ??= $this->queryClinicsFor($user);
    }

    /**
     * @return Collection<int, Clinic>
     */
    private function queryClinicsFor(User $user): Collection
    {
        return Clinic::query()
            ->join('model_has_roles', 'model_has_roles.clinic_id', '=', 'clinics.id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->whereNotNull('model_has_roles.clinic_id')
            ->whereNull('clinics.deleted_at')
            ->distinct()
            ->orderBy('clinics.name')
            ->get(['clinics.id', 'clinics.name', 'clinics.tenant_id', 'clinics.vertical_id', 'clinics.timezone']);
    }

    /**
     * @return list<int>
     */
    public function clinicIdsFor(User $user): array
    {
        return $this->clinicsFor($user)->pluck('id')->map(intval(...))->all();
    }

    /**
     * Clinics the user holds `clinics.switch` in, among their memberships.
     *
     * @return list<int>
     */
    public function switchableClinicIds(User $user): array
    {
        $rows = DB::table('model_has_roles')
            ->join('clinics', 'clinics.id', '=', 'model_has_roles.clinic_id')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->whereNotNull('model_has_roles.clinic_id')
            ->whereNull('clinics.deleted_at')
            ->where('permissions.name', 'clinics.switch')
            ->distinct()
            ->pluck('clinics.id');

        return $rows->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Clinic ids the user may switch INTO right now, given the currently active
     * clinic. Mirrors ClinicPolicy::switchTo's predicate exactly (single source of
     * truth) so the shared switcher prop and the policy enforcement cannot drift:
     * when the active clinic itself is switchable, every membership qualifies (the
     * "or active clinic" leg that prevents lock-in); otherwise only clinics where
     * the user directly holds clinics.switch.
     *
     * @return list<int>
     */
    public function switchTargetsFor(User $user, ?int $activeClinicId): array
    {
        $switchable = $this->switchableClinicIds($user);

        if ($activeClinicId !== null && in_array($activeClinicId, $switchable, true)) {
            return $this->clinicIdsFor($user);
        }

        return $switchable;
    }

    public function belongsTo(User $user, int $clinicId): bool
    {
        return in_array($clinicId, $this->clinicIdsFor($user), true);
    }

    /**
     * $preferredClinicId wins when it is a real membership; otherwise the lowest
     * membership clinic id (deterministic fallback); null when the user has none.
     */
    public function resolveActiveClinicId(User $user, ?int $preferredClinicId): ?int
    {
        $ids = $this->clinicIdsForOrdered($user);

        if ($preferredClinicId !== null && in_array($preferredClinicId, $ids, true)) {
            return $preferredClinicId;
        }

        return $ids[0] ?? null;
    }

    /**
     * Membership clinic ids ∩ the given tenant's clinics.
     *
     * @return list<int>
     */
    public function branchIdsForTenant(User $user, int $tenantId): array
    {
        return $this->clinicsFor($user)
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * @return list<int>
     */
    private function clinicIdsForOrdered(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('clinics', 'clinics.id', '=', 'model_has_roles.clinic_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->whereNotNull('model_has_roles.clinic_id')
            ->whereNull('clinics.deleted_at')
            ->distinct()
            ->orderBy('model_has_roles.clinic_id')
            ->pluck('model_has_roles.clinic_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
