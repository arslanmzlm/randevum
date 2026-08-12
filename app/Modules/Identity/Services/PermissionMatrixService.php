<?php

namespace App\Modules\Identity\Services;

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Repositories\RoleRepository;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Support\ClinicContext;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

class PermissionMatrixService
{
    public function __construct(
        private RoleRepository $repository,
        private ClinicContext $clinicContext,
        private SelfLockoutGuard $guard,
    ) {}

    /**
     * Builds the permission matrix props for the active clinic — see the "Inertia props
     * contract" in the run-file spec for the exact shape.
     *
     * @return array{
     *     roles: list<array{id: int, name: string, label: string, is_customized: bool, is_custom: bool, is_own: bool, assigned_users_count: int, can_delete: bool}>,
     *     groups: list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>, locked_role_ids: list<int>, undefined_role_ids: list<int>}>}>,
     *     undefinedPermissions: list<array{name: string, label: string}>,
     * }
     */
    public function matrixForActiveClinic(User $user): array
    {
        $clinicId = $this->clinicContext->id();
        $columns = $this->repository->columnsForClinic($clinicId);
        $columnIds = $columns->pluck('id')->all();
        $permissions = $this->repository->allPermissions($columnIds);

        $roleLabels = trans('role.names');
        $permissionLabels = trans('permission.names');
        $ownRoleIds = $this->guard->ownRoleIds($user, $clinicId);
        $assignedCounts = $this->repository->assignedUserCounts($columnIds, $clinicId);

        $roles = $columns->map(function ($role) use ($roleLabels, $ownRoleIds, $assignedCounts): array {
            $isCustom = $role->isCustomRole();
            $isOwn = in_array($role->id, $ownRoleIds, true);
            $assignedCount = $assignedCounts[$role->id] ?? 0;

            return [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $roleLabels[$role->name] ?? $role->name,
                'is_customized' => $role->isCustomized() && ! $isCustom,
                'is_custom' => $isCustom,
                'is_own' => $isOwn,
                'assigned_users_count' => $assignedCount,
                'can_delete' => $isCustom && $assignedCount === 0 && ! $isOwn,
            ];
        })->values()->all();

        // Baseline copies only (clinic_id set, name is a ClinicRole case). A custom role
        // (createCustomRole) starts with zero permissions by design — nothing about it was
        // ever "missed", every gap there is a deliberate initial default, not a copy-on-write
        // blind spot. Global template columns (no copy yet) stay in lockstep with
        // PermissionSeeder automatically, so they carry no drift either.
        $copies = $columns->filter(
            fn (Role $role): bool => $role->isCustomized() && ! $role->isCustomRole(),
        )->values();

        $undefinedRoleIdsByPermission = $this->undefinedRoleIdsByPermission($permissions, $copies);

        $groups = $this->buildGroups($permissions, $ownRoleIds, $columnIds, $permissionLabels, $undefinedRoleIdsByPermission);
        $undefinedPermissions = $this->undefinedPermissionsSummary($undefinedRoleIdsByPermission, $permissionLabels);

        return compact('roles', 'groups', 'undefinedPermissions');
    }

    /**
     * A permission is "undefined" for a baseline copy when the copy could never have decided
     * it either way: it didn't exist yet when the copy was made (permissions.created_at is
     * after the copy's roles.created_at) AND the copy doesn't currently hold it. That second
     * condition also covers the case where the owner has since noticed the new row in the
     * matrix and granted it manually — once granted, it's no longer "undefined".
     *
     * A permission that existed before the copy was created is never flagged even if the copy
     * doesn't hold it — that's the clinic's own decision (inherited-but-later-revoked, or never
     * granted to that baseline role to begin with), not a copy-on-write gap.
     *
     * @param  Collection<int, Permission>  $permissions
     * @param  Collection<int, Role>  $copies  baseline role copies for the active clinic
     * @return array<string, list<int>> permission name => copy role ids it's undefined for
     */
    private function undefinedRoleIdsByPermission(Collection $permissions, Collection $copies): array
    {
        if ($copies->isEmpty()) {
            return [];
        }

        $map = [];

        foreach ($permissions as $permission) {
            $heldByRoleIds = $permission->getAttribute('role_ids');

            foreach ($copies as $copy) {
                $existedBeforeCopy = $permission->created_at !== null
                    && $permission->created_at->lessThanOrEqualTo($copy->created_at);

                if ($existedBeforeCopy || in_array($copy->id, $heldByRoleIds, true)) {
                    continue;
                }

                $map[$permission->name][] = $copy->id;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, list<int>>  $undefinedRoleIdsByPermission
     * @param  array<string, string>  $permissionLabels
     * @return list<array{name: string, label: string}>
     */
    private function undefinedPermissionsSummary(array $undefinedRoleIdsByPermission, array $permissionLabels): array
    {
        return collect(array_keys($undefinedRoleIdsByPermission))
            ->sort()
            ->map(fn (string $name): array => [
                'name' => $name,
                'label' => $permissionLabels[$name] ?? $name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @param  list<int>  $ownRoleIds
     * @param  list<int>  $columnIds
     * @param  array<string, string>  $permissionLabels
     * @param  array<string, list<int>>  $undefinedRoleIdsByPermission
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>, locked_role_ids: list<int>, undefined_role_ids: list<int>}>}>
     */
    private function buildGroups(
        Collection $permissions,
        array $ownRoleIds,
        array $columnIds,
        array $permissionLabels,
        array $undefinedRoleIdsByPermission,
    ): array {
        $groupLabels = trans('permission.groups');

        $sorted = $permissions->sortBy(
            fn (Permission $permission) => PermissionCatalog::sortKey($permission->name),
        );

        $byGroup = $sorted->groupBy(fn (Permission $permission) => PermissionCatalog::groupFor($permission->name));

        $lockedColumnIds = array_values(array_intersect($ownRoleIds, $columnIds));

        return collect(PermissionCatalog::orderedGroups())
            ->filter(fn (string $groupKey) => $byGroup->has($groupKey))
            ->map(fn (string $groupKey): array => [
                'key' => $groupKey,
                'label' => $groupLabels[$groupKey] ?? $groupKey,
                'permissions' => $byGroup->get($groupKey)->map(fn (Permission $permission): array => [
                    'name' => $permission->name,
                    'label' => $permissionLabels[$permission->name] ?? $permission->name,
                    'role_ids' => $permission->getAttribute('role_ids'),
                    'locked_role_ids' => in_array($permission->name, SelfLockoutGuard::PROTECTED_PERMISSIONS, true)
                        ? $lockedColumnIds
                        : [],
                    'undefined_role_ids' => $undefinedRoleIdsByPermission[$permission->name] ?? [],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
