<?php

namespace App\Modules\Identity\Repositories;

use App\Enums\ClinicRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Services\RoleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RoleRepository
{
    public function __construct(private RoleResolver $roleResolver) {}

    /**
     * The matrix columns for a clinic: the five ClinicRole baseline roles (clinic copy if one
     * exists, global template otherwise) in enum order, followed by any extra clinic-owned
     * roles whose name is not a ClinicRole case, ordered by name (forward-compat for 8b
     * custom roles — the tail is always empty today).
     *
     * @return Collection<int, Role>
     */
    public function columnsForClinic(?int $clinicId): Collection
    {
        $baseline = $this->roleResolver->resolveManyForClinic($clinicId, ClinicRole::values());

        $columns = collect(ClinicRole::values())
            ->map(fn (string $name) => $baseline->get($name))
            ->filter()
            ->values();

        if ($clinicId !== null) {
            $extra = Role::query()
                ->where('clinic_id', $clinicId)
                ->where('guard_name', 'web')
                ->whereNotIn('name', ClinicRole::values())
                ->orderBy('name')
                ->get();

            $columns = $columns->concat($extra);
        }

        return $columns;
    }

    /**
     * Every seeded permission, each carrying the ids (from $roleIds — the matrix's column
     * role ids) that hold it. No with('roles') eager load — that would pull every clinic's
     * role rows.
     *
     * @param  list<int>  $roleIds
     * @return Collection<int, Permission>
     */
    public function allPermissions(array $roleIds): Collection
    {
        $permissionIdsByRole = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->get(['permission_id', 'role_id'])
            ->groupBy('permission_id');

        return Permission::query()
            ->where('guard_name', 'web')
            ->get()
            ->map(function (Permission $permission) use ($permissionIdsByRole): Permission {
                $heldByRoleIds = $permissionIdsByRole->get($permission->id, collect())
                    ->pluck('role_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $permission->setAttribute('role_ids', $heldByRoleIds);

                return $permission;
            });
    }

    /**
     * Role ids the user holds in the given clinic (or globally, when $clinicId is null).
     *
     * @return list<int>
     */
    public function roleIdsForUser(User $user, ?int $clinicId): array
    {
        return DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->where('clinic_id', $clinicId)
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Assignment counts for the given role ids, restricted to this clinic — a global baseline
     * column must not report another clinic's assignments.
     *
     * @param  list<int>  $roleIds
     * @return array<int, int> role_id => count
     */
    public function assignedUserCounts(array $roleIds, ?int $clinicId): array
    {
        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('clinic_id', $clinicId)
            ->select('role_id', DB::raw('count(*) as aggregate'))
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Whether a role has any assignment at all. No clinic filter is strictly needed — a custom
     * role id is already clinic-specific, so `model_has_roles` rows for it can only ever carry
     * that same clinic_id — but the caller (RoleCustomizationService::deleteCustomRole, gating a
     * delete) is exactly the kind of check that should stay correct even if that invariant ever
     * changes, so the filter is added defensively.
     */
    public function hasAnyAssignment(int $roleId, ?int $clinicId): bool
    {
        return DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('clinic_id', $clinicId)
            ->exists();
    }

    /**
     * This clinic's baseline-copy roles (clinic_id set, name is a ClinicRole case).
     *
     * @return Collection<int, Role>
     */
    public function baselineCopiesForClinic(int $clinicId): Collection
    {
        return Role::query()
            ->where('clinic_id', $clinicId)
            ->where('guard_name', 'web')
            ->whereIn('name', ClinicRole::values())
            ->orderBy('name')
            ->get();
    }

    /**
     * The role's current permission names — used by SelfLockoutGuard and by the "unchanged
     * submission → skip" comparison in RolePermissionService.
     *
     * @return list<string>
     */
    public function permissionNamesFor(int $roleId): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->all();
    }
}
