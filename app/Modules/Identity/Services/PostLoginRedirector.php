<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Returns the appropriate post-login URL for a given user.
 *
 * Resolves role names via a direct DB query (team-independent) so it works
 * at login time before SetClinicContext has run for the current request.
 * Priority map: global admin → /admin; clinic staff → /dashboard;
 * patient → patient home (dashboard for now); no role → fallback.
 */
class PostLoginRedirector
{
    public function resolve(User $user): string
    {
        $roleNames = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('roles.name');

        foreach (['superadmin', 'admin', 'moderator'] as $role) {
            if ($roleNames->contains($role)) {
                return route('admin');
            }
        }

        foreach (['owner', 'manager', 'doctor', 'receptionist', 'assistant'] as $role) {
            if ($roleNames->contains($role)) {
                return route('dashboard');
            }
        }

        return route('dashboard');
    }
}
