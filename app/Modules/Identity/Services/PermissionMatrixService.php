<?php

namespace App\Modules\Identity\Services;

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
     *     groups: list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>, locked_role_ids: list<int>}>}>,
     * }
     */
    public function matrixForActiveClinic(User $user): array
    {
        $clinicId = $this->clinicContext->id();
        $columns = $this->repository->columnsForClinic($clinicId);
        $columnIds = $columns->pluck('id')->all();
        $permissions = $this->repository->allPermissions($columnIds);

        $roleLabels = trans('role.names');
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

        $groups = $this->buildGroups($permissions, $ownRoleIds, $columnIds);

        return compact('roles', 'groups');
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @param  list<int>  $ownRoleIds
     * @param  list<int>  $columnIds
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>, locked_role_ids: list<int>}>}>
     */
    private function buildGroups(Collection $permissions, array $ownRoleIds, array $columnIds): array
    {
        $permissionLabels = trans('permission.names');
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
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
