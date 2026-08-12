<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Core\Support\Toast;
use App\Modules\Identity\Http\Requests\RenameRoleRequest;
use App\Modules\Identity\Http\Requests\StoreRoleRequest;
use App\Modules\Identity\Services\PermissionMatrixService;
use App\Modules\Identity\Services\RoleCustomizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(
        private PermissionMatrixService $service,
        private RoleCustomizationService $customization,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('settings/roles/Index', $this->service->matrixForActiveClinic($request->user()));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $this->customization->createCustomRole($request->validated('name'));

        Toast::success(__('messages.role.created'));

        return to_route('settings.roles.index');
    }

    public function update(RenameRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('rename', $role);

        $this->customization->renameCustomRole($role, $request->validated('name'));

        Toast::success(__('messages.role.renamed'));

        return to_route('settings.roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->customization->deleteCustomRole($request->user(), $role);

        Toast::success(__('messages.role.deleted'));

        return to_route('settings.roles.index');
    }

    public function revert(Request $request): RedirectResponse
    {
        $this->authorize('update', Role::class);

        $names = $this->customization->revertAllForActiveClinic($request->user());

        if ($names !== []) {
            Toast::success(__('messages.role.reverted'));
        } else {
            Toast::info(__('messages.role.no_customizations'));
        }

        return to_route('settings.roles.index');
    }
}
