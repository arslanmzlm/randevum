<?php

namespace App\Modules\Identity\Services;

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
    ) {}

    /**
     * Builds the read-only permission matrix props for the active clinic — see the
     * "Inertia props contract" in the run-file spec for the exact shape.
     *
     * @return array{
     *     roles: list<array{id: int, name: string, label: string, is_customized: bool}>,
     *     groups: list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>}>}>,
     * }
     */
    public function matrixForActiveClinic(): array
    {
        $clinicId = $this->clinicContext->id();
        $columns = $this->repository->columnsForClinic($clinicId);
        $permissions = $this->repository->allPermissions($columns->pluck('id')->all());

        $roleLabels = trans('role.names');

        $roles = $columns->map(fn ($role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $roleLabels[$role->name] ?? $role->name,
            'is_customized' => $role->isCustomized(),
        ])->values()->all();

        $groups = $this->buildGroups($permissions);

        return compact('roles', 'groups');
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string, role_ids: list<int>}>}>
     */
    private function buildGroups($permissions): array
    {
        $permissionLabels = trans('permission.names');
        $groupLabels = trans('permission.groups');

        $sorted = $permissions->sortBy(
            fn (Permission $permission) => PermissionCatalog::sortKey($permission->name),
        );

        $byGroup = $sorted->groupBy(fn (Permission $permission) => PermissionCatalog::groupFor($permission->name));

        return collect(PermissionCatalog::orderedGroups())
            ->filter(fn (string $groupKey) => $byGroup->has($groupKey))
            ->map(fn (string $groupKey): array => [
                'key' => $groupKey,
                'label' => $groupLabels[$groupKey] ?? $groupKey,
                'permissions' => $byGroup->get($groupKey)->map(fn (Permission $permission): array => [
                    'name' => $permission->name,
                    'label' => $permissionLabels[$permission->name] ?? $permission->name,
                    'role_ids' => $permission->getAttribute('role_ids'),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
