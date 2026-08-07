<?php

namespace App\Modules\Identity\Services;

use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Repositories\RoleRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The single server-side self-lockout gate: a user may never strip roles.manage /
 * roles.viewAny from a role they themselves hold, delete their own role, or revert in a way
 * that would do either.
 */
class SelfLockoutGuard
{
    public const PROTECTED_PERMISSIONS = ['roles.manage', 'roles.viewAny'];

    public function __construct(private RoleRepository $repository) {}

    /** @var array<string, list<int>> */
    private array $ownRoleCache = [];

    /**
     * @return list<int> role ids the user holds in this clinic
     */
    public function ownRoleIds(User $user, ?int $clinicId): array
    {
        // Called once per submitted role in RolePermissionService's loop and again per copy on
        // revert; the answer cannot change within a request.
        return $this->ownRoleCache[$user->getKey().':'.$clinicId] ??= $this->repository->roleIdsForUser($user, $clinicId);
    }

    /**
     * @param  list<string>  $desired
     */
    public function assertKeepsProtected(User $user, ?int $clinicId, Role $role, array $desired): void
    {
        if (! in_array($role->id, $this->ownRoleIds($user, $clinicId), true)) {
            return;
        }

        $current = $this->repository->permissionNamesFor($role->id);

        $dropped = array_intersect(self::PROTECTED_PERMISSIONS, $current, array_diff($current, $desired));

        if ($dropped !== []) {
            throw ValidationException::withMessages([
                'permissions' => [__('messages.role.self_lockout')],
            ]);
        }
    }

    public function assertNotOwnRole(User $user, ?int $clinicId, Role $role): void
    {
        if (in_array($role->id, $this->ownRoleIds($user, $clinicId), true)) {
            throw ValidationException::withMessages([
                'role' => [__('messages.role.cannot_delete_own_role')],
            ]);
        }
    }

    /**
     * @param  Collection<int, Role>  $copies  baseline copies about to be reverted
     */
    public function assertRevertKeepsProtected(User $user, ?int $clinicId, Collection $copies): void
    {
        $ownRoleIds = $this->ownRoleIds($user, $clinicId);

        foreach ($copies as $copy) {
            if (! in_array($copy->id, $ownRoleIds, true)) {
                continue;
            }

            $copyPermissions = $this->repository->permissionNamesFor($copy->id);

            $globalRole = Role::query()
                ->where('name', $copy->name)
                ->where('guard_name', 'web')
                ->whereNull('clinic_id')
                ->firstOrFail();

            $globalPermissions = $this->repository->permissionNamesFor($globalRole->id);

            $wouldLose = array_intersect(self::PROTECTED_PERMISSIONS, $copyPermissions, array_diff($copyPermissions, $globalPermissions));

            if ($wouldLose !== []) {
                throw ValidationException::withMessages([
                    'role' => [__('messages.role.revert_locks_out')],
                ]);
            }
        }
    }
}
