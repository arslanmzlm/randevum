<?php

namespace App\Modules\Identity\Services;

use App\Enums\ClinicRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Repositories\RoleRepository;
use App\Support\ClinicContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Copy-on-write role customization: the first time a clinic touches a global baseline role,
 * it gets its own row (same name/guard, permissions copied) and its own model_has_roles
 * assignments are re-pointed to it — the global row and every other clinic's assignments
 * are untouched. Also owns the rest of the clinic role lifecycle: custom role create/delete
 * and reverting every baseline copy back to the global template.
 */
class RoleCustomizationService
{
    public function __construct(
        private ClinicContext $clinicContext,
        private PermissionRegistrar $permissionRegistrar,
        private RoleRepository $repository,
        private SelfLockoutGuard $guard,
    ) {}

    public function customizeForActiveClinic(Role $role): Role
    {
        $clinicId = $this->clinicContext->clinicOrFail()->id;

        if ($role->clinic_id === $clinicId) {
            return $role;
        }

        if ($role->clinic_id !== null) {
            throw new RuntimeException("Role [{$role->name}] belongs to another clinic and cannot be customized here.");
        }

        if (ClinicRole::tryFrom($role->name) === null) {
            throw new RuntimeException("Role [{$role->name}] is a platform-global role and cannot be customized.");
        }

        $copy = DB::transaction(function () use ($role, $clinicId): Role {
            // Role::query()->create(), NOT Role::create() — Spatie's static create() runs its
            // own findByParam() duplicate-check first ("team IS NULL OR team = X", unordered
            // first()) and throws RoleAlreadyExists as soon as a global role of this name
            // exists, which is exactly the customization case. A plain Eloquent create bypasses
            // that check (it's what Spatie's own create() delegates to internally anyway).
            $copy = Role::query()->create([
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'clinic_id' => $clinicId,
            ]);

            // Direct pivot insert, not syncPermissions — stays independent of the registrar's
            // team-aware cache/state; the cache is flushed once after the transaction commits.
            $permissionIds = $role->permissions()->pluck('permissions.id');

            if ($permissionIds->isNotEmpty()) {
                DB::table('role_has_permissions')->insert(
                    $permissionIds->map(fn (int $permissionId): array => [
                        'permission_id' => $permissionId,
                        'role_id' => $copy->id,
                    ])->all(),
                );
            }

            DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->where('clinic_id', $clinicId)
                ->update(['role_id' => $copy->id]);

            return $copy;
        });

        $this->permissionRegistrar->forgetCachedPermissions();

        return $copy;
    }

    public function createCustomRole(string $name): Role
    {
        $clinicId = $this->clinicContext->clinicOrFail()->id;

        // Role::query()->create(), not Role::create() — same reasoning as customizeForActiveClinic():
        // Spatie's static create() runs findByParam()'s duplicate check first.
        $role = Role::query()->create([
            'name' => trim($name),
            'guard_name' => 'web',
            'clinic_id' => $clinicId,
        ]);

        $this->permissionRegistrar->forgetCachedPermissions();

        return $role;
    }

    /**
     * @throws ValidationException
     */
    public function deleteCustomRole(User $user, Role $role): void
    {
        $clinicId = $this->clinicContext->clinicOrFail()->id;

        if ($role->clinic_id !== $clinicId || ! $role->isCustomRole()) {
            // The policy already returns 403 for this case; this is defence in depth.
            throw new RuntimeException("Role [{$role->id}] is not a deletable custom role for this clinic.");
        }

        $this->guard->assertNotOwnRole($user, $clinicId, $role);

        if ($this->repository->hasAnyAssignment($role->id)) {
            throw ValidationException::withMessages([
                'role' => [__('messages.role.has_assigned_users')],
            ]);
        }

        $role->delete();

        $this->permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * Deletes every baseline copy this clinic owns, re-pointing its assignments back to the
     * matching global template row. Custom roles are never touched.
     *
     * @return list<string> reverted role names, for the toast
     */
    public function revertAllForActiveClinic(User $user): array
    {
        $clinicId = $this->clinicContext->clinicOrFail()->id;

        $copies = $this->repository->baselineCopiesForClinic($clinicId);

        if ($copies->isEmpty()) {
            return [];
        }

        $this->guard->assertRevertKeepsProtected($user, $clinicId, $copies);

        DB::transaction(function () use ($copies, $clinicId): void {
            foreach ($copies as $copy) {
                $globalRole = Role::query()
                    ->where('name', $copy->name)
                    ->where('guard_name', 'web')
                    ->whereNull('clinic_id')
                    ->firstOrFail();

                DB::table('model_has_roles')
                    ->where('role_id', $copy->id)
                    ->where('clinic_id', $clinicId)
                    ->update(['role_id' => $globalRole->id]);

                $copy->delete();
            }
        });

        $this->permissionRegistrar->forgetCachedPermissions();

        return $copies->pluck('name')->all();
    }
}
