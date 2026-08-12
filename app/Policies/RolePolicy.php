<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\ClinicContext;

class RolePolicy
{
    /**
     * Container-resolved (policies support constructor injection) — the first policy here to
     * have one. Keeps the clinic-ownership check where the 403 belongs instead of duplicating
     * it in every controller action.
     */
    public function __construct(private ClinicContext $clinicContext) {}

    public function viewAny(User $user): bool
    {
        return $user->can('roles.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    /**
     * Takes no Role instance: both callers (matrix save, revert) act "ForActiveClinic" — the
     * clinic's whole effective role set, not a single row — so there's no single Role to check
     * ownership against. Tenant isolation still holds: `can('roles.manage')` is resolved through
     * Spatie Teams against the active clinic (set by SetClinicContext), and the services these
     * callers invoke (RolePermissionService::syncForActiveClinic,
     * RoleCustomizationService::revertAllForActiveClinic) themselves scope every row they touch
     * to that same active clinic.
     */
    public function update(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        // Route-model binding on `roles` has no ClinicScope by design (see the model note),
        // so this clinic check is the tenant gate here — never rely on the binding alone.
        return $user->can('roles.manage')
            && $role->clinic_id === $this->clinicContext->id()
            && $role->isCustomRole();
    }

    /** Same gate as delete(): only a custom role owned by the active clinic may be renamed. */
    public function rename(User $user, Role $role): bool
    {
        return $user->can('roles.manage')
            && $role->clinic_id === $this->clinicContext->id()
            && $role->isCustomRole();
    }
}
