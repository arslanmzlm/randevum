<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Identity\Services\PermissionMatrixService;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private PermissionMatrixService $service) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('settings/roles/Index', $this->service->matrixForActiveClinic());
    }
}
