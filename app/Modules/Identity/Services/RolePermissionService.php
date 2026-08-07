<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Repositories\RoleRepository;
use App\Support\ClinicContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bulk permission-matrix save: for each changed role, copy-on-write a global baseline row the
 * first time it's touched, then replace its permission set. Untouched-but-submitted roles are
 * skipped before any copy is made.
 */
class RolePermissionService
{
    public function __construct(
        private RoleRepository $repository,
        private RoleCustomizationService $customization,
        private SelfLockoutGuard $guard,
        private ClinicContext $clinicContext,
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @param  list<array{id: int, permissions: list<string>}>  $changes
     */
    public function syncForActiveClinic(User $user, array $changes): void
    {
        $clinicId = $this->clinicContext->clinicOrFail()->id;

        $columns = $this->repository->columnsForClinic($clinicId)->keyBy('id');

        foreach ($changes as $change) {
            if (! $columns->has($change['id'])) {
                // Defence in depth — UpdateRolePermissionsRequest already rejects a foreign
                // id with 422 via Rule::in(columnsForClinic ids).
                throw new RuntimeException("Role [{$change['id']}] is not editable in this clinic.");
            }

            $this->guard->assertKeepsProtected($user, $clinicId, $columns[$change['id']], $change['permissions']);
        }

        DB::transaction(function () use ($changes, $columns): void {
            foreach ($changes as $change) {
                $role = $columns[$change['id']];
                $desired = $change['permissions'];

                // Compare BEFORE customizing — an unchanged submission never mints a copy.
                $current = $this->repository->permissionNamesFor($role->id);

                if ($this->sameSet($current, $desired)) {
                    continue;
                }

                if ($role->clinic_id === null) {
                    $role = $this->customization->customizeForActiveClinic($role);
                }

                DB::table('role_has_permissions')->where('role_id', $role->id)->delete();

                $permissionIds = Permission::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $desired)
                    ->pluck('id');

                if ($permissionIds->isNotEmpty()) {
                    DB::table('role_has_permissions')->insert(
                        $permissionIds->map(fn (int $permissionId): array => [
                            'permission_id' => $permissionId,
                            'role_id' => $role->id,
                        ])->all(),
                    );
                }
            }
        });

        $this->permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
