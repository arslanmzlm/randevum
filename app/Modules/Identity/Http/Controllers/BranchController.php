<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Vertical;
use App\Modules\Core\Support\Toast;
use App\Modules\Identity\Http\Requests\StoreBranchRequest;
use App\Modules\Identity\Services\BranchProvisioningService;
use App\Support\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function __construct(
        private ClinicContext $clinicContext,
        private BranchProvisioningService $provisioning,
    ) {}

    public function index(): Response
    {
        $this->authorize('create', Clinic::class);

        $activeClinic = $this->clinicContext->clinicOrFail();

        // Clinic carries no ClinicScope of its own (it is not clinic-owned) —
        // a plain tenant_id filter already reads across the tenant's branches.
        $branches = Clinic::with('vertical')
            ->where('tenant_id', $activeClinic->tenant_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Clinic $clinic): array => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'vertical_name' => $clinic->vertical
                    ? __('verticals.'.$clinic->vertical->slug.'::vertical.name')
                    : null,
                'is_active' => $clinic->is_active,
                'is_current' => $clinic->id === $activeClinic->id,
                'created_at' => $clinic->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('settings/branches/Index', [
            'branches' => $branches,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Clinic::class);

        $activeClinic = $this->clinicContext->clinicOrFail();

        $verticals = Vertical::where('is_active', true)
            ->get()
            ->map(fn (Vertical $v): array => [
                'id' => $v->id,
                'slug' => $v->slug,
                'name' => __('verticals.'.$v->slug.'::vertical.name'),
            ])
            ->values()
            ->all();

        return Inertia::render('settings/branches/Create', [
            'verticals' => $verticals,
            'sourceClinic' => [
                'id' => $activeClinic->id,
                'name' => $activeClinic->name,
                'vertical_id' => $activeClinic->vertical_id,
            ],
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->authorize('create', Clinic::class);

        $activeClinic = $this->clinicContext->clinicOrFail();

        $this->provisioning->create($request->user(), $activeClinic, [
            'name' => $request->validated('name'),
            'vertical_id' => (int) $request->validated('vertical_id'),
            'copy_catalog' => (bool) $request->validated('copy_catalog', false),
        ]);

        Toast::success(__('branch.created'));

        return redirect()->route('settings.branches.index');
    }
}
