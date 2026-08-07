<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Core\Support\Toast;
use App\Modules\Identity\Http\Requests\UpdateRolePermissionsRequest;
use App\Modules\Identity\Services\RolePermissionService;
use Illuminate\Http\RedirectResponse;

class RolePermissionController extends Controller
{
    public function __construct(private RolePermissionService $service) {}

    public function update(UpdateRolePermissionsRequest $request): RedirectResponse
    {
        $this->authorize('update', Role::class);

        $this->service->syncForActiveClinic($request->user(), $request->validated('roles'));

        Toast::success(__('messages.role.permissions_updated'));

        return to_route('settings.roles.index');
    }
}
