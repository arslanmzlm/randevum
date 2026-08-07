<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Support\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's active clinic into ClinicContext and the Spatie permission
 * team id. Prefers the session's active_clinic_id (multi-branch switcher), falling
 * back to the user's clinic-scoped role memberships. Global-role users (superadmin /
 * patient) carry no clinic → context stays null.
 */
class SetClinicContext
{
    public function __construct(
        private ClinicContext $clinicContext,
        private PermissionRegistrar $permissionRegistrar,
        private ClinicMembershipService $membership,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $clinicId = $user instanceof User ? $this->resolveClinicId($request, $user) : null;

        $this->clinicContext->set($clinicId);
        $this->permissionRegistrar->setPermissionsTeamId($clinicId);

        return $next($request);
    }

    private function resolveClinicId(Request $request, User $user): ?int
    {
        $preferred = $request->hasSession()
            ? $request->session()->get('active_clinic_id')
            : null;
        $preferred = is_numeric($preferred) ? (int) $preferred : null;

        $clinicId = $this->membership->resolveActiveClinicId($user, $preferred);

        // A stale/invalid session id must never shadow a later legitimate switch.
        if ($request->hasSession() && $preferred !== null && $preferred !== $clinicId) {
            $request->session()->forget('active_clinic_id');
        }

        return $clinicId;
    }
}
