<?php

namespace App\Modules\Identity\Repositories;

use App\Enums\ClinicRole;
use App\Models\Role;
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
                $roleIds = $permissionIdsByRole->get($permission->id, collect())
                    ->pluck('role_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $permission->setAttribute('role_ids', $roleIds);

                return $permission;
            });
    }
}
