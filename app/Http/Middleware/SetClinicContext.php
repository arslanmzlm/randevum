<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's active clinic (from the authenticated user's clinic-scoped
 * Spatie Teams role) into ClinicContext and the Spatie permission team id.
 * Global-role users (superadmin / patient) carry no clinic → context stays null.
 */
class SetClinicContext
{
    public function __construct(
        private ClinicContext $clinicContext,
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $clinicId = $user instanceof User ? $this->resolveClinicId($user) : null;

        $this->clinicContext->set($clinicId);
        $this->permissionRegistrar->setPermissionsTeamId($clinicId);

        return $next($request);
    }

    /**
     * The clinic of the user's clinic-scoped role assignment (MVP: at most one).
     */
    private function resolveClinicId(User $user): ?int
    {
        $clinicId = DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereNotNull('clinic_id')
            ->value('clinic_id');

        return is_null($clinicId) ? null : (int) $clinicId;
    }
}
